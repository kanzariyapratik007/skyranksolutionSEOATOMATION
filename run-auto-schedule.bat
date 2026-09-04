@echo off
REM SEO Auto-Schedule — Windows Task Scheduler thi run karo
REM Setup: Task Scheduler > Create Task > Actions > Start a program
REM Program: C:\Users\ADMIN\Desktop\seo-system\run-auto-schedule.bat

cd /d "C:\Users\ADMIN\Desktop\seo-system"
"C:\xampp\php\php.exe" auto-schedule.php >> logs\task-scheduler.log 2>&1
