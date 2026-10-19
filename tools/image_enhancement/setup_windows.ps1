# Run from PowerShell. This script stops at the first failed native command.
[CmdletBinding()]
param(
    [string]$Xampp = 'C:\xampp',
    [string]$AiHome = 'C:\lufly-ai'
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Invoke-Checked {
    param([string]$Executable, [string[]]$Arguments)
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed with exit code ${LASTEXITCODE}: $Executable"
    }
}

$Xampp = [IO.Path]::GetFullPath($Xampp)
$AiHome = [IO.Path]::GetFullPath($AiHome)
$Project = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$Php = Join-Path $Xampp 'php\php.exe'
$Python = Join-Path $AiHome 'venv\Scripts\python.exe'
$Model = Join-Path $AiHome 'models\EDSR_x4.pb'
$Backup = Join-Path $AiHome 'backups'

if (!(Test-Path -LiteralPath $Php -PathType Leaf)) { throw "PHP not found: $Php" }
# Keep the environment, model and private configuration backups outside htdocs.
$WebRoot = [IO.Path]::GetFullPath((Join-Path $Xampp 'htdocs')).TrimEnd('\') + '\'
$HomePath = [IO.Path]::GetFullPath($AiHome).TrimEnd('\') + '\'
if ($HomePath.StartsWith($WebRoot, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'AiHome must be outside the XAMPP web root.'
}
New-Item -ItemType Directory -Path $AiHome -Force | Out-Null
if (!(Test-Path -LiteralPath $Python -PathType Leaf)) {
    $BasePython = Join-Path $env:LOCALAPPDATA 'Programs\Python\Python312\python.exe'
    if (!(Test-Path -LiteralPath $BasePython -PathType Leaf)) {
        Invoke-Checked -Executable 'winget' -Arguments @('install','--exact','--id','Python.Python.3.12','--scope','user','--silent','--accept-package-agreements','--accept-source-agreements')
    }
    if (!(Test-Path -LiteralPath $BasePython -PathType Leaf)) { throw "Python not found: $BasePython" }
    Invoke-Checked -Executable $BasePython -Arguments @('-m','venv',(Join-Path $AiHome 'venv'))
}
Invoke-Checked -Executable $Python -Arguments @('-m','pip','install','-r',(Join-Path $PSScriptRoot 'requirements.txt'))
Write-Host 'Downloading/verifying the pinned EDSR x4 model (about 39 MB)...'
Invoke-Checked -Executable $Python -Arguments @((Join-Path $PSScriptRoot 'download_model.py'),'--output',$Model)

# A small real inference smoke test; a full catalog photo may take several minutes.
$Sample = Join-Path $AiHome ('test-edsr-x4-' + [guid]::NewGuid().ToString() + '.png')
Invoke-Checked -Executable $Python -Arguments @((Join-Path $PSScriptRoot 'smoke_test.py'),'--output',$Sample,'--model-path',$Model)
if (!(Test-Path -LiteralPath $Sample -PathType Leaf)) { throw 'AI did not create a sample image.' }
Invoke-Checked -Executable $Php -Arguments @((Join-Path $PSScriptRoot 'setup_config.php'),$Project,$Python,$Model,$Backup)
Write-Host 'SUCCESS: EDSR x4 inference passed and configuration saved.' -ForegroundColor Green
Write-Host "Sample: $Sample"
Write-Host "Private configuration backups: $Backup"
Write-Host 'Refresh the website and select High Resolution x4 - EDSR. Keep the original until you approve.'
