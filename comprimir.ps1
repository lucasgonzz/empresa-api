# Script para comprimir todo menos .env, vendor, public y storage
# Guarda este archivo como 'comprimir_sin_vendor.ps1' y ejecutalo desde PowerShell

# Configuraciones
$excludes = @('vendor', 'public', 'storage', '.env', 'asd.zip')
$root = Get-Location

# Buscar todos los archivos y carpetas excepto los excluidos
$items = Get-ChildItem -Recurse -Force | Where-Object {
    $relativePath = $_.FullName.Substring($root.Path.Length + 1)
    foreach ($exclude in $excludes) {
        if ($_.PSIsContainer -and $_.Name -eq $exclude) { return $false }
        if ($relativePath -like "$exclude\*" -or $relativePath -eq $exclude) { return $false }
    }
    return $true
}

# Crear carpeta temporal
$tempFolder = "$env:TEMP\temp_compression_$(Get-Random)"
New-Item -ItemType Directory -Path $tempFolder | Out-Null

# Copiar los archivos manteniendo estructura
foreach ($item in $items) {
    $destination = Join-Path $tempFolder ($item.FullName.Substring($root.Path.Length + 1))
    if ($item.PSIsContainer) {
        New-Item -ItemType Directory -Force -Path $destination | Out-Null
    } else {
        $destFolder = Split-Path $destination
        if (!(Test-Path $destFolder)) {
            New-Item -ItemType Directory -Force -Path $destFolder | Out-Null
        }
        Copy-Item $item.FullName -Destination $destination -Force
    }
}

# Comprimir a zip
$zipPath = Join-Path $root "asd.zip"
Compress-Archive -Path "$tempFolder\*" -DestinationPath $zipPath -Force

# Borrar carpeta temporal
Remove-Item -Path $tempFolder -Recurse -Force

Write-Host "Archivo comprimido creado en: $zipPath"
