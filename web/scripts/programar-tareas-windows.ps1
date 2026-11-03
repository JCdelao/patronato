# Registra en el Programador de tareas de Windows la ejecución de schedule:run cada minuto.
# Laravel decide qué tareas tocan, como el respaldo diario de las 2:00 a. m.
# Uso: powershell -ExecutionPolicy Bypass -File scripts\programar-tareas-windows.ps1

param(
    [string]$Php = (Get-Command php -ErrorAction Stop).Source
)

$web = Split-Path -Parent $PSScriptRoot
$nombre = 'Patronato - tareas programadas'

$accion = New-ScheduledTaskAction -Execute $Php -Argument 'artisan schedule:run' -WorkingDirectory $web
$disparador = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 1)
$opciones = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1)

Register-ScheduledTask -TaskName $nombre -Action $accion -Trigger $disparador -Settings $opciones `
    -Description 'Ejecuta las tareas programadas del Patronato, incluido el respaldo diario' -Force | Out-Null

Write-Host "Tarea '$nombre' registrada. Revisala con: Get-ScheduledTask -TaskName '$nombre'"
