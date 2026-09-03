@echo off
REM Boots the Grounds for Concern stack: Go rule engine (:8081) + PHP app (:8080).
REM Resets the database to the seeded demo state first - deterministic 90-day
REM history, the built-in weekly budget rule (week tuned to $56.50, one latte
REM under the line), no alerts. Anything from a previous run is wiped.
REM Run from anywhere; finds its own paths. Close both spawned windows to stop.

setlocal
cd /d "%~dp0.."

REM Prefer php on PATH, fall back to XAMPP.
set PHP_CMD=php
where php >nul 2>nul || set PHP_CMD=C:\xampp\php\php.exe
if not exist "%PHP_CMD%" if not exist "%PHP_CMD%.exe" (
    echo Could not find PHP. Add it to PATH or edit this script.
    exit /b 1
)

if not exist vendor\ (
    echo Installing PHP dependencies ^(Twig, PHPUnit^)...
    "%PHP_CMD%" composer.phar install --no-interaction || exit /b 1
)

REM Reset to the canonical demo state before booting.
echo Resetting demo data ...
"%PHP_CMD%" scripts/migrate.php || exit /b 1
"%PHP_CMD%" scripts/seed.php || exit /b 1

echo Starting rule engine on http://127.0.0.1:8081 ...
start "grounds-engine" cmd /c "cd engine && go run .\cmd\grounds-api"

echo Starting app on http://127.0.0.1:8080 ...
start "grounds-app" cmd /k "%PHP_CMD%" -S 127.0.0.1:8080 -t public public/index.php

timeout /t 2 >nul
start http://127.0.0.1:8080/
echo.
echo Grounds for Concern is running.
echo App:    http://127.0.0.1:8080
echo Engine: http://127.0.0.1:8081/healthz
