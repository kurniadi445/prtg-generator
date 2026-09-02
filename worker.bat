@echo off
rem ==========================================================================
rem  Menjalankan worker.php terus-menerus. Bila proses PHP berhenti karena
rem  alasan apa pun, skrip ini otomatis menjalankannya kembali.
rem  Semua keluaran ditulis ke worker.log.
rem
rem  KECUALI bila worker keluar dengan kode 3, yang berarti "berhenti atas
rem  permintaan" (tombol Stop di halaman Antrean, atau stop-worker.bat).
rem  Tanpa pengecualian itu, loop di bawah menyalakannya lagi 5 detik kemudian
rem  dan tombol Stop terlihat tidak berfungsi.
rem
rem  Jangan jalankan file ini langsung untuk mode background; pakai
rem  start-worker-hidden.vbs agar berjalan tanpa jendela.
rem ==========================================================================

title PRTG Worker
set "PHP=C:\xampp\php\php.exe"
cd /d "%~dp0"

:loop
echo [%date% %time%] --- worker dimulai --->> worker.log
"%PHP%" worker.php >> worker.log 2>&1

if %errorlevel% equ 3 (
    echo [%date% %time%] --- worker berhenti atas permintaan, tidak direstart --->> worker.log
    goto selesai
)

echo [%date% %time%] --- worker berhenti (exit %errorlevel%), restart 5 detik --->> worker.log
ping -n 6 127.0.0.1 >nul
goto loop

:selesai
