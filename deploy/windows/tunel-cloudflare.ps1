# Abre el túnel rápido de Cloudflare hacia el sitio local y mantiene el proyecto al día con su dirección.
#
# Cada vez que el túnel arranca (o se reinicia porque se cayó), Cloudflare entrega una URL nueva
# https://xxxx.trycloudflare.com. Este script:
#   1. la escribe como APP_URL en el .env (los enlaces que manda el bot y los correos usan APP_URL),
#   2. limpia la caché de configuración de Laravel,
#   3. vuelve a registrar el webhook en Evolution API (con EVOLUTION_WEBHOOK_URL si existe,
#      o con APP_URL/webhook/evolution si no),
#   4. deja todo anotado en storage\logs\tunel-cloudflare.log.
#
# Uso manual:   powershell -ExecutionPolicy Bypass -File deploy\windows\tunel-cloudflare.ps1
# Al iniciar Windows: ver deploy\windows\LEEME.md

param(
    [string]$Cloudflared = (Join-Path $env:USERPROFILE 'cloudflared\cloudflared.exe'),
    [string]$LocalUrl = 'http://localhost:8000',
    [string]$Php = 'C:\xampp\php82\php.exe',
    [string]$EvolutionUrl = 'http://127.0.0.1:8080'
)

$ErrorActionPreference = 'Continue'
$projectDir = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$envFile = Join-Path $projectDir '.env'
$logDir = Join-Path $projectDir 'storage\logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$log = Join-Path $logDir 'tunel-cloudflare.log'
$cfLog = Join-Path $logDir 'cloudflared.log'

function Write-Log([string]$msg) {
    $line = '{0:yyyy-MM-dd HH:mm:ss}  {1}' -f (Get-Date), $msg
    Add-Content -Path $log -Value $line -Encoding UTF8
    Write-Host $line
}

function Get-EnvValue([string]$name) {
    $line = Get-Content $envFile | Where-Object { $_ -match "^$name=" } | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line -replace "^$name=", '').Trim('"', ' ')
}

# Reemplaza (o agrega) una variable del .env sin tocar el resto del archivo.
function Set-EnvValue([string]$name, [string]$value) {
    $text = [IO.File]::ReadAllText($envFile)
    $pattern = "(?m)^$([regex]::Escape($name))=[^\r\n]*"
    if ([regex]::IsMatch($text, $pattern)) {
        $text = [regex]::Replace($text, $pattern, "$name=$value")
    } else {
        $text = $text.TrimEnd("`r", "`n") + "`r`n$name=$value`r`n"
    }
    [IO.File]::WriteAllText($envFile, $text, (New-Object System.Text.UTF8Encoding($false)))
}

function Register-Webhook([string]$appUrl) {
    $apikey = Get-EnvValue 'EVOLUTION_APIKEY'
    $instance = Get-EnvValue 'EVOLUTION_INSTANCE'
    if (-not $instance) { $instance = 'barberia' }
    $hook = Get-EnvValue 'EVOLUTION_WEBHOOK_URL'
    if (-not $hook) { $hook = "$appUrl/webhook/evolution" }
    if (-not $apikey) { Write-Log 'Sin EVOLUTION_APIKEY: no registro el webhook.'; return }

    $body = @{ webhook = @{
        enabled = $true; url = $hook; byEvents = $false; base64 = $false
        # Igual que EvolutionClient::setWebhook: sin esta cabecera Laravel rechaza los eventos (401).
        headers = @{ apikey = $apikey }
        events = @('MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE', 'QRCODE_UPDATED', 'LOGOUT_INSTANCE', 'REMOVE_INSTANCE')
    } } | ConvertTo-Json -Depth 5

    try {
        Invoke-RestMethod -Method Post -Uri "$EvolutionUrl/webhook/set/$instance" -Headers @{ apikey = $apikey } `
            -ContentType 'application/json' -Body $body -TimeoutSec 15 | Out-Null
        Write-Log "Webhook de Evolution apuntando a $hook"
    } catch {
        Write-Log "No se pudo registrar el webhook (¿Evolution apagada o la instancia '$instance' aún no existe?): $($_.Exception.Message)"
    }
}

if (-not (Test-Path $envFile)) { Write-Log "No encuentro el .env en $envFile"; exit 1 }

$useNgrok = (Get-EnvValue 'TUNNEL_PROVIDER') -eq 'ngrok'
$tunnelConfig = Get-EnvValue 'CLOUDFLARE_TUNNEL_CONFIG'
$publicUrl = $null
$tunnelExecutable = $Cloudflared
$provider = 'Cloudflare'
$tunnelArgs = @('tunnel', '--no-autoupdate', '--logfile', "`"$cfLog`"")
if ($useNgrok) {
    $provider = 'ngrok'
    $tunnelExecutable = (Get-Command ngrok.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1).Source
    if (-not $tunnelExecutable) {
        $tunnelExecutable = Join-Path $env:USERPROFILE 'scoop\shims\ngrok.exe'
    }
    $cfLog = Join-Path $logDir 'ngrok.log'
    $tunnelArgs = @('http', $LocalUrl, '--inspect=false', '--log-format=json', '--log', "`"$cfLog`"")
} elseif ($tunnelConfig) {
    if (-not (Test-Path -LiteralPath $tunnelConfig -PathType Leaf)) {
        Write-Log "No encuentro la configuración del túnel permanente: $tunnelConfig"
        exit 1
    }
    $publicUrl = Get-EnvValue 'APP_URL'
    if ($publicUrl -notmatch '^https://[^/\s]+$' -or $publicUrl -match '\.trycloudflare\.com$') {
        Write-Log 'APP_URL debe contener la URL HTTPS fija del túnel permanente.'
        exit 1
    }
    $tunnelArgs += @('--config', "`"$tunnelConfig`"", 'run')
} else {
    $tunnelArgs += @('--url', $LocalUrl)
}
if (-not (Test-Path -LiteralPath $tunnelExecutable -PathType Leaf)) {
    Write-Log "No encuentro el ejecutable de $provider en $tunnelExecutable."
    exit 1
}

$restarts = 0
while ($true) {
    if (Test-Path $cfLog) { Remove-Item $cfLog -Force -ErrorAction SilentlyContinue }
    $proc = Start-Process -FilePath $tunnelExecutable -ArgumentList $tunnelArgs -WindowStyle Hidden -PassThru -ErrorAction Stop
    $startedAt = Get-Date
    Write-Log "Túnel de $provider iniciado (PID $($proc.Id)) hacia $LocalUrl."

    # Cloudflare permanente usa APP_URL. Los demás anuncian su URL en el registro.
    $url = $publicUrl
    for ($i = 0; $i -lt 90 -and -not $url -and -not $proc.HasExited; $i++) {
        Start-Sleep -Seconds 1
        if (Test-Path $cfLog) {
            if ($useNgrok) {
                $entry = Get-Content -LiteralPath $cfLog | ForEach-Object { $_ | ConvertFrom-Json -ErrorAction SilentlyContinue } |
                    Where-Object { $_.msg -eq 'started tunnel' -and $_.url -match '^https://[a-z0-9-]+\.ngrok-free\.(dev|app)$' } |
                    Select-Object -First 1
                if ($entry) { $url = $entry.url }
            } else {
                $m = Select-String -Path $cfLog -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches -ErrorAction SilentlyContinue | Select-Object -First 1
                if ($m) { $url = $m.Matches[0].Value }
            }
        }
    }

    if ($url) {
        $current = Get-EnvValue 'APP_URL'
        if ($current -ne $url) {
            Set-EnvValue 'APP_URL' $url
            & $Php (Join-Path $projectDir 'artisan') config:clear | Out-Null
            Write-Log "APP_URL actualizado: $current -> $url"
        } else {
            Write-Log "La dirección no cambió: $url"
        }
        # Se registra siempre: si Evolution se reinició, también queda bien.
        Register-Webhook $url
    } else {
        Write-Log "$provider no entregó una dirección. Revisa $cfLog."
        try { Stop-Process -Id $proc.Id -Force -ErrorAction Stop } catch { }
    }

    while (-not $proc.HasExited) { Start-Sleep -Seconds 10 }
    if (((Get-Date) - $startedAt).TotalSeconds -ge 60) { $restarts = 0 }
    $restarts++
    $wait = [Math]::Min(60, 5 * $restarts)
    Write-Log "El túnel se cerró (código $($proc.ExitCode)). Lo reabro en $wait s."
    Start-Sleep -Seconds $wait
}
