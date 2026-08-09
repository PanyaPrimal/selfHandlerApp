from __future__ import annotations

import os
import shutil
import subprocess
from collections.abc import Sequence
from pathlib import Path
from typing import Any


def _first_available(names: Sequence[str]) -> str | None:
    for name in names:
        executable = shutil.which(name)
        if executable is not None:
            return executable
    return None


# Production runs under Windows PowerShell 5.1. Prefer it on Windows so local and
# self-hosted validation exercises the production engine; GitHub-hosted Ubuntu
# runners expose PowerShell Core as `pwsh`.
POWERSHELL_EXECUTABLE = _first_available(
    ("powershell", "pwsh") if os.name == "nt" else ("pwsh", "powershell")
)
WINDOWS_POWERSHELL_EXECUTABLE = (
    _first_available(("powershell",)) if os.name == "nt" else None
)


def _is_windows_powershell_51() -> bool:
    if WINDOWS_POWERSHELL_EXECUTABLE is None:
        return False
    result = subprocess.run(
        [
            WINDOWS_POWERSHELL_EXECUTABLE,
            "-NoLogo",
            "-NoProfile",
            "-NonInteractive",
            "-Command",
            "$PSVersionTable.PSVersion.Major",
        ],
        capture_output=True,
        text=True,
    )
    return result.returncode == 0 and result.stdout.strip() == "5"


WINDOWS_POWERSHELL_51_AVAILABLE = _is_windows_powershell_51()


def run_powershell(command: str, **kwargs: Any) -> subprocess.CompletedProcess[str]:
    if POWERSHELL_EXECUTABLE is None:
        raise RuntimeError("PowerShell is required for deployment contract tests.")

    # The production scripts intentionally contain fixed C:\Homelab paths.
    # Pure-function fixtures on non-Windows runners need only a provider-backed
    # C: drive so those constants can be initialized while the scripts are
    # dot-sourced; no production filesystem operation is invoked there.
    compatibility_preamble = """
if ($PSVersionTable.PSEdition -eq 'Core' -and -not $IsWindows -and -not (Get-PSDrive -Name C -ErrorAction SilentlyContinue)) {
  New-PSDrive -Name C -PSProvider FileSystem -Root ([IO.Path]::GetTempPath()) | Out-Null
}
"""
    return subprocess.run(
        [
            POWERSHELL_EXECUTABLE,
            "-NoLogo",
            "-NoProfile",
            "-NonInteractive",
            "-Command",
            compatibility_preamble + command,
        ],
        **kwargs,
    )


def powershell_literal() -> str:
    if POWERSHELL_EXECUTABLE is None:
        raise RuntimeError("PowerShell is required for deployment contract tests.")
    return str(Path(POWERSHELL_EXECUTABLE)).replace("'", "''")
