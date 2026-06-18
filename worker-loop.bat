@echo off
REM Starts worker.php in --loop mode in this terminal window.
REM Worker polls the pending queue, picks one job at a time, sleeps 5s when idle.
REM Press Ctrl+C to stop.

cd /d "%~dp0"
title Video Wall Worker
echo Starting Video Wall worker (loop mode)
echo Working directory: %CD%
echo Press Ctrl+C to stop.
echo.

php worker.php --loop

echo.
echo Worker exited.
pause
