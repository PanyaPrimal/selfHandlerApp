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

The harness selects Windows PowerShell on Windows and `pwsh` on Linux. The current Linux contract is
92 discovered tests with 88 executed and exactly four intentional Windows PowerShell 5.1 skips:
native stdin redirection, Windows ACL rejection, atomic state ACL protection, and protected lock-file
serialization. A release still requires the full 92/92 suite with zero skips under Windows PowerShell
5.1 in both public hosted Windows CI and the private exact-revision qualification gate. Any additional
Linux skip or any Windows skip is a contract failure.

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
