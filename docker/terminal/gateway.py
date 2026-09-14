"""Authenticated ttyd gateway. Only this trusted service has Docker access."""
import asyncio
import contextlib
import json
import os
import re
import signal
import ssl
import tempfile
import time
from urllib.parse import urlsplit

from aiohttp import ClientSession, ClientTimeout, DummyCookieJar, UnixConnector, WSMsgType, web


def valid_origin(value, expected):
    parsed = urlsplit(value)
    return bool(parsed.scheme and parsed.netloc) and f"{parsed.scheme}://{parsed.netloc}" == expected


def validate_runtime(data, container):
    expected = data.get("labels", {})
    return (
        re.fullmatch(r"[a-f0-9]{64}", data.get("container", "")) is not None
        and container.get("Id") == data["container"]
        and container.get("State", {}).get("Running") is True
        and set(expected) == {"secops.namespace", "secops.project", "secops.environment", "secops.generation"}
        and all(container.get("Config", {}).get("Labels", {}).get(key) == value for key, value in expected.items())
    )


async def command(*args):
    process = await asyncio.create_subprocess_exec(
        *args, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL,
    )
    try:
        output, _ = await asyncio.wait_for(process.communicate(), 5)
    except BaseException:
        if process.returncode is None:
            process.kill()
        await process.wait()
        raise
    if process.returncode:
        raise web.HTTPServiceUnavailable(text="The runtime is unavailable.")
    return output


async def relay(source, destination):
    async for message in source:
        if message.type == WSMsgType.BINARY:
            await destination.send_bytes(message.data)
        elif message.type == WSMsgType.TEXT:
            await destination.send_str(message.data)
        else:
            break


class Gateway:
    def __init__(self):
        self.origin = os.environ["TERMINAL_ORIGIN"].rstrip("/")
        self.api = os.environ["TERMINAL_API_URL"].rstrip("/") + "/api/v1/terminal/authorize"
        if not valid_origin(self.origin, self.origin) or urlsplit(self.origin).scheme not in ("http", "https"):
            raise ValueError("Invalid terminal origin")
        self.sessions = {}
        self.lock = asyncio.Lock()

    async def authorize(self, ticket, headers):
        async with self.client.get(self.api, headers={
            "Accept": "application/json", "Origin": self.origin, "Referer": self.origin + "/",
            "Cookie": headers.get("Cookie", ""),
            "Authorization": headers.get("Authorization", ""),
            "X-Terminal-Ticket": ticket,
        }, allow_redirects=False) as response:
            if response.status != 200:
                raise web.HTTPForbidden(text="Terminal access expired or was revoked. Open a new terminal.")
            data = (await response.json())["data"]
        if re.fullmatch(r"[a-f0-9]{64}", data.get("container", "")) is None:
            raise web.HTTPForbidden()
        runtime = json.loads(await command("docker", "inspect", "--type", "container", data["container"]))[0]
        if not validate_runtime(data, runtime):
            raise web.HTTPConflict(text="The environment is no longer running.")
        return data

    async def open_session(self, ticket, headers, data):
        async with self.lock:
            if ticket in self.sessions:
                session = self.sessions[ticket]
                if session["process"].returncode is not None or session["data"] != data:
                    raise web.HTTPConflict(text="Open a new terminal session.")
                return session
            if len(self.sessions) >= 32:
                raise web.HTTPServiceUnavailable(text="Terminal capacity reached. Try again later.")
            directory = tempfile.TemporaryDirectory(prefix="terminal-")
            socket = directory.name + "/tty.sock"
            process = await asyncio.create_subprocess_exec(
                "ttyd", "-i", socket, "-W", "-m", "1", "-b", "/terminal/" + ticket,
                "-t", "disableReconnect=true",
                "docker", "exec", "-it", "-e", "TERM=xterm-256color", "-w", "/workspace",
                data["container"], "tmux", "new-session", "-A", "-s", "secops", "-c", "/workspace",
                stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL,
                start_new_session=True,
            )
            session = {
                "process": process, "directory": directory, "headers": dict(headers),
                "data": data, "sockets": set(), "last_used": time.monotonic(),
                "client": ClientSession(connector=UnixConnector(path=socket),
                                        cookie_jar=DummyCookieJar(), timeout=ClientTimeout(total=5)),
            }
            self.sessions[ticket] = session
            for _ in range(100):
                if os.path.exists(socket):
                    return session
                if process.returncode is not None:
                    break
                await asyncio.sleep(0.02)
            await self.close_session(ticket)
            raise web.HTTPServiceUnavailable(text="Unable to start the terminal.")

    async def close_session(self, ticket):
        session = self.sessions.pop(ticket, None)
        if not session:
            return
        for socket in list(session["sockets"]):
            await socket.close(code=1008, message=b"Terminal session ended")
        await session["client"].close()
        process = session["process"]
        if process.returncode is None:
            with contextlib.suppress(ProcessLookupError):
                os.killpg(process.pid, signal.SIGTERM)
            try:
                await asyncio.wait_for(process.wait(), 3)
            except asyncio.TimeoutError:
                with contextlib.suppress(ProcessLookupError):
                    os.killpg(process.pid, signal.SIGKILL)
                await process.wait()
        session["directory"].cleanup()

    async def handle(self, request):
        ticket = request.match_info["ticket"]
        if not re.fullmatch(r"[A-Za-z0-9]{64}", ticket):
            raise web.HTTPNotFound()
        websocket = request.headers.get("Upgrade", "").lower() == "websocket"
        origin = request.headers.get("Origin") if websocket else request.headers.get("Origin", request.headers.get("Referer", ""))
        if not valid_origin(origin or "", self.origin):
            raise web.HTTPForbidden(text="Terminal requests must originate from the application.")
        if request.query_string:
            raise web.HTTPBadRequest(text="Terminal URL arguments are disabled.")
        try:
            data = await self.authorize(ticket, request.headers)
            session = await self.open_session(ticket, request.headers, data)
            session["last_used"] = time.monotonic()
            url = "http://localhost" + request.path
            if websocket:
                return await self.websocket(request, session, url)
            async with session["client"].get(url, allow_redirects=False) as response:
                return web.Response(body=await response.read(), status=response.status, headers={
                    "Content-Type": response.headers.get("Content-Type", "application/octet-stream"),
                    "Cache-Control": "no-store, private", "Referrer-Policy": "same-origin",
                    "X-Frame-Options": "SAMEORIGIN", "X-Content-Type-Options": "nosniff",
                    "Content-Security-Policy": "frame-ancestors 'self'",
                })
        except web.HTTPException:
            raise
        except Exception:
            raise web.HTTPServiceUnavailable(text="Terminal unavailable. Check the gateway and runtime.") from None

    async def websocket(self, request, session, url):
        async with session["client"].ws_connect(url, protocols=("tty",), max_msg_size=1048576) as upstream:
            downstream = web.WebSocketResponse(protocols=("tty",), heartbeat=20, max_msg_size=1048576)
            await downstream.prepare(request)
            session["sockets"].add(downstream)
            tasks = [asyncio.create_task(relay(downstream, upstream)), asyncio.create_task(relay(upstream, downstream))]
            try:
                await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
            finally:
                for task in tasks:
                    task.cancel()
                await asyncio.gather(*tasks, return_exceptions=True)
                session["sockets"].discard(downstream)
                session["last_used"] = time.monotonic()
                await downstream.close()
            return downstream

    async def check_session(self, ticket, session):
        try:
            if not session["sockets"] and time.monotonic() - session["last_used"] > 60:
                await self.close_session(ticket)
                return
            data = await self.authorize(ticket, session["headers"])
            if data != session["data"] or session["process"].returncode is not None:
                await self.close_session(ticket)
        except Exception:
            await self.close_session(ticket)

    async def monitor(self):
        while True:
            await asyncio.sleep(5)
            await asyncio.gather(*(self.check_session(ticket, session)
                                   for ticket, session in list(self.sessions.items())))

    async def lifecycle(self, app):
        from aiohttp import TCPConnector
        context = ssl.create_default_context()
        if os.environ.get("TERMINAL_CA_FILE"):
            context.load_verify_locations(os.environ["TERMINAL_CA_FILE"])
        self.client = ClientSession(connector=TCPConnector(ssl=context),
                                    cookie_jar=DummyCookieJar(), timeout=ClientTimeout(total=5))
        monitor = asyncio.create_task(self.monitor())
        yield
        monitor.cancel()
        with contextlib.suppress(asyncio.CancelledError):
            await monitor
        for ticket in list(self.sessions):
            await self.close_session(ticket)
        await self.client.close()


def application():
    gateway = Gateway()
    app = web.Application(client_max_size=1048576)
    app.cleanup_ctx.append(gateway.lifecycle)
    app.router.add_get("/terminal/{ticket}/{path:.*}", gateway.handle)
    return app


if __name__ == "__main__":
    web.run_app(application(), host="0.0.0.0", port=7681, access_log=None)
