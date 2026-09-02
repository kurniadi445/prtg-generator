@echo off
setlocal
rem ==========================================================================
rem  Menghentikan PRTG Worker yang berjalan di belakang layar.
rem
rem  Caranya bertahap, dan urutannya penting:
rem
rem    1. Tulis tmp\worker.stop, lalu tunggu. Worker memeriksa berkas ini di
rem       awal tiap putaran dan keluar setelah job yang sedang berjalan
rem       selesai — dokumen yang sedang dibuat tidak ditinggalkan setengah jadi.
rem    2. Baru bila worker tidak juga berhenti, prosesnya dimatikan paksa.
rem
rem  Versi sebelumnya langsung mematikan paksa. Akibatnya job yang sedang
rem  diproses tertinggal berstatus 'processing' selamanya: tidak pernah
rem  dilanjutkan, dan tidak bisa dihapus dari halaman Antrean.
rem ==========================================================================

cd /d "%~dp0"

echo Meminta PRTG Worker berhenti dengan rapi...

if not exist "tmp" mkdir "tmp"
echo %date% %time%> "tmp\worker.stop"

rem Job satu bulan bisa memakan beberapa menit (scraping PRTG + render
rem Chromium), jadi tunggu cukup lama sebelum menyerah.
set /a SISA=180

:tunggu
if not exist "tmp\worker.json" goto berhenti_rapi

ping -n 3 127.0.0.1 >nul
set /a SISA-=2
if %SISA% gtr 0 goto tunggu

echo Worker belum berhenti setelah 3 menit. Menghentikan paksa...

powershell -NoProfile -Command ^
  "Get-CimInstance Win32_Process | Where-Object { ($_.Name -eq 'php.exe' -and $_.CommandLine -match 'worker\.php') -or ($_.Name -eq 'cmd.exe' -and $_.CommandLine -match '\\worker\.bat') -or ($_.Name -eq 'wscript.exe' -and $_.CommandLine -match 'start-worker-hidden\.vbs') } | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch {} }"

rem Heartbeat milik proses yang dimatikan paksa tidak sempat dihapus sendiri.
if exist "tmp\worker.json" del /q "tmp\worker.json"

echo Selesai (dihentikan paksa; job yang sedang diproses akan ditandai gagal
echo saat worker dijalankan lagi).
goto akhir

:berhenti_rapi
rem Loop worker.bat dan peluncur VBS-nya ikut berhenti sendiri karena worker
rem keluar dengan kode 3. Sisa berkas perintah dibersihkan bila worker sempat
rem keluar sebelum sempat membacanya.
if exist "tmp\worker.stop" del /q "tmp\worker.stop"

echo Selesai. Worker berhenti dengan rapi.

:akhir
endlocal
