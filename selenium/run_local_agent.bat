@echo off
setlocal enabledelayedexpansion
title SkyRank Local PC Agent (1-Click Auto Setup)
color 0A

echo ================================================================
echo   SkyRank Local PC Agent Engine
echo   Port: 8989 (127.0.0.1)
echo   Mode: 1-Click Fully Automated
echo ================================================================
echo.

:: Step 1: Detect working Python
set "PY_CMD="

:: Test python in PATH
python -c "import sys; print(sys.version)" >nul 2>nul
if %errorlevel% equ 0 set "PY_CMD=python"

:: Test py launcher
if not defined PY_CMD (
    py -c "import sys; print(sys.version)" >nul 2>nul
    if %errorlevel% equ 0 set "PY_CMD=py"
)

:: Test standard installation paths
if not defined PY_CMD (
    for %%P in (
        "%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
        "%LOCALAPPDATA%\Programs\Python\Python311\python.exe"
        "%LOCALAPPDATA%\Programs\Python\Python310\python.exe"
        "%LOCALAPPDATA%\Programs\Python\Python314\python.exe"
        "C:\Python312\python.exe"
        "C:\Python311\python.exe"
        "C:\Python310\python.exe"
        "C:\Program Files\Python312\python.exe"
        "C:\Program Files\Python311\python.exe"
    ) do (
        if exist %%P (
            %%P -c "import sys; print(sys.version)" >nul 2>nul
            if !errorlevel! equ 0 (
                set "PY_CMD=%%P"
                goto :FOUND_PYTHON
            )
        )
    )
)

:FOUND_PYTHON
:: Step 2: If Python is not installed, install it automatically in background
if not defined PY_CMD (
    echo [!] Python is not installed on this PC.
    echo [*] Downloading and installing Python automatically... Please wait 30 seconds...
    echo.
    
    :: Try winget first
    winget install Python.Python.3.12 --silent --accept-package-agreements --accept-source-agreements >nul 2>nul
    
    :: If winget didn't install, download official installer silently via PowerShell
    python -c "import sys" >nul 2>nul
    if %errorlevel% neq 0 (
        echo [*] Downloading Python setup package...
        powershell -NoProfile -ExecutionPolicy Bypass -Command "$wc=New-Object System.Net.WebClient; $installerPath=\"$env:TEMP\python_installer.exe\"; $wc.DownloadFile('https://www.python.org/ftp/python/3.11.8/python-3.11.8-amd64.exe', $installerPath); Start-Process -FilePath $installerPath -ArgumentList '/quiet InstallAllUsers=0 PrependPath=1 Include_test=0' -Wait; Remove-Item $installerPath" >nul 2>nul
    )

    :: Refresh PATH and find python again
    set "PATH=%LOCALAPPDATA%\Programs\Python\Python311;%LOCALAPPDATA%\Programs\Python\Python311\Scripts;%LOCALAPPDATA%\Programs\Python\Python312;%LOCALAPPDATA%\Programs\Python\Python312\Scripts;%PATH%"
    
    if exist "%LOCALAPPDATA%\Programs\Python\Python311\python.exe" (
        set "PY_CMD=%LOCALAPPDATA%\Programs\Python\Python311\python.exe"
    ) else if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" (
        set "PY_CMD=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
    ) else (
        set "PY_CMD=python"
    )
)

echo [1/2] Checking required packages (selenium, requests, pillow, webdriver-manager)...
%PY_CMD% -m pip install -q selenium webdriver-manager requests pillow >nul 2>nul

echo [2/2] Starting SkyRank Local Engine on 127.0.0.1:8989...
echo.
echo ================================================================
echo   Status: ONLINE [Ready to Post]
echo   Keep this black window OPEN while doing Auto-Posting.
echo   You can minimize it.
echo ================================================================
echo.

%PY_CMD% -u "%~dp0local_agent.py"
if %errorlevel% neq 0 (
    echo.
    echo Agent stopped.
    pause
)
