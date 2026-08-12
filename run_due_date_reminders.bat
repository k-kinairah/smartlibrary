@echo off
setlocal

set "PHP_EXE=C:\xampp\php\php.exe"
set "SCRIPT=C:\xampp\htdocs\SmartLib\send_due_date_reminders.php"
set "LOG_DIR=C:\xampp\htdocs\SmartLib\tmp"
set "LOG_FILE=%LOG_DIR%\due_date_reminders.log"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

echo [%DATE% %TIME%] Running due-date reminder worker...>>"%LOG_FILE%"
"%PHP_EXE%" "%SCRIPT%" >>"%LOG_FILE%" 2>&1
echo.>>"%LOG_FILE%"

endlocal
