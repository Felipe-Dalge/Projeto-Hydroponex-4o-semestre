@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\install-hydroponex.ps1" %*
set "result=%ERRORLEVEL%"
if not "%result%"=="0" echo Instalacao incompleta. Consulte a mensagem acima.
pause
exit /b %result%
