@ECHO OFF
set "PHPCMD=C:\php-8.1.6\php.exe"

REM 
REM  Bootstrap script
REM 

set daemon=0
for %%f in (%*) do (
  if "%%f"=="-daemon" (
     set daemon=1
  )
)

if "%daemon%" == "1" (
  start %PHPCMD%  %~p0\bootstrap.php %*
) else (
  %PHPCMD%  %~p0\bootstrap.php %*
)