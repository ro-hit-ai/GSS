Set shell = CreateObject("WScript.Shell")
phpExe = "C:\xampp\php\php.exe"
scriptPath = "C:\xampp\htdocs\GSS\fetch_replies.php"
cmd = """" & phpExe & """ """ & scriptPath & """"
' Run hidden, do not wait.
shell.Run cmd, 0, False
