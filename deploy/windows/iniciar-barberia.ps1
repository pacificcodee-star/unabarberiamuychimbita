# Enciende todo lo que necesita el sitio y el bot de WhatsApp en este PC, en orden:
#   1. MySQL de XAMPP (base de datos del sitio)
#   2. Laravel en http://localhost:8000 (php artisan serve)
#   3. Evolution API con su vigilante (se reinicia sola si se cae)
#   4. Túnel de Cloudflare (actualiza APP_URL y el webhook cada vez que cambia la dirección)
# Lo que ya esté encendido no se duplica. Pensado para ejecutarse al iniciar sesión en Windows
# (ver deploy\windows\LEEME.md).

param(
    [string]$Xampp = 'C:\xampp',
    [string]$Php = 'C:\xampp\php82\php.exe'
)

$projectDir = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$logDir = Join-Path $projectDir 'storage\logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$log = Join-Path $logDir 'iniciar-barberia.log'

function Write-Log([string]$msg) {
    $line = '{0:yyyy-MM-dd HH:mm:ss}  {1}' -f (Get-Date), $msg
    Add-Content -Path $log -Value $line -Encoding UTF8
    Write-Host $line
}

function Test-Port([int]$port) {
    return [bool](Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
}

function Wait-Port([int]$port, [int]$seconds) {
    for ($i = 0; $i -lt $seconds; $i++) {
        if (Test-Port $port) { return $true }
        Start-Sleep -Seconds 1
    }
    return $false
}

# 1. MySQL
if (Test-Port 3306) {
    Write-Log 'MySQL ya estaba encendido.'
} else {
    Start-Process -FilePath (Join-Path $Xampp 'mysql\bin\mysqld.exe') -ArgumentList '--defaults-file=bin\my.ini', '--standalone' `
        -WorkingDirectory (Join-Path $Xampp 'mysql') -WindowStyle Hidden
    if (Wait-Port 3306 60) { Write-Log 'MySQL encendido.' } else { Write-Log 'MySQL no arrancó en 60 s (revisa C:\xampp\mysql\data\mysql_error.log).' }
}

# 2. Laravel
if (Test-Port 8000) {
    Write-Log 'Laravel ya estaba en el puerto 8000.'
} else {
    Start-Process -FilePath $Php -ArgumentList 'artisan', 'serve', '--host=0.0.0.0', '--port=8000' `
        -WorkingDirectory $projectDir -WindowStyle Hidden
    if (Wait-Port 8000 30) { Write-Log 'Laravel encendido en http://localhost:8000.' } else { Write-Log 'Laravel no respondió en 30 s.' }
}

# 2b. Programador de tareas (cada minuto): recordatorios, fila automática de turnos,
#     cancelación por falta de confirmación, citas atendidas y mensajes de gracias.
$scheduler = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like '*schedule:work*' }
if ($scheduler) {
    Write-Log 'Programador de tareas ya estaba corriendo.'
} else {
    Start-Process -FilePath $Php -ArgumentList 'artisan', 'schedule:work' -WorkingDirectory $projectDir -WindowStyle Hidden
    Write-Log 'Programador de tareas iniciado (schedule:work).'
}

# 3 y 4. Evolution (vigilante) y túnel: cada uno corre en su propia ventana oculta y se queda vigilando.
$scripts = @(
    @{ Name = 'evolution-vigilante.ps1'; Label = 'Vigilante de Evolution' },
    @{ Name = 'tunel-cloudflare.ps1'; Label = 'Túnel público' }
)
foreach ($s in $scripts) {
    $path = Join-Path $PSScriptRoot $s.Name
    $running = Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like "*$($s.Name)*" }
    if ($running) {
        Write-Log "$($s.Label) ya estaba corriendo."
        continue
    }
    Start-Process -FilePath 'powershell.exe' -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', "`"$path`"" -WindowStyle Hidden
    Write-Log "$($s.Label) iniciado."
    if ($s.Name -eq 'evolution-vigilante.ps1') { [void](Wait-Port 8080 60) }
}

Write-Log 'Listo. Registros en storage\logs (evolution-vigilante.log, tunel-cloudflare.log).'
