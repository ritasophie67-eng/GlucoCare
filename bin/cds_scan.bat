@echo off
REM Recommended Task Scheduler command (every 30 minutes):
REM   C:\xampp\htdocs\phase4\bin\cds_scan.bat
"C:\xampp\php\php.exe" "%~dp0cds_scan.php" --verbose >> "%~dp0..\logs\cds-scan.log" 2>&1
"C:\xampp\php\php.exe" "%~dp0cds_digest.php" --verbose >> "%~dp0..\logs\cds-scan.log" 2>&1
