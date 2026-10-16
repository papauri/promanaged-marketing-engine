# Registers the app's Windows scheduled tasks (run by schedule_agents.bat). Safe to run again: each task is replaced.
# Each task runs php.exe with a path relative to this folder as its working directory, so no quotes are nested inside the
# task's command line (schtasks /TR "php \"...\"" stored the backslashes literally and every task failed with "Could not open input file").
$ErrorActionPreference = 'Stop'
$here = $PSScriptRoot
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) { throw 'php is not on the PATH: install PHP 8.1 or newer, or add its folder to PATH, then run this again.' }

# A missed run (the computer was asleep) starts as soon as it can; a run never overlaps the previous one.
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 3)
$every = { param($min) New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes $min) }

# The morning meeting is at 07:30 in Malawi (UTC+2, the app's clock), whatever time zone this computer is in: the trigger carries the +02:00 offset
# ("synchronise across time zones"), so a computer in Sydney runs it at 16:30 in summer and 15:30 in winter, and the day's orders are made in the Malawi morning.
$morning = New-ScheduledTaskTrigger -Daily -At '07:30'
$morning.StartBoundary = (Get-Date).Date.ToString('yyyy-MM-dd') + 'T07:30:00+02:00'

$jobs = @(
    @{ Name = 'ProManaged Agents'; Script = 'lib\run_agents.php --all'; Trigger = $morning; What = 'every business''s agents, 07:30 daily Malawi time' },
    @{ Name = 'ProManaged Social'; Script = 'lib\social_run.php'; Trigger = (& $every 30); What = 'posts, autopilot and module jobs, every 30 minutes' },
    @{ Name = 'ProManaged Send';   Script = 'lib\send_due.php';   Trigger = (& $every 15); What = 'approved emails, every 15 minutes' },
    @{ Name = 'ProManaged Poll';   Script = 'lib\poll_run.php';   Trigger = (& $every 15); What = 'reads replies and bounces, every 15 minutes' }
)
foreach ($j in $jobs) {
    $action = New-ScheduledTaskAction -Execute $php -Argument $j.Script -WorkingDirectory $here
    Register-ScheduledTask -TaskName $j.Name -Action $action -Trigger $j.Trigger -Settings $settings -Force | Out-Null
    Write-Host ("Scheduled {0}: {1}" -f $j.Name, $j.What)
}
Start-ScheduledTask -TaskName 'ProManaged Poll' # read the inbox now, not in 15 minutes
Write-Host "Done. To remove: Unregister-ScheduledTask -TaskName 'ProManaged *' -Confirm:`$false"
