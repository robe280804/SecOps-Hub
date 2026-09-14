import unittest
import asyncio
import json
import os
import tempfile
from pathlib import Path
from unittest.mock import AsyncMock, patch
from aiohttp import web, WSMsgType
from aiohttp.test_utils import TestClient, TestServer
from gateway import Gateway, valid_origin, validate_runtime


class GatewayTests(unittest.TestCase):
    def test_origin_must_match_scheme_host_and_port(self):
        self.assertTrue(valid_origin("https://app.example/path", "https://app.example"))
        for origin in ("https://app.example.evil", "http://app.example", "null", "",
                       "https://app.example:999", "https://app.example@evil.example"):
            self.assertFalse(valid_origin(origin, "https://app.example"))

    def test_runtime_must_be_running_and_match_all_ownership_labels(self):
        labels = {"secops.namespace": "test", "secops.project": "1",
                  "secops.environment": "2", "secops.generation": "3"}
        data = {"container": "a" * 64, "labels": labels}
        runtime = {"Id": "a" * 64, "State": {"Running": True}, "Config": {"Labels": dict(labels)}}
        self.assertTrue(validate_runtime(data, runtime))
        runtime["Config"]["Labels"]["secops.environment"] = "99"
        self.assertFalse(validate_runtime(data, runtime))
        runtime["Config"]["Labels"] = dict(labels)
        runtime["State"]["Running"] = False
        self.assertFalse(validate_runtime(data, runtime))
        self.assertFalse(validate_runtime({"container": "--privileged", "labels": {}}, runtime))


class TerminalIntegrationTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.directory = tempfile.TemporaryDirectory()
        executable = Path(self.directory.name) / "docker"
        executable.write_text("#!/bin/sh\nexec /bin/sh\n")
        executable.chmod(0o700)
        self.environment = patch.dict(os.environ, {
            "TERMINAL_ORIGIN": "https://app.example", "TERMINAL_API_URL": "http://api.example",
            "PATH": self.directory.name + ":" + os.environ["PATH"],
        })
        self.environment.start()
        self.gateway = Gateway()
        self.gateway.authorize = AsyncMock(return_value={"container": "a" * 64, "labels": {}})
        app = web.Application()
        app.cleanup_ctx.append(self.gateway.lifecycle)
        app.router.add_get("/terminal/{ticket}/{path:.*}", self.gateway.handle)
        self.client = TestClient(TestServer(app))
        await self.client.start_server()
        self.path = "/terminal/" + "a" * 64 + "/"

    async def asyncTearDown(self):
        await self.client.close()
        self.environment.stop()
        self.directory.cleanup()

    async def test_http_and_binary_websocket_roundtrip_and_revocation(self):
        response = await self.client.get(self.path, headers={"Referer": "https://app.example/projects"})
        self.assertEqual(response.status, 200)
        self.assertIn("text/html", response.headers["Content-Type"])
        self.assertEqual(response.headers["Cache-Control"], "no-store, private")
        token = await self.client.get(self.path + "token", headers={"Referer": "https://app.example/projects"})
        self.assertEqual(token.status, 200)
        async with self.client.ws_connect(self.path + "ws", protocols=("tty",),
                                         headers={"Origin": "https://app.example"}) as socket:
            await socket.send_bytes(json.dumps({"AuthToken": "", "columns": 80, "rows": 24}).encode())
            await socket.send_bytes(b"0printf 'gateway-%s\\n' works\r")
            output = b""
            async with asyncio.timeout(5):
                while b"gateway-works" not in output:
                    message = await socket.receive()
                    self.assertEqual(message.type, WSMsgType.BINARY)
                    if message.data[:1] == b"0":
                        output += message.data[1:]
            await socket.send_bytes(b'1{"columns":100,"rows":30}')
            self.gateway.authorize.side_effect = web.HTTPForbidden()
            ticket = "a" * 64
            await self.gateway.check_session(ticket, self.gateway.sessions[ticket])
            async with asyncio.timeout(5):
                while not socket.closed:
                    await socket.receive()
            self.assertNotIn(ticket, self.gateway.sessions)

    async def test_rejects_cross_origin_and_url_arguments_before_starting_a_process(self):
        for headers, suffix, status in [
            ({"Referer": "https://evil.example"}, "", 403),
            ({"Referer": "https://app.example"}, "?arg=sh", 400),
        ]:
            response = await self.client.get(self.path + suffix, headers=headers)
            self.assertEqual(response.status, status)
        self.gateway.authorize.assert_not_awaited()
        self.assertEqual(self.gateway.sessions, {})

    async def test_authorization_failure_does_not_create_a_terminal(self):
        self.gateway.authorize.side_effect = web.HTTPForbidden()
        response = await self.client.get(self.path, headers={"Referer": "https://app.example"})
        self.assertEqual(response.status, 403)
        self.assertEqual(self.gateway.sessions, {})


if __name__ == "__main__":
    unittest.main()
