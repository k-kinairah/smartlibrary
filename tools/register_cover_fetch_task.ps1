# Registers (or re-registers) the nightly cover-fetch task in Windows Task
# Scheduler.
#
# Same logon setup as "SmartLib Due Date Reminders": "Run whether user is
# logged on or not", which needs the Windows password once. It's asked for in
# a normal Windows prompt and handed straight to Task Scheduler; nothing is
# saved here.
#
# The host is a laptop, so the task is allowed to start on battery, isn't
# killed when the power source changes, catches up after a missed 3:00 AM,
# and asks Windows to wake the PC for it. The old settings did none of that,
# which is why most nights either didn't run or were cut off partway.
# Waking only works if the power plan allows wake timers; see the note
# printed at the end.
#
# A catch-up run is safe: the fetch only touches books that still have no
# cover, and IgnoreNew stops two runs overlapping.
#
# Safe to run again: it replaces the existing task of the same name.

$taskName = 'SmartLib Daily Cover Fetch'
$userName = "$env:USERDOMAIN\$env:USERNAME"

$credential = Get-Credential -UserName $userName -Message "Windows password for $userName, to run '$taskName' while logged off"
if ($null -eq $credential) {
    Write-Host 'Cancelled. Nothing was changed.'
    exit 1
}

$action = New-ScheduledTaskAction -Execute 'C:\xampp\htdocs\SmartLib\tools\run_fetch_book_covers.bat'
$trigger = New-ScheduledTaskTrigger -Daily -At 3:00AM
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -WakeToRun -ExecutionTimeLimit (New-TimeSpan -Hours 6) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings `
    -User $userName -Password $credential.GetNetworkCredential().Password `
    -Description 'Nightly book-cover backfill. Output goes to logs\fetch_book_covers.log.' `
    -Force -ErrorAction Stop | Out-Null

$task = Get-ScheduledTask -TaskName $taskName
Write-Host "Registered '$taskName'"
Write-Host "  Logon type: $($task.Principal.LogonType) (Password = runs whether logged on or not)"
Write-Host "  Next run:   $((Get-ScheduledTaskInfo -TaskName $taskName).NextRunTime)"
Write-Host "  Battery:    starts on battery = $(-not $task.Settings.DisallowStartIfOnBatteries), stopped on battery = $($task.Settings.StopIfGoingOnBatteries)"
Write-Host "  Catch-up:   $($task.Settings.StartWhenAvailable)   Wake PC: $($task.Settings.WakeToRun)"

$wake = powercfg /q SCHEME_CURRENT SUB_SLEEP BD3B718A-0680-4D9D-8AB2-E1D2B4AC806D 2>$null |
    Select-String 'Current (?:AC|DC) Power Setting Index: 0x0+(\d)' |
    ForEach-Object { $_.Matches[0].Groups[1].Value }
if ($wake -contains '0') {
    Write-Host ''
    Write-Host '  Note: this power plan has wake timers turned off, so Windows will not wake'
    Write-Host '  the PC for this task. If the laptop is asleep at 3:00 AM, the run happens'
    Write-Host '  when it next wakes instead. To let it wake the PC: Control Panel > Power'
    Write-Host '  Options > Change plan settings > Change advanced power settings > Sleep >'
    Write-Host '  Allow wake timers > Enable (for both "On battery" and "Plugged in").'
}
