# Registers the daily due-date reminder task in Windows Task Scheduler.
#
# Same logon setup as "SmartLib Daily Cover Fetch": "Run whether user is logged
# on or not", which needs the Windows password once. It's asked for in a normal
# Windows prompt and handed straight to Task Scheduler; nothing is saved here.
#
# Runs on battery and catches up after a missed 8:00 AM, because the host is a
# laptop and Task Scheduler otherwise skips the day (the cover task now does
# the same; see register_cover_fetch_task.ps1). Reminders are de-duplicated in due_soon_reminder_logs, so a
# catch-up run can't email anyone twice.
#
# Safe to run again: it replaces the existing task of the same name.

$taskName = 'SmartLib Due Date Reminders'
$userName = "$env:USERDOMAIN\$env:USERNAME"

$credential = Get-Credential -UserName $userName -Message "Windows password for $userName, to run '$taskName' while logged off"
if ($null -eq $credential) {
    Write-Host 'Cancelled. Nothing was changed.'
    exit 1
}

$action = New-ScheduledTaskAction -Execute 'C:\xampp\htdocs\SmartLib\run_due_date_reminders.bat'
$trigger = New-ScheduledTaskTrigger -Daily -At 8:00AM
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings `
    -User $userName -Password $credential.GetNetworkCredential().Password `
    -Description 'Daily due-date reminder emails. Output goes to tmp\due_date_reminders.log.' `
    -Force -ErrorAction Stop | Out-Null

$task = Get-ScheduledTask -TaskName $taskName
Write-Host "Registered '$taskName'"
Write-Host "  Logon type: $($task.Principal.LogonType) (Password = runs whether logged on or not)"
Write-Host "  Next run:   $((Get-ScheduledTaskInfo -TaskName $taskName).NextRunTime)"
