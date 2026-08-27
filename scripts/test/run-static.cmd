@echo off
setlocal
cd /d "%~dp0\..\.."
if defined PHP (set "PHPBIN=%PHP%") else (set "PHPBIN=php")
echo [INFO] Repo root: %CD%
echo [INFO] PHP: %PHPBIN%
"%PHPBIN%" -v || exit /b 1
for /R src %%F in (*.php) do @"%PHPBIN%" -l "%%F" >nul || (echo [FAIL] PHP syntax: %%F & exit /b 1)
for /R scripts %%F in (*.php) do @"%PHPBIN%" -l "%%F" >nul || (echo [FAIL] PHP syntax: %%F & exit /b 1)
for /R tests %%F in (*.php) do @"%PHPBIN%" -l "%%F" >nul || (echo [FAIL] PHP syntax: %%F & exit /b 1)
echo [PASS] PHP syntax.
"%PHPBIN%" tests\security\security-regression.php || exit /b 1
"%PHPBIN%" tests\security\security-regression-phase02.php || exit /b 1
"%PHPBIN%" tests\security\security-regression-local-xampp.php || exit /b 1
"%PHPBIN%" tests\security\security-regression-easy-setup.php || exit /b 1
"%PHPBIN%" tests\security\security-regression-ui-foundation.php || exit /b 1
"%PHPBIN%" tests\security\security-regression-ui-shell.php || exit /b 1
"%PHPBIN%" tests\security\security-regression-ui-shell-visual.php || exit /b 1
"%PHPBIN%" tests\security\security-regression-ui-components.php || exit /b 1
where node >nul 2>&1 && node --check src\app.js || echo [INFO] Node unavailable; JS syntax check skipped.
echo [PASS] VibRetail repository static checks complete.
