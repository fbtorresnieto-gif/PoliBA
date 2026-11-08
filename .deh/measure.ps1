[CmdletBinding()]
param(
    [ValidateSet('Start', 'Close', 'List')]
    [string]$Action,

    [string]$Id,

    [string]$CsvPath = (Join-Path $PSScriptRoot 'tasks.csv')
)

$ErrorActionPreference = 'Stop'
$script:Columns = @(
    'Id', 'StartedAt', 'ClosedAt', 'Description', 'Complexity', 'Model',
    'ReasoningLevel', 'FiveHourBeforePct', 'FiveHourAfterPct',
    'FiveHourConsumedPct', 'WeeklyBeforePct', 'WeeklyAfterPct',
    'WeeklyConsumedPct', 'QuotaResetDetected', 'Comparable',
    'DurationMinutes', 'Retries', 'Tests', 'Result', 'Status'
)

function Initialize-TaskFile {
    $parent = Split-Path -Parent $CsvPath
    if (-not (Test-Path -LiteralPath $parent)) {
        New-Item -ItemType Directory -Path $parent | Out-Null
    }

    if (-not (Test-Path -LiteralPath $CsvPath)) {
        ($script:Columns -join ',') | Set-Content -LiteralPath $CsvPath -Encoding utf8
    }
}

function Read-RequiredText([string]$Prompt) {
    do {
        $value = (Read-Host $Prompt).Trim()
    } while ([string]::IsNullOrWhiteSpace($value))
    return $value
}

function Read-OptionalPercentage([string]$Prompt) {
    while ($true) {
        $raw = (Read-Host "$Prompt (0-100, Enter/N/D si no está disponible)").Trim()
        if ([string]::IsNullOrWhiteSpace($raw) -or $raw -match '^(N/?D|NA|N/?A)$') {
            return $null
        }

        $normalized = $raw.Replace(',', '.')
        $parsed = 0.0
        if ([double]::TryParse(
            $normalized,
            [Globalization.NumberStyles]::Number,
            [Globalization.CultureInfo]::InvariantCulture,
            [ref]$parsed
        ) -and $parsed -ge 0 -and $parsed -le 100) {
            return [Math]::Round($parsed, 2)
        }

        Write-Warning 'Ingresá un porcentaje entre 0 y 100, o N/D.'
    }
}

function Read-Retries {
    while ($true) {
        $raw = (Read-Host 'Cantidad de reintentos (Enter = 0)').Trim()
        if ([string]::IsNullOrWhiteSpace($raw)) { return 0 }
        $parsed = 0
        if ([int]::TryParse($raw, [ref]$parsed) -and $parsed -ge 0) {
            return $parsed
        }
        Write-Warning 'Ingresá un entero mayor o igual a cero.'
    }
}

function Format-OptionalNumber($Value) {
    if ($null -eq $Value) { return '' }
    return ([double]$Value).ToString('0.##', [Globalization.CultureInfo]::InvariantCulture)
}

function New-TaskId {
    $suffix = [Guid]::NewGuid().ToString('N').Substring(0, 6)
    return 'DEH-{0}-{1}' -f (Get-Date -Format 'yyyyMMdd-HHmmss'), $suffix
}

function Get-Tasks {
    Initialize-TaskFile
    $rows = @(Import-Csv -LiteralPath $CsvPath)
    return $rows
}

function Save-Tasks($Rows) {
    if (@($Rows).Count -eq 0) {
        ($script:Columns -join ',') | Set-Content -LiteralPath $CsvPath -Encoding utf8
        return
    }
    @($Rows) | Select-Object $script:Columns | Export-Csv -LiteralPath $CsvPath -NoTypeInformation -Encoding utf8
}

function Start-Task {
    Initialize-TaskFile
    $taskId = New-TaskId
    $description = Read-RequiredText 'Descripción breve'
    $complexity = Read-RequiredText 'Complejidad (baja/media/alta o criterio propio)'
    $model = Read-RequiredText 'Modelo utilizado'
    $reasoning = Read-RequiredText 'Nivel de razonamiento'
    $fiveHourBefore = Read-OptionalPercentage 'Disponible del límite de cinco horas antes'
    $weeklyBefore = Read-OptionalPercentage 'Disponible semanal antes'

    $row = [pscustomobject][ordered]@{
        Id                    = $taskId
        StartedAt             = (Get-Date).ToString('o')
        ClosedAt              = ''
        Description           = $description
        Complexity            = $complexity
        Model                 = $model
        ReasoningLevel        = $reasoning
        FiveHourBeforePct     = Format-OptionalNumber $fiveHourBefore
        FiveHourAfterPct      = ''
        FiveHourConsumedPct   = ''
        WeeklyBeforePct       = Format-OptionalNumber $weeklyBefore
        WeeklyAfterPct        = ''
        WeeklyConsumedPct     = ''
        QuotaResetDetected    = ''
        Comparable            = ''
        DurationMinutes       = ''
        Retries               = ''
        Tests                 = ''
        Result                = ''
        Status                = 'InProgress'
    }

    $row | Export-Csv -LiteralPath $CsvPath -NoTypeInformation -Append -Encoding utf8
    Write-Output "Tarea iniciada: $taskId"
}

function Convert-OptionalPercentage($Value) {
    if ([string]::IsNullOrWhiteSpace([string]$Value)) { return $null }
    return [double]::Parse([string]$Value, [Globalization.CultureInfo]::InvariantCulture)
}

function Close-Task {
    $tasks = @(Get-Tasks)
    $open = @($tasks | Where-Object { $_.Status -eq 'InProgress' })
    if ($open.Count -eq 0) { throw 'No hay tareas abiertas.' }

    if ([string]::IsNullOrWhiteSpace($Id)) {
        Write-Output 'Tareas abiertas:'
        $open | Select-Object Id, StartedAt, Description | Format-Table -AutoSize
        $selectedId = Read-RequiredText 'Identificador a cerrar'
    } else {
        $selectedId = $Id
    }

    $matches = @($open | Where-Object { $_.Id -eq $selectedId })
    if ($matches.Count -ne 1) { throw "No existe una única tarea abierta con ID $selectedId." }
    $task = $matches[0]

    $fiveHourAfter = Read-OptionalPercentage 'Disponible del límite de cinco horas después'
    $weeklyAfter = Read-OptionalPercentage 'Disponible semanal después'
    $retries = Read-Retries
    $tests = (Read-Host 'Pruebas ejecutadas (Enter/N/D si no corresponde)').Trim()
    if ([string]::IsNullOrWhiteSpace($tests)) { $tests = 'N/D' }
    $result = Read-RequiredText 'Resultado'

    $fiveHourBefore = Convert-OptionalPercentage $task.FiveHourBeforePct
    $weeklyBefore = Convert-OptionalPercentage $task.WeeklyBeforePct
    $resetDetected = (($null -ne $fiveHourBefore -and $null -ne $fiveHourAfter -and $fiveHourAfter -gt $fiveHourBefore) -or
        ($null -ne $weeklyBefore -and $null -ne $weeklyAfter -and $weeklyAfter -gt $weeklyBefore))
    $allAvailable = ($null -ne $fiveHourBefore -and $null -ne $fiveHourAfter -and
        $null -ne $weeklyBefore -and $null -ne $weeklyAfter)
    $comparable = $allAvailable -and -not $resetDetected

    $task.ClosedAt = (Get-Date).ToString('o')
    $task.FiveHourAfterPct = Format-OptionalNumber $fiveHourAfter
    $task.WeeklyAfterPct = Format-OptionalNumber $weeklyAfter
    $task.QuotaResetDetected = if ($resetDetected) { 'Yes' } else { 'No' }
    $task.Comparable = if ($comparable) { 'Yes' } else { 'No' }
    if ($comparable) {
        $task.FiveHourConsumedPct = Format-OptionalNumber ($fiveHourBefore - $fiveHourAfter)
        $task.WeeklyConsumedPct = Format-OptionalNumber ($weeklyBefore - $weeklyAfter)
    } else {
        $task.FiveHourConsumedPct = ''
        $task.WeeklyConsumedPct = ''
    }
    $started = [DateTimeOffset]::Parse($task.StartedAt, [Globalization.CultureInfo]::InvariantCulture)
    $closed = [DateTimeOffset]::Parse($task.ClosedAt, [Globalization.CultureInfo]::InvariantCulture)
    $task.DurationMinutes = ($closed - $started).TotalMinutes.ToString('0.##', [Globalization.CultureInfo]::InvariantCulture)
    $task.Retries = [string]$retries
    $task.Tests = $tests
    $task.Result = $result
    $task.Status = 'Completed'

    Save-Tasks $tasks
    if ($resetDetected) {
        Write-Output 'Tarea cerrada. Se detectó un restablecimiento de cuota; la medición quedó como no comparable.'
    } elseif (-not $allAvailable) {
        Write-Output 'Tarea cerrada. Faltan porcentajes; la medición quedó como no comparable.'
    } else {
        Write-Output 'Tarea cerrada con consumo comparable.'
    }
}

function Show-Tasks {
    $tasks = @(Get-Tasks)
    if ($tasks.Count -eq 0) {
        Write-Output 'No hay tareas registradas.'
        return
    }
    $tasks | Select-Object Id, StartedAt, Description, Status, Comparable, Result | Format-Table -AutoSize
}

if ([string]::IsNullOrWhiteSpace($Action)) {
    $Action = Read-RequiredText 'Acción (Start/Close/List)'
}

switch ($Action.ToLowerInvariant()) {
    'start' { Start-Task }
    'close' { Close-Task }
    'list'  { Show-Tasks }
    default { throw 'Acción inválida. Usá Start, Close o List.' }
}
