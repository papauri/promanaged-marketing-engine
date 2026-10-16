@echo off
rem Registers the app's Windows scheduled tasks: every business's agents at 07:30 daily, social posts every 30 minutes,
rem approved emails and the reply check every 15 minutes. Double-click once; running it again replaces the tasks.
rem The work is done by schedule_agents.ps1 (it gives each task a working folder, so the path with spaces needs no nested quotes).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0schedule_agents.ps1"
pause
