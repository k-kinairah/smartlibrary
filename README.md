# SmartLib

Self-service library system for SJCDC Library, built for local XAMPP/PHP + MySQL development.

## Local Setup

1. Start Apache and MySQL from XAMPP.
2. Make sure the project lives at:

```text
C:\xampp\htdocs\SmartLib
```

3. Make sure the local database is named:

```text
smartlib
```

4. Open the app:

```text
http://localhost/SmartLib/
```

5. Admin pages are under:

```text
http://localhost/SmartLib/admin/dashboard.php
```

## Local Email / 2FA

SmartLib uses PHP `mail()` through XAMPP sendmail for librarian/admin 2FA and PIN reset emails.

Useful local config files:

```text
C:\xampp\php\php.ini
C:\xampp\sendmail\sendmail.ini
```

For local development, keep sendmail debug logging disabled to avoid huge logs and slow mail sends:

```ini
;debug_logfile=debug.log
```

If 2FA is slow or stuck on "Sending Code...", check:

```text
C:\xampp\apache\logs\error.log
C:\xampp\sendmail\error.log
```

## Verification Commands

Run PHP syntax checks on key files:

```powershell
C:\xampp\php\php.exe -l C:\xampp\htdocs\SmartLib\login_handler.php
C:\xampp\php\php.exe -l C:\xampp\htdocs\SmartLib\admin\borrow_records.php
C:\xampp\php\php.exe -l C:\xampp\htdocs\SmartLib\admin\manage_books.php
C:\xampp\php\php.exe -l C:\xampp\htdocs\SmartLib\admin\reports.php
```

Run the safe Borrow Records workflow verifier:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\SmartLib\tools\verify_borrow_workflow.php
```

The verifier creates temporary data, tests `mark_returned` and `mark_missing`, then cleans up the temporary records.

## Git Workflow

This repo uses `main` as the GitHub branch.

Check status:

```powershell
git -C C:\xampp\htdocs\SmartLib status --short --branch
```

Commit a completed change:

```powershell
git -C C:\xampp\htdocs\SmartLib add <files>
git -C C:\xampp\htdocs\SmartLib commit -m "Describe the completed change"
```

Push to GitHub:

```powershell
git -C C:\xampp\htdocs\SmartLib push origin main
```
