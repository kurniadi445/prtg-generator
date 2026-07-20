@echo off
rem ==========================================================================
rem  Menghentikan PRTG Worker yang berjalan di belakang layar:
rem  proses loop (worker.bat), peluncur (start-worker-hidden.vbs), dan
rem  proses PHP (worker.php). Tidak mengganggu proses PHP lain.
rem ==========================================================================

echo Menghentikan PRTG Worker...

powershell -NoProfile -Command ^
  "Get-CimInstance Win32_Process | Where-Object { ($_.Name -eq 'php.exe' -and $_.CommandLine -match 'worker\.php') -or ($_.Name -eq 'cmd.exe' -and $_.CommandLine -match '\\worker\.bat') -or ($_.Name -eq 'wscript.exe' -and $_.CommandLine -match 'start-worker-hidden\.vbs') } | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch {} }"

echo Selesai.
