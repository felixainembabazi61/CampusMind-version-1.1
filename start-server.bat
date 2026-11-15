@echo off
title CampusMind server
cd /d "%~dp0"

rem Find php.exe: on the PATH, or where winget installed it.
set "PHP=php"
where php >nul 2>nul
if errorlevel 1 (
  for /d %%D in ("%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_*") do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
)
if "%PHP%"=="php" (
  where php >nul 2>nul
  if errorlevel 1 (
    echo PHP was not found. Install it with:  winget install PHP.PHP.8.3
    pause
    exit /b 1
  )
)

echo.
echo  CampusMind is running at http://localhost:8000
echo  Keep this window open while you use the site. Press Ctrl+C to stop.
echo.
start "" http://localhost:8000
"%PHP%" -S localhost:8000 server.php
pause
