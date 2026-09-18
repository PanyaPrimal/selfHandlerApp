$ErrorActionPreference='Stop'
if ($PSVersionTable.PSVersion.Major -ne 5 -or $PSVersionTable.PSEdition -ne 'Desktop') { throw 'Run this smoke with Windows PowerShell 5.1.' }
. (Join-Path $PSScriptRoot '..\scripts\shared.ps1')
$proofRoot=Join-Path $env:TEMP ('selfhandler-stdin-proof-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $proofRoot | Out-Null
$project='selfhandler-stdin-proof-'+[guid]::NewGuid().ToString('N').Substring(0,12)
$container=$project+'-db'
$volume=$project+'-mysql'
$image='mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb'
$envPath=Join-Path $proofRoot 'fixture.env'
[IO.File]::WriteAllText($envPath,"MYSQL_ROOT_PASSWORD=$([guid]::NewGuid().ToString('N'))`nMYSQL_DATABASE=fixture`n",[Text.UTF8Encoding]::new($false))
$sql=Join-Path $proofRoot 'fixture.sql'
[IO.File]::WriteAllText($sql,"CREATE TABLE sample(id INT PRIMARY KEY, body VARCHAR(100)); INSERT INTO sample VALUES (1, 'stdin with spaces'); CREATE TABLE users(id INT PRIMARY KEY); INSERT INTO users VALUES(1); CREATE TABLE migrations(migration VARCHAR(100), batch INT); INSERT INTO migrations VALUES('test_migration',1);`n",[Text.UTF8Encoding]::new($false))
$created=$false
$volumeCreated=$false
try {
 & docker volume create --label "selfhandler.validation-project=$project" $volume | Out-Null
 if ($LASTEXITCODE -ne 0) { throw 'Proof volume creation failed.' }
 $volumeCreated=$true
 & docker run -d --name $container --label "selfhandler.validation-project=$project" --network none --read-only --user 999:999 --cap-drop ALL --security-opt no-new-privileges:true --pids-limit 256 --memory 768m --cpus 0.75 --tmpfs '/run/mysqld:rw,nosuid,nodev,size=16m,uid=999,gid=999,mode=0750' --tmpfs '/tmp:rw,nosuid,nodev,size=64m,uid=999,gid=999,mode=1770' --env-file $envPath --mount "type=volume,source=$volume,target=/var/lib/mysql" $image | Out-Null
 if ($LASTEXITCODE -ne 0) { throw 'Proof database creation failed.' }
 $created=$true
 $probe=ConvertTo-EncodedPosixShellCommand -Script 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot -e "SELECT 1"'
 $ready=$false
 for($attempt=0;$attempt -lt 60;$attempt++) {
  if((Invoke-DockerQuietProbe -Argument @('exec',$container,'sh','-c',$probe)) -eq 0) { $ready=$true;break }
  Start-Sleep -Seconds 2
 }
 if(-not $ready) { throw 'Proof database readiness failed.' }
 $dockerPath=(Get-Command docker).Source
 $import=ConvertTo-EncodedPosixShellCommand -Script 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot "$MYSQL_DATABASE"'
 $code=Invoke-NativeProcessRedirected -FilePath $dockerPath -Arguments @('exec','-i',$container,'sh','-c',$import) -StandardInputPath $sql -StandardErrorPath (Join-Path $proofRoot 'import.error')
 if($code -ne 0) { throw 'Fixture SQL import failed.' }
 $dump=Join-Path $proofRoot 'snapshot.sql'
 $dumpCommand=ConvertTo-EncodedPosixShellCommand -Script 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysqldump --comments --single-transaction --routines --triggers --events -uroot "$MYSQL_DATABASE"'
 $code=Invoke-NativeProcessRedirected -FilePath $dockerPath -Arguments @('exec',$container,'sh','-c',$dumpCommand) -StandardOutputPath $dump -StandardErrorPath (Join-Path $proofRoot 'dump.error')
 if($code -ne 0 -or (Get-Item $dump).Length -lt 100) { throw 'Fixture logical dump failed.' }
 $clear=ConvertTo-EncodedPosixShellCommand -Script 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot "$MYSQL_DATABASE" -e "DROP TABLE sample"'
 & docker exec $container sh -c $clear
 if($LASTEXITCODE -ne 0) { throw 'Fixture table reset failed.' }
 $code=Invoke-NativeProcessRedirected -FilePath $dockerPath -Arguments @('exec','-i',$container,'sh','-c',$import) -StandardInputPath $dump -StandardErrorPath (Join-Path $proofRoot 'restore.error')
 if($code -ne 0) { throw 'Exact dump reimport failed.' }
 $count=ConvertTo-EncodedPosixShellCommand -Script 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM sample WHERE id=1 AND body=\"stdin with spaces\""'
 $value=[string](& docker exec $container sh -c $count)
 if($LASTEXITCODE -ne 0 -or $value.Trim() -ne '1') { throw 'Exact restored fixture row mismatch.' }
 # Load only the actual backup validation functions, never the production entry
 # point. Their generated validation project is isolated from the fixture too.
 $tokens=$null
 $parseErrors=$null
 $ast=[Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot '..\scripts\backup-production.ps1'),[ref]$tokens,[ref]$parseErrors)
 if($parseErrors.Count) { throw 'Backup script parsing failed.' }
 foreach($name in @('Wait-BackupDatabaseHealthy','New-BackupValidationSecret','Assert-BackupValidationResourceLabel','Get-DatabaseSnapshotEvidence')) {
  $definition=$ast.Find({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name},$false)
  if(-not $definition) { throw 'A required backup validation function is missing.' }
  Invoke-Expression $definition.Extent.Text
 }
 $script:SelfHandlerBackupMySqlImage=$image
 $evidence=Get-DatabaseSnapshotEvidence -DatabasePath $dump -WorkingRoot $proofRoot
 if($evidence.controlled_count -ne 1 -or $evidence.schema_fingerprint -ne (Get-Sha256Text -Text "test_migration`t1")) { throw 'Production snapshot validator returned incorrect fixture evidence.' }
 'Windows PowerShell 5.1 -> docker.exe -> encoded POSIX shell -> MySQL: import, dump, reimport, exact row and production snapshot validator PASSED.'
} finally {
 if($created) {
  if((Get-DockerResourceLabel -Type container -Name $container -Label 'selfhandler.validation-project') -ne $project) { throw 'Unsafe proof container cleanup.' }
  & docker rm --force $container | Out-Null
 }
 if($volumeCreated) {
  if((Get-DockerResourceLabel -Type volume -Name $volume -Label 'selfhandler.validation-project') -ne $project) { throw 'Unsafe proof volume cleanup.' }
  & docker volume rm $volume | Out-Null
 }
 $resolved=[IO.Path]::GetFullPath($proofRoot)
 if(-not $resolved.StartsWith(([IO.Path]::GetFullPath($env:TEMP).TrimEnd('\')+'\selfhandler-stdin-proof-'),[StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe proof filesystem cleanup.' }
 Remove-Item -LiteralPath $resolved -Recurse -Force
}
