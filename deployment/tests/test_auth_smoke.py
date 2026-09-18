from pathlib import Path
import unittest

from powershell_test_support import run_powershell


ROOT = Path(__file__).resolve().parents[2]


class AuthenticationSmokeTests(unittest.TestCase):
    def test_session_cookie_validation_handles_expires_without_accepting_weaker_cookies(self) -> None:
        source = (ROOT / "deployment/scripts/auth-smoke.ps1").as_posix().replace("'", "''")
        command = f"""
$ErrorActionPreference = 'Stop'
$tokens=$null
$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile('{source}',[ref]$tokens,[ref]$errors)
$definition=$ast.Find({{param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Test-ProductionSessionCookie'}},$false)
if(-not $definition -or $errors.Count) {{ throw 'Cookie validator is missing or invalid.' }}
Invoke-Expression $definition.Extent.Text
$cookie=New-Object Net.Cookie('selfhandler_session','fixture','/','example.test')
$cookie.Secure=$true
$cookie.HttpOnly=$true
$valid='selfhandler_session=fixture; expires=Fri, 18 Sep 2026 14:00:00 GMT; path=/; secure; httponly; samesite=lax'
$xsrf='XSRF-TOKEN=fixture; expires=Fri, 18 Sep 2026 14:00:00 GMT; path=/; secure; samesite=lax'
if(-not (Test-ProductionSessionCookie $cookie @($xsrf,$valid))) {{ throw 'Separate valid cookie headers rejected.' }}
if(-not (Test-ProductionSessionCookie $cookie @(($xsrf+', '+$valid)))) {{ throw 'Combined valid cookie headers rejected.' }}
foreach($sameSite in @('strict','none','')) {{
 $weak=$valid.Replace('samesite=lax',('samesite='+$sameSite))
 if(Test-ProductionSessionCookie $cookie @(($xsrf+', '+$weak))) {{ throw 'XSRF SameSite masked a weaker session cookie.' }}
}}
$cookie.Secure=$false
if(Test-ProductionSessionCookie $cookie @($valid)) {{ throw 'Insecure cookie accepted.' }}
$cookie.Secure=$true
$cookie.HttpOnly=$false
if(Test-ProductionSessionCookie $cookie @($valid)) {{ throw 'Script-readable session cookie accepted.' }}
$cookie.HttpOnly=$true
if(Test-ProductionSessionCookie $null @($valid)) {{ throw 'Missing cookie accepted.' }}
if(Test-ProductionSessionCookie $cookie @($xsrf)) {{ throw 'Missing session header accepted.' }}
if(Test-ProductionSessionCookie $cookie @($valid,$valid)) {{ throw 'Duplicate session headers accepted.' }}
"""
        result = run_powershell(command, cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
