@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\update-hydroponex.ps1" %*
set "result=%ERRORLEVEL%"
if not "%result%"=="0" echo Atualizacao incompleta. Consulte a mensagem e os backups indicados.
pause
exit /b %result%
