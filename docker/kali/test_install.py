import unittest
from unittest.mock import patch

from install import ALL_TOOLS, apt_packages, install, selected_tools


class ToolSelectionTest(unittest.TestCase):
    def test_omitted_tools_are_enabled(self):
        self.assertEqual(set(selected_tools({})), ALL_TOOLS)

    def test_false_removes_only_the_requested_tool(self):
        selected = selected_tools({"network": {"nmap": False, "masscan": True}})
        self.assertNotIn("nmap", selected)
        self.assertIn("masscan", selected)
        self.assertEqual(len(selected), len(ALL_TOOLS) - 1)

    def test_all_tools_can_be_disabled(self):
        self.assertEqual(selected_tools({"all": dict.fromkeys(ALL_TOOLS, False)}), [])

    def test_shared_packages_remain_when_one_command_is_disabled(self):
        self.assertEqual(apt_packages(["objdump"]), ["binutils"])
        self.assertEqual(apt_packages(["strings", "objdump"]), ["binutils"])
        self.assertEqual(apt_packages(["httpx"]), ["httpx-toolkit"])

    def test_invalid_configuration_fails_before_installation(self):
        for invalid in [[], {"network": []}, {"x": {"nmp": True}},
                        {"x": {"nmap": "false"}}, {"x": {"nmap": 1}},
                        {"x": {"nmap": True}, "y": {"nmap": False}}]:
            with self.subTest(configuration=invalid), patch("install.run") as run:
                with self.assertRaises(ValueError):
                    install(invalid)
                run.assert_not_called()


if __name__ == "__main__":
    unittest.main()
