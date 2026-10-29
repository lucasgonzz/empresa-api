param([Parameter(Mandatory=$true)][string]$Salida)
$ErrorActionPreference = 'Stop'
$compilador = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path -LiteralPath $compilador)) { $compilador = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe' }
if (-not (Test-Path -LiteralPath $compilador)) { throw 'No se encontró el compilador de .NET Framework de Windows.' }
New-Item -ItemType Directory -Path $Salida -Force | Out-Null
$fuente = Join-Path $PSScriptRoot 'DiagnosticoConexion.cs'
$destino = Join-Path (Resolve-Path -LiteralPath $Salida).Path 'ComercioCity-Diagnostico.exe'
& $compilador /nologo /target:exe /platform:anycpu /optimize+ /utf8output "/out:$destino" /r:System.Windows.Forms.dll /r:System.Drawing.dll /r:System.Management.dll /r:System.Web.dll /r:System.Web.Extensions.dll /r:System.Core.dll $fuente
if ($LASTEXITCODE -ne 0) { throw 'La compilación falló.' }
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'LEEME.txt') -Destination (Join-Path $Salida 'LEEME.txt')
Get-FileHash -LiteralPath $destino -Algorithm SHA256 | Format-List
