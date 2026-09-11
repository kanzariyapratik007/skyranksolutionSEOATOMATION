@echo off
title SkyRank Local PC Agent Engine
color 0A
echo ==================================================
echo   SkyRank Local PC Agent Engine
echo   Port: 8989 (127.0.0.1)
echo ==================================================

:: Find Python executable
set PY_EXE=python
where python >nul 2>nul
if %errorlevel% neq 0 (
    if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" (
        set PY_EXE="%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
    ) else if exist "%LOCALAPPDATA%\Programs\Python\Python311\python.exe" (
        set PY_EXE="%LOCALAPPDATA%\Programs\Python\Python311\python.exe"
    ) else if exist "%LOCALAPPDATA%\Programs\Python\Python310\python.exe" (
        set PY_EXE="%LOCALAPPDATA%\Programs\Python\Python310\python.exe"
    ) else if exist "%LOCALAPPDATA%\Programs\Python\Python314\python.exe" (
        set PY_EXE="%LOCALAPPDATA%\Programs\Python\Python314\python.exe"
    ) else if exist "C:\Python312\python.exe" (
        set PY_EXE="C:\Python312\python.exe"
    ) else if exist "C:\Python311\python.exe" (
        set PY_EXE="C:\Python311\python.exe"
    ) else (
        echo [ERROR] Python not found on your system!
        echo Please install Python from https://www.python.org/downloads/
        echo Make sure to check "Add python.exe to PATH" during installation.
        echo.
        pause
        exit /b 1
    )
)

echo [1/2] Checking required Python packages (selenium, requests, pillow)...
%PY_EXE% -m pip install -q selenium requests pillow >nul 2>nul

echo [2/2] Starting SkyRank Local Engine on 127.0.0.1:8989...
echo.
echo ================================================================
echo   Status: ONLINE 🟢
echo   Keep this black window OPEN while doing Auto-Posting.
echo   You can minimize it.
echo ================================================================
echo.

%PY_EXE% -u "%~dp0local_agent.py"
if %errorlevel% neq 0 (
    echo.
    echo Agent stopped unexpectedly.
    pause
)
