@echo off
REM Daily book-cover backfill for SmartLib.
REM
REM Runs tools/fetch_book_covers.php --commit, which only ever touches books
REM that still have no cover (already-matched books are skipped), so this is
REM safe to run once a day without rechecking anything. Open Library (no
REM daily cap) is tried first; Google Books is only contacted as a fallback
REM for whatever Open Library couldn't match, so this stays well under
REM Google's 1,000-query/day free quota on a 2,031-book catalog.
REM
REM Intended to be run by Windows Task Scheduler once a day. Output is
REM appended to logs\fetch_book_covers.log so a day's run can be checked
REM without having to watch it happen.

setlocal
set "SMARTLIB_DIR=C:\xampp\htdocs\SmartLib"
set "PHP_EXE=C:\xampp\php\php.exe"
set "LOG_DIR=%SMARTLIB_DIR%\logs"
set "LOG_FILE=%LOG_DIR%\fetch_book_covers.log"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

echo. >> "%LOG_FILE%"
echo ===== %date% %time% ===== >> "%LOG_FILE%"

cd /d "%SMARTLIB_DIR%"
"%PHP_EXE%" tools\fetch_book_covers.php --commit >> "%LOG_FILE%" 2>&1

endlocal
