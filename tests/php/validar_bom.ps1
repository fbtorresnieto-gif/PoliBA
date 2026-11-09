$ErrorActionPreference = 'Stop'
$repo = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$prioritarios = @(
    'web/includes/auth.php',
    'web/includes/header.php',
    'web/login.php'
)
$rutas = @()

foreach ($relativa in $prioritarios) {
    $ruta = Join-Path $repo $relativa
    if (Test-Path -LiteralPath $ruta) { $rutas += $ruta }
}

foreach ($directorio in @('web', 'tests', 'database')) {
    $base = Join-Path $repo $directorio
    if (Test-Path -LiteralPath $base) {
        $rutas += Get-ChildItem -LiteralPath $base -Recurse -File -Filter '*.php' | Select-Object -ExpandProperty FullName
    }
}

$conBom = @()
foreach ($ruta in $rutas | Sort-Object -Unique) {
    $flujo = [System.IO.File]::OpenRead($ruta)
    try {
        if ($flujo.Length -ge 3 -and $flujo.ReadByte() -eq 0xEF -and $flujo.ReadByte() -eq 0xBB -and $flujo.ReadByte() -eq 0xBF) {
            $conBom += [System.IO.Path]::GetRelativePath($repo, $ruta)
        }
    } finally {
        $flujo.Dispose()
    }
}

if ($conBom.Count -gt 0) {
    throw "PHP_BOM_DETECTADO: $($conBom -join ', ')"
}

"PHP_BOM_OK archivos=$((($rutas | Sort-Object -Unique).Count)) prioritarios=$($prioritarios.Count)"
