@echo off
setlocal
cd /d "%~dp0\..\.."
if defined PHP (set "PHPBIN=%PHP%") else (set "PHPBIN=php")
echo [INFO] Cross-platform static runner: scripts\test\run-static.php
"%PHPBIN%" scripts\test\run-static.php
exit /b %ERRORLEVEL%
