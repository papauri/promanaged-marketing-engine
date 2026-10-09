@echo off
rem Registers a daily 07:30 Windows task that runs the prospecting agents for ProManaged IT, then for Travel Malawi.
rem Double-click once. To remove: schtasks /Delete /TN "ProManaged Agents" /F
set "HERE=%~dp0"
schtasks /Create /SC DAILY /ST 07:30 /TN "ProManaged Agents" /TR "cmd /c php \"%HERE%lib\run_agents.php\" & php \"%HERE%lib\run_agents.php\" travel" /F
schtasks /Create /SC MINUTE /MO 30 /TN "ProManaged Social" /TR "php \"%HERE%lib\social_run.php\"" /F
rem Slow drip of owner-approved emails (one per run, Mon-Fri 08:00-16:30) and reply polling, every 15 minutes.
schtasks /Create /SC MINUTE /MO 15 /TN "ProManaged Send" /TR "php \"%HERE%lib\send_due.php\"" /F
schtasks /Create /SC MINUTE /MO 15 /TN "ProManaged Poll" /TR "php \"%HERE%lib\poll_run.php\"" /F
pause
