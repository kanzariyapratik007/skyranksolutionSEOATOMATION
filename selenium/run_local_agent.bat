@echo off
title SkyRank Local Agent Engine (HTTPS Tunnel Mode)
color 0A
echo ==================================================
echo   SkyRank Local PC Agent Engine
echo   Port: 8989 (127.0.0.1)
echo   HTTPS Tunnel: Starting Cloudflare Tunnel...
echo ==================================================

set PY_EXE=python
where python >nul 2>nul
if %errorlevel% neq 0 (
    if exist "%LOCALAPPDATA%\Programs\Python\Python311\python.exe" (
        set PY_EXE="%LOCALAPPDATA%\Programs\Python\Python311\python.exe"
    ) else if exist "%LOCALAPPDATA%\Programs\Python\Python314\python.exe" (
        set PY_EXE="%LOCALAPPDATA%\Programs\Python\Python314\python.exe"
    )
)

%PY_EXE% -u "%~dp0local_agent.py"
pause

