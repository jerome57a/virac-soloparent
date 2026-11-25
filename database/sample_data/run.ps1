# Adds the sample Solo Parents (database/sample_data/solo-parents.csv) through the system, start to finish:
#   accounts -> each Solo Parent applies on the web form -> MSWDO staff steps -> re-upload and renewal -> report.
# Usage (PowerShell, from the project folder; back up the database first):
#   .\database\sample_data\run.ps1 -Base http://localhost/virac-soloparent/
#   (add -TeamMobile 09XXXXXXXXX only when a row of the CSV says TEAM)
# You are asked for the demo password (typed, never shown or saved). -Database runs against another database (a copy).
param(
  [Parameter(Mandatory = $true)][string]$Base,
  [string]$TeamMobile = '',
  [string]$Database = '',
  [string]$Pictures = 'C:/dev/sample-data/pictures',
  [string]$PasswordFile = '',
  [string]$Only = ''   # e.g. R1,R2,R3: run only those rows of the CSV (the others are left as they are)
)
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..\..')
if ($PasswordFile -ne '') { $plain = [IO.File]::ReadAllText($PasswordFile) }
else {
  $secure = Read-Host 'Demo password for the sample Solo Parent accounts' -AsSecureString
  $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
}
$env:SAMPLE_PASSWORD = $plain
$env:SAMPLE_TEAM_MOBILE = $TeamMobile
$env:SAMPLE_BASE = $Base
if ($Only -ne '') { $env:SAMPLE_ONLY = $Only } else { Remove-Item Env:\SAMPLE_ONLY -ErrorAction SilentlyContinue }
$env:SAMPLE_PICTURES = $Pictures
$env:NODE_PATH = (Join-Path (Get-Location) 'scripts\node_modules')
if ($Database -ne '') { $env:DB_NAME = $Database }
try {
  function Step($title, [scriptblock]$run) { "=== $title"; & $run 2>&1 | ForEach-Object { "$_" }; if ($LASTEXITCODE -ne 0) { throw "$title failed (exit $LASTEXITCODE)" } }
  Step 'check' { php database/sample_data.php check }
  Step 'accounts' { php database/sample_data.php accounts }
  Step 'applications (web form)' { node scripts/sample-data/apply.cjs apply }
  Step 'staff steps' { php database/sample_data.php staff }
  Step 're-upload and renewal (web form)' { node scripts/sample-data/apply.cjs followup }
  Step 'staff steps for the renewals' { php database/sample_data.php staff }
  Step 'report' { php database/sample_data.php report }
} finally {
  Remove-Item Env:\SAMPLE_PASSWORD -ErrorAction SilentlyContinue
  $plain = $null
}
