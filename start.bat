@echo off
title ProManaged Proposals
cd /d "%~dp0"
echo Starting ProManaged Proposals on http://127.0.0.1:8085
echo Keep this window open while you use it. Close it to stop.
start "" http://127.0.0.1:8085/index.php
php -S 127.0.0.1:8085 -t "%~dp0" "%~dp0router.php"
