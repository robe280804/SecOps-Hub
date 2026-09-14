"""Build-time installation of the platform's globally selected tools."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import sys


APT_TOOLS = {
    name: name for name in """
    nmap masscan rustscan amass subfinder nuclei fierce dnsenum autorecon
    theharvester responder netexec enum4linux-ng gobuster feroxbuster dirsearch
    ffuf dirb katana nikto sqlmap wpscan arjun paramspider dalfox wafw00f
    hydra john hashcat medusa patator crackmapexec evil-winrm hash-identifier
    ophcrack gdb radare2 binwalk ghidra checksec foremost steghide trivy
    """.split()
}
APT_TOOLS.update({
    "httpx": "httpx-toolkit", "strings": "binutils", "objdump": "binutils",
    "exiftool": "libimage-exiftool-perl",
})
PYTHON_TOOLS = {
    "volatility3": ("volatility3", "vol"),
    "prowler": ("prowler", "prowler"),
    "scout-suite": ("ScoutSuite", "scout"),
    "kube-hunter": ("kube-hunter", "kube-hunter"),
}
SOURCE_TOOLS = {
    "kube-bench": "https://github.com/aquasecurity/kube-bench.git",
    "docker-bench-security": "https://github.com/docker/docker-bench-security.git",
}
ALL_TOOLS = set(APT_TOOLS) | set(PYTHON_TOOLS) | set(SOURCE_TOOLS)


def selected_tools(configuration):
    if not isinstance(configuration, dict):
        raise ValueError("Tool configuration must be an object of groups.")
    selections = {}
    for group, entries in configuration.items():
        if not isinstance(entries, dict):
            raise ValueError(f"Group {group} must contain tool booleans.")
        for name, enabled in entries.items():
            if name not in ALL_TOOLS:
                raise ValueError(f"Unknown tool: {name}")
            if name in selections:
                raise ValueError(f"Duplicate tool: {name}")
            if type(enabled) is not bool:
                raise ValueError(f"{name} must be true or false.")
            selections[name] = enabled
    # An omitted tool retains its requested default: enabled.
    return sorted(name for name in ALL_TOOLS if selections.get(name, True))


def apt_packages(selected):
    packages = {APT_TOOLS[name] for name in selected if name in APT_TOOLS}
    if "docker-bench-security" in selected:
        packages.add("docker-cli")
    return sorted(packages)


def run(*arguments, cwd=None):
    print("+", " ".join(arguments), flush=True)
    subprocess.run(arguments, cwd=cwd, check=True)


def wrapper(name, contents):
    destination = Path("/usr/local/bin") / name
    destination.write_text("#!/bin/sh\nset -eu\n" + contents + "\n")
    destination.chmod(0o755)


def install(configuration):
    selected = selected_tools(configuration)
    packages = apt_packages(selected)
    os.environ["DEBIAN_FRONTEND"] = "noninteractive"
    if packages:
        run("apt-get", "update")
        run("apt-get", "install", "-y", "--no-install-recommends", *packages)

    if any(name in PYTHON_TOOLS for name in selected):
        # Keep legacy cloud SDK dependencies separate from Kali's Python.
        run("uv", "python", "install", "3.11")
    for name in selected:
        if name in PYTHON_TOOLS:
            package, command = PYTHON_TOOLS[name]
            run("uv", "tool", "install", "--python", "3.11", package)
            if not shutil.which(command):
                raise RuntimeError(f"Missing executable after installing {name}: {command}")
            run(command, "--help")

    revisions = {}
    for name in selected:
        if name not in SOURCE_TOOLS:
            continue
        directory = Path("/opt") / name
        run("git", "clone", "--depth", "1", SOURCE_TOOLS[name], str(directory))
        revisions[name] = subprocess.check_output(
            ["git", "rev-parse", "HEAD"], cwd=directory, text=True,
        ).strip()
        if name == "kube-bench":
            run("go", "build", "-o", "/opt/kube-bench/kube-bench", ".", cwd=directory)
            wrapper(name, 'exec /opt/kube-bench/kube-bench --config-dir /opt/kube-bench/cfg "$@"')
            for helper in (directory / "helper_scripts").glob("*.sh"):
                shutil.copy2(helper, Path("/usr/local/bin") / helper.name)
            run(name, "--help")
        else:
            wrapper(name, 'cd /opt/docker-bench-security\nexec sh ./docker-bench-security.sh "$@"')
            # Syntax validation only: never audit the Docker build host.
            run("sh", "-n", str(directory / "docker-bench-security.sh"))

    if "httpx" in selected:
        wrapper("httpx", 'exec /usr/bin/httpx-toolkit "$@"')
    if "volatility3" in selected:
        wrapper("volatility3", 'exec /usr/local/bin/vol "$@"')
    if "scout-suite" in selected:
        wrapper("scout-suite", 'exec /usr/local/bin/scout "$@"')

    # Record actual installed versions, including transitive APT dependencies.
    destination = Path("/opt/secops-image")
    (destination / "installed-tools.json").write_text(json.dumps({
        "selected": selected, "source_revisions": revisions,
    }, indent=2) + "\n")
    (destination / "apt-packages.txt").write_text(subprocess.check_output(
        ["dpkg-query", "-W", "-f=${Package}\t${Version}\n"], text=True,
    ))
    if any(name in PYTHON_TOOLS for name in selected):
        (destination / "python-tools.txt").write_text(subprocess.check_output(
            ["uv", "tool", "list"], text=True,
        ))


if __name__ == "__main__":
    configuration = json.loads(Path(sys.argv[1]).read_text())
    if "--plan" in sys.argv[2:]:
        selected = selected_tools(configuration)
        print(json.dumps({"selected": selected, "apt_packages": apt_packages(selected)}, indent=2))
    else:
        install(configuration)
