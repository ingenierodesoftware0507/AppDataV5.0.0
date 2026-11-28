# Actualiza el backend (appdatamachine) que corre bajo PM2 sin dañarlo.
# Lo lanza Cactualizar::actualizarBackend (PHP) en segundo plano; el avance queda en -ArchivoEstado.
#
# Orden pensado para minimizar el tiempo caído y poder volver atrás:
#   1. Clona la versión nueva en una carpeta temporal (la app sigue corriendo).
#   2. Si cambiaron las dependencias, hace `npm ci` AQUÍ, en la carpeta temporal (la app sigue corriendo).
#   3. Copia de seguridad de la carpeta actual.
#   4. pm2 stop -> copia la versión nueva conservando .env y logs -> pm2 restart.
#   5. Verifica que quede online; si no, restaura la copia de seguridad y reinicia la versión anterior.
param(
    [Parameter(Mandatory = $true)][string]$RepoUrl,
    [Parameter(Mandatory = $true)][string]$Destino,
    [Parameter(Mandatory = $true)][string]$NombrePm2,
    [Parameter(Mandatory = $true)][string]$Trabajo,
    [Parameter(Mandatory = $true)][string]$ArchivoEstado
)

$ErrorActionPreference = 'Continue'
$inicio = (Get-Date).ToString('s')
$sinBom = New-Object System.Text.UTF8Encoding($false)

function Set-Estado([string]$estado, [string]$paso, [string]$mensaje, [string]$version = '') {
    $obj = [ordered]@{ estado = $estado; paso = $paso; mensaje = $mensaje; version = $version; inicio = $inicio; actualizado = (Get-Date).ToString('s') }
    [IO.File]::WriteAllText($ArchivoEstado, ($obj | ConvertTo-Json), $sinBom)
}

function Invoke-Nativo([string]$exe, [string[]]$argumentos, [string]$cwd = $null) {
    if ($cwd) { Push-Location $cwd }
    try {
        $salida = & $exe @argumentos 2>&1 | Out-String
        $codigo = $LASTEXITCODE
    } finally {
        if ($cwd) { Pop-Location }
    }
    if ($codigo -ne 0) { throw "$exe $($argumentos -join ' ') fallo (codigo $codigo): $salida" }
    return $salida
}

function Leer-Version([string]$carpeta) {
    try { return (Get-Content (Join-Path $carpeta 'package.json') -Raw | ConvertFrom-Json).version } catch { return '' }
}

function Hash-Archivo([string]$ruta) {
    if (Test-Path $ruta) { return (Get-FileHash $ruta -Algorithm SHA256).Hash }
    return ''
}

function Copiar-Carpeta([string]$origen, [string]$destino, [string[]]$excluirDirs, [string[]]$excluirArchivos) {
    # /MIR deja el destino igual al origen (borra archivos viejos); lo excluido (/XD /XF) ni se copia ni se borra.
    $args = @($origen, $destino, '/MIR', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:2', '/W:2')
    if ($excluirDirs.Count -gt 0) { $args += '/XD'; $args += $excluirDirs }
    if ($excluirArchivos.Count -gt 0) { $args += '/XF'; $args += $excluirArchivos }
    & robocopy @args | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy fallo (codigo $LASTEXITCODE) copiando $origen -> $destino" }
}

function Esperar-Online([int]$segundos) {
    # El primer arranque puede tardar ~20 s (antivirus); se exige que siga online tras una segunda lectura.
    $limite = (Get-Date).AddSeconds($segundos)
    while ((Get-Date) -lt $limite) {
        Start-Sleep -Seconds 4
        try {
            $lista = (& pm2 jlist 2>$null | Out-String)
            $json = $lista.Substring($lista.IndexOf('[')) | ConvertFrom-Json
            $app = $json | Where-Object { $_.name -eq $NombrePm2 } | Select-Object -First 1
            if ($app -and $app.pm2_env.status -eq 'online') {
                Start-Sleep -Seconds 6
                $lista2 = (& pm2 jlist 2>$null | Out-String)
                $json2 = $lista2.Substring($lista2.IndexOf('[')) | ConvertFrom-Json
                $app2 = $json2 | Where-Object { $_.name -eq $NombrePm2 } | Select-Object -First 1
                if ($app2 -and $app2.pm2_env.status -eq 'online' -and $app2.pm2_env.restart_time -eq $app.pm2_env.restart_time) { return $true }
            }
        } catch { }
    }
    return $false
}

$staging = Join-Path $Trabajo 'staging'
$respaldo = Join-Path $Trabajo 'respaldo'
$detuvo = $false
$copio = $false
$versionAnterior = Leer-Version $Destino

try {
    if (-not (Test-Path (Join-Path $Destino 'main.js'))) { throw "No se encontro main.js en $Destino. Verifique BACKEND_PATH." }
    New-Item -ItemType Directory -Force -Path $Trabajo | Out-Null

    # 1. Clonar la version nueva
    Set-Estado 'en_proceso' 'descargando' 'Descargando la nueva version del backend...'
    if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
    $intento = 1
    while ($true) {
        try { Invoke-Nativo 'git' @('clone', '--depth', '1', $RepoUrl, $staging) | Out-Null; break }
        catch { if ($intento -ge 3) { throw }; $intento++; Start-Sleep -Seconds 3; if (Test-Path $staging) { Remove-Item $staging -Recurse -Force } }
    }
    if (-not (Test-Path (Join-Path $staging 'main.js'))) { throw 'El repositorio descargado no contiene main.js; no parece un paquete release.' }
    $versionNueva = Leer-Version $staging

    # 2. Dependencias: solo si cambiaron, y se instalan en staging mientras la app sigue corriendo
    $cambioDeps = ((Hash-Archivo (Join-Path $staging 'package-lock.json')) -ne (Hash-Archivo (Join-Path $Destino 'package-lock.json'))) -or
                  ((Hash-Archivo (Join-Path $staging 'package.json')) -ne (Hash-Archivo (Join-Path $Destino 'package.json'))) -or
                  (-not (Test-Path (Join-Path $Destino 'node_modules')))
    if ($cambioDeps) {
        Set-Estado 'en_proceso' 'dependencias' 'Instalando dependencias (puede tardar unos minutos)...' $versionNueva
        Invoke-Nativo 'npm.cmd' @('ci', '--omit=dev', '--no-audit', '--no-fund') $staging | Out-Null
    }

    # 3. Copia de seguridad (con node_modules solo si van a cambiar)
    Set-Estado 'en_proceso' 'respaldo' 'Creando copia de seguridad de la version actual...' $versionNueva
    if (Test-Path $respaldo) { Remove-Item $respaldo -Recurse -Force }
    $excRespaldo = @('logs'); if (-not $cambioDeps) { $excRespaldo += 'node_modules' }
    Copiar-Carpeta $Destino $respaldo $excRespaldo @()

    # 4. Cambio de version con la app detenida
    Set-Estado 'en_proceso' 'reemplazando' 'Deteniendo el servicio y reemplazando archivos...' $versionNueva
    Invoke-Nativo 'pm2' @('stop', $NombrePm2) | Out-Null
    $detuvo = $true
    $excNuevo = @('.git', 'logs'); if (-not $cambioDeps) { $excNuevo += 'node_modules' }
    $copio = $true
    Copiar-Carpeta $staging $Destino $excNuevo @('.env')

    # 5. Reiniciar y verificar
    Set-Estado 'en_proceso' 'reiniciando' 'Reiniciando el servicio y verificando...' $versionNueva
    # ecosystem.config.js puede haber cambiado: se vuelve a registrar para que PM2 lo tome.
    Invoke-Nativo 'pm2' @('delete', $NombrePm2) | Out-Null
    Invoke-Nativo 'pm2' @('start', (Join-Path $Destino 'ecosystem.config.js')) | Out-Null
    Invoke-Nativo 'pm2' @('save') | Out-Null
    if (-not (Esperar-Online 60)) { throw "El servicio $NombrePm2 no quedo online despues de actualizar." }

    Set-Estado 'ok' 'terminado' "Backend actualizado de $versionAnterior a $versionNueva." $versionNueva
} catch {
    $motivo = $_.Exception.Message
    if ($copio -or $detuvo) {
        # Volver a la version anterior
        try {
            Set-Estado 'en_proceso' 'restaurando' "Fallo la actualizacion ($motivo). Restaurando la version anterior..." $versionAnterior
            try { & pm2 stop $NombrePm2 2>&1 | Out-Null } catch { }
            if (Test-Path $respaldo) {
                $excRest = @('logs'); if (-not (Test-Path (Join-Path $respaldo 'node_modules'))) { $excRest += 'node_modules' }
                Copiar-Carpeta $respaldo $Destino $excRest @('.env')
            }
            & pm2 delete $NombrePm2 2>&1 | Out-Null
            Invoke-Nativo 'pm2' @('start', (Join-Path $Destino 'ecosystem.config.js')) | Out-Null
            Invoke-Nativo 'pm2' @('save') | Out-Null
            $ok = Esperar-Online 60
            $detalle = if ($ok) { 'Se restauro la version anterior y el servicio esta online.' } else { 'NO se pudo dejar el servicio online; revise pm2 logs.' }
            Set-Estado 'error' 'restaurado' "Fallo la actualizacion: $motivo. $detalle" $versionAnterior
        } catch {
            Set-Estado 'error' 'restauracion_fallida' "Fallo la actualizacion: $motivo. Ademas fallo la restauracion: $($_.Exception.Message). Copia de seguridad en $respaldo" $versionAnterior
        }
    } else {
        Set-Estado 'error' 'sin_cambios' "No se modifico nada: $motivo" $versionAnterior
    }
} finally {
    if (Test-Path $staging) { Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue }
}
