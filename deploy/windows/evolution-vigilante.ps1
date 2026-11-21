# Mantiene Evolution API encendida en este PC (Windows, sin Docker).
#
# - Arranca `node dist/main` en la carpeta de Evolution y, si el proceso termina
#   (error, falta de memoria, corte de la base de datos), lo vuelve a levantar.
# - Cada 30 s pregunta a http://127.0.0.1:8080/ si responde. Si falla 4 veces seguidas
#   (2 minutos colgada), la reinicia.
# - Si el WhatsApp queda desconectado, le pide a Evolution que reconecte la instancia.
# - Escribe un registro con fecha en logs\evolution-vigilante.log de este proyecto.
#
# Uso manual:   powershell -ExecutionPolicy Bypass -File deploy\windows\evolution-vigilante.ps1
# Al iniciar Windows: ver deploy\windows\LEEME.md

param(
    [string]$EvolutionDir = (Join-Path $env:USERPROFILE 'Desktop\evolution-api-main'),
    [string]$HealthUrl = 'http://127.0.0.1:8080/',
    [int]$CheckSeconds = 30,
    [int]$MaxFailures = 4
)

$ErrorActionPreference = 'Continue'
$projectDir = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$logDir = Join-Path $projectDir 'storage\logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$log = Join-Path $logDir 'evolution-vigilante.log'
$nodeOut = Join-Path $logDir 'evolution-node.log'

function Write-Log([string]$msg) {
    $line = '{0:yyyy-MM-dd HH:mm:ss}  {1}' -f (Get-Date), $msg
    Add-Content -Path $log -Value $line -Encoding UTF8
    Write-Host $line
}

# Lee la API key y la instancia desde el .env de Laravel (para revisar la conexión de WhatsApp).
function Get-EnvValue([string]$name) {
    $envFile = Join-Path $projectDir '.env'
    if (-not (Test-Path $envFile)) { return $null }
    $line = Get-Content $envFile | Where-Object { $_ -match "^$name=" } | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line -replace "^$name=", '').Trim('"', ' ')
}

function Start-Evolution {
    if (-not (Test-Path (Join-Path $EvolutionDir 'dist\main.js'))) {
        Write-Log "No encuentro $EvolutionDir\dist\main.js. Compila Evolution primero (npm run build)."
        return $null
    }
    # Recorta el registro de Node si pasa de 20 MB.
    if ((Test-Path $nodeOut) -and ((Get-Item $nodeOut).Length -gt 20MB)) {
        Move-Item $nodeOut "$nodeOut.1" -Force
    }
    $p = Start-Process -FilePath 'node' -ArgumentList 'dist/main' -WorkingDirectory $EvolutionDir `
        -RedirectStandardOutput $nodeOut -RedirectStandardError "$nodeOut.err" -WindowStyle Hidden -PassThru
    Write-Log "Evolution iniciada (PID $($p.Id))."
    return $p
}

function Test-Health {
    try {
        $r = Invoke-WebRequest -Uri $HealthUrl -UseBasicParsing -TimeoutSec 10
        return $r.StatusCode -eq 200
    } catch { return $false }
}

# Si ya hay una Evolution escuchando en el puerto (arrancada a mano), se adopta en vez de duplicarla.
$port = ([uri]$HealthUrl).Port
$existing = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
$proc = $null
if ($existing) {
    $proc = Get-Process -Id $existing.OwningProcess -ErrorAction SilentlyContinue
    Write-Log "Evolution ya estaba corriendo (PID $($proc.Id)); la vigilo."
} else {
    $proc = Start-Evolution
}

$failures = 0
$restarts = 0
$apikey = Get-EnvValue 'EVOLUTION_APIKEY'
$instance = Get-EnvValue 'EVOLUTION_INSTANCE'
if (-not $instance) { $instance = 'barberia' }
$tick = 0

while ($true) {
    Start-Sleep -Seconds $CheckSeconds
    $tick++

    if (-not $proc -or $proc.HasExited) {
        $code = if ($proc) { $proc.ExitCode } else { 'sin proceso' }
        $restarts++
        $wait = [Math]::Min(60, 5 * $restarts)   # espera creciente para no reiniciar en bucle
        Write-Log "Evolution se detuvo (código $code). Reinicio #$restarts en $wait s."
        Start-Sleep -Seconds $wait
        $proc = Start-Evolution
        $failures = 0
        continue
    }

    if (Test-Health) {
        if ($failures -gt 0) { Write-Log 'Evolution vuelve a responder.' }
        $failures = 0
        $restarts = 0

        # Cada ~5 min: si el WhatsApp quedó desconectado, pedir reconexión (no borra la sesión).
        if ($apikey -and ($tick % 10 -eq 0)) {
            try {
                $s = Invoke-RestMethod -Uri ("{0}instance/connectionState/{1}" -f $HealthUrl, $instance) -Headers @{ apikey = $apikey } -TimeoutSec 10
                $state = $s.instance.state
                if ($state -and $state -ne 'open' -and $state -ne 'connecting') {
                    Write-Log "WhatsApp en estado '$state'. Pido reconexión."
                    Invoke-RestMethod -Uri ("{0}instance/connect/{1}" -f $HealthUrl, $instance) -Headers @{ apikey = $apikey } -TimeoutSec 15 | Out-Null
                }
            } catch { }
        }
        continue
    }

    $failures++
    Write-Log "Evolution no responde ($failures/$MaxFailures)."
    if ($failures -ge $MaxFailures) {
        Write-Log 'Evolution colgada: la reinicio.'
        try { Stop-Process -Id $proc.Id -Force -ErrorAction Stop } catch { }
        Start-Sleep -Seconds 3
        $proc = Start-Evolution
        $failures = 0
    }
}
