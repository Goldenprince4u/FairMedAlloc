@echo off
REM ============================================================================
REM FairMedAlloc — Worker Startup Script (Windows)
REM ============================================================================
REM This script starts the background allocation worker on Windows.
REM 
REM Usage:
REM   1. Double-click this file from Windows Explorer, OR
REM   2. Run from Command Prompt: start_worker.bat
REM 
REM Keep this window open while using the allocation system.
REM ============================================================================

SETLOCAL ENABLEDELAYEDEXPANSION

REM Get the directory of this script
set "SCRIPT_DIR=%~dp0"
set "PHP_EXEC=C:\xampp\php\php.exe"

REM Check if PHP exists
if not exist "!PHP_EXEC!" (
    echo ERROR: PHP not found at !PHP_EXEC!
    echo Please ensure XAMPP is installed at C:\xampp
    pause
    exit /b 1
)

REM Check if worker script exists
if not exist "!SCRIPT_DIR!worker_launcher.php" (
    echo ERROR: worker_launcher.php not found in !SCRIPT_DIR!
    pause
    exit /b 1
)

REM Display startup message
cls
echo.
echo ============================================================================
echo FairMedAlloc — Allocation Worker
echo ============================================================================
echo.
echo This window contains the background job processor.
echo.
echo Instructions:
echo   • Keep this window OPEN while running allocations from the admin panel
echo   • You will see log messages like "Spawning worker for job #123"
echo   • To stop: close this window or press Ctrl+C
echo.
echo ============================================================================
echo.

REM Start the worker
"!PHP_EXEC!" "!SCRIPT_DIR!worker_launcher.php"

pause
