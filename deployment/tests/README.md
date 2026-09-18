# Deployment test harness

The deployment contract tests use Python's standard `unittest` runner plus two validation-only
packages. Their transitive dependency set is fully resolved and hash-locked in `requirements.txt`.
They are not application runtime dependencies and must not be copied into either production image.

Install the reviewed test dependencies in an isolated environment:

```powershell
python -m venv .venv-deployment
.\.venv-deployment\Scripts\python -m pip install --disable-pip-version-check --require-hashes -r deployment\tests\requirements.txt
```

Run the fast contract suite from the repository root:

```powershell
.\.venv-deployment\Scripts\python -m unittest discover -s deployment/tests -p "test_*.py" -v
```

The harness selects Windows PowerShell on Windows and `pwsh` on Linux. The suite currently discovers
107 tests. Linux executes 102 and intentionally skips five Windows PowerShell 5.1 cases: native stdin
redirection, Windows ACL rejection, atomic state ACL protection, preserving an administrator-provisioned
root without WRITE_DAC, and protected lock-file serialization. Windows executes 106 and skips only the
POSIX image health dispatch case, which Linux runs. A release requires both platform gates so every
test executes on its applicable platform. Additional skips are a contract failure.

`jsonschema` validates the four Draft 2020-12 operational schemas, including RFC 3339 formats.
`PyYAML` is used only to inspect Compose and GitHub workflow structure. Docker-backed production,
rollback, and recovery smoke tests have separate entry points documented by the feature quickstart.

The workflow contracts also require public GitHub Actions to consist only of read-only CI, with the
deployment suite pinned to hosted Windows Server 2025 and Windows PowerShell 5.1; the sole production
qualification/publish path lives in the private operations template and independently repeats the
exact-SHA suite on a no-secret hosted Windows job before fresh qualification. They cover the
authenticated input-free dispatch, hosted/private privilege split, unique never-overwritten
qualification tags, protected prepared/pending/terminal crash resume, completion of one older pending
release after `master` advances, original run-attempt and signer preservation, two-phase release
finalization, GitHub Free private-repo constraints, ACL-protected installed operations, and the absence
of credentials during public bundle execution. Before copying the private template, additionally run
actionlint 1.7.12 over both workflow trees and parse every `shell: powershell` run block with the
Windows PowerShell 5.1 parser.
