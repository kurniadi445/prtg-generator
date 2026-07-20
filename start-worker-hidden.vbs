' ==========================================================================
'  Menjalankan worker.bat tanpa jendela (di belakang layar), lepas dari
'  VS Code / terminal. Klik dua kali file ini untuk memulai.
'
'  Untuk menghentikan: jalankan stop-worker.bat.
' ==========================================================================

Dim shell, folder
Set shell = CreateObject("WScript.Shell")
folder = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)

shell.CurrentDirectory = folder
' Argumen ke-2 = 0 -> jendela disembunyikan; ke-3 = False -> tidak menunggu
shell.Run """" & folder & "\worker.bat""", 0, False
