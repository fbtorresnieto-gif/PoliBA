$ErrorActionPreference = 'Stop'

$repo = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$manifestPath = Join-Path $PSScriptRoot 'release-sha256.txt'

if (-not (Test-Path -LiteralPath $manifestPath)) {
    throw 'No existe release-sha256.txt.'
}

$checked = 0
foreach ($line in Get-Content -LiteralPath $manifestPath) {
    if ([string]::IsNullOrWhiteSpace($line) -or $line.StartsWith('#')) {
        continue
    }

    if ($line -notmatch '^([A-Fa-f0-9]{64})  (.+)$') {
        throw "Entrada inválida en el manifiesto: $line"
    }

    $expected = $Matches[1].ToUpperInvariant()
    $relative = $Matches[2].Replace('/', [IO.Path]::DirectorySeparatorChar)
    $fullPath = Join-Path $repo $relative
    if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) {
        throw "Falta el archivo del paquete: $relative"
    }

    $actual = (Get-FileHash -LiteralPath $fullPath -Algorithm SHA256).Hash.ToUpperInvariant()
    if ($actual -ne $expected) {
        throw "La huella no coincide: $relative"
    }
    $checked++
}

if ($checked -eq 0) {
    throw 'El manifiesto no contiene archivos.'
}

Write-Output "MULTIPRO_RELEASE_MANIFEST_OK files=$checked"
