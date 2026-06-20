@echo off
REM Prompts for a YouTube playlist URL, then downloads the audio (m4a) of every
REM video into storage\playlist-audio\<list-id>\. Standalone: no DB, no transcription.
REM Re-runs skip already-downloaded files. Leave the prompt empty to quit.

cd /d "%~dp0"
title Playlist Audio Download

:loop
echo.
set "url="
set /p url="Playlist URL (blank to quit): "
if "%url%"=="" goto end

php playlist-audio-download.php "%url%"

echo.
echo --- finished, ready for next playlist ---
goto loop

:end
echo Bye.
