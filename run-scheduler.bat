@echo off
title SEO 80/20 Scheduler Runner
echo =======================================================
echo     SEO 80/20 SYSTEM - DAILY SCHEDULER EXECUTION
echo =======================================================
echo.

:: Detect PHP executable path
set PHP_BIN=php
if exist "C:\xampp\php\php.exe" (
    set PHP_BIN="C:\xampp\php\php.exe"
)

echo [INFO] Using PHP: %PHP_BIN%
echo [INFO] Running schedule script...
echo.

%PHP_BIN% -f "%~dp0auto-schedule.php"

echo.
echo =======================================================
echo     DAILY SCHEDULER EXECUTION COMPLETED
echo =======================================================
echo.
pause
