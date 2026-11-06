$ErrorActionPreference = 'Stop'
$destino = 'C:/Users/USER/Desktop/Daniel/Sistema_Balinsa_23_10'
$manifest = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'manifest.json') -Raw | ConvertFrom-Json
foreach ($item in $manifest) {
    $target = Join-Path $destino $item.path
    $actual = (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $item.before -and $actual -ne $item.after) { throw "El archivo cambió desde la revisión: $target" }
    $prepared = Join-Path (Join-Path $PSScriptRoot 'sistema') $item.path
    if ((Get-FileHash -LiteralPath $prepared -Algorithm SHA256).Hash.ToLowerInvariant() -ne $item.after) { throw "La entrega cambió: $prepared" }
}
foreach ($item in $manifest) {
    $target = Join-Path $destino $item.path
    Copy-Item -LiteralPath (Join-Path (Join-Path $PSScriptRoot 'sistema') $item.path) -Destination $target -Force
    if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant() -ne $item.after) { throw "Falló verificación: $target" }
    Write-Output "Actualizado: $($item.path)"
}
