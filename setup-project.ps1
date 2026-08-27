[CmdletBinding()]
param([switch]$DryRun)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path -LiteralPath $PSScriptRoot).Path
if (Test-Path -LiteralPath (Join-Path $Root '.git')) { throw 'Existing .git metadata detected. Run this initializer only before Git initialization.' }
$requiredDirs = @('project','docs','src','tests','scripts','assets')
$requiredFiles = @('README.md','AGENTS.md','PROJECT_STRUCTURE.md','vibproject.ygit','docs\docs.manifest.ygit','project\README.md','project\PROJECT_UPDATE_WORKFLOW.md')
foreach($d in $requiredDirs){if(-not(Test-Path (Join-Path $Root $d) -PathType Container)){throw "Missing directory: $d"}}
foreach($f in $requiredFiles){if(-not(Test-Path (Join-Path $Root $f) -PathType Leaf)){throw "Missing file: $f"}}
$ignore = Join-Path $Root '.gitignore'
$content = if(Test-Path $ignore){Get-Content $ignore -Raw}else{''}
if($content -notmatch '(?m)^/project/\*$'){
  if($DryRun){Write-Host '[DRY-RUN] Add /project/* to .gitignore'} else {Add-Content -LiteralPath $ignore -Value "`n# VibProject private internal workspace`n/project/*`n!/project/.gitkeep" -Encoding UTF8}
}
Write-Host '[PASS] VibRetail VibProject workspace is ready.' -ForegroundColor Green
Write-Host 'No Git commands were executed.'
