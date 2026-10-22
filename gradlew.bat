@echo off
setlocal
set "GRADLE_VERSION=8.9"
set "BASE=%USERPROFILE%\.gradle\bayclub-bootstrap"
set "GRADLE_HOME=%BASE%\gradle-%GRADLE_VERSION%"
if not exist "%GRADLE_HOME%\bin\gradle.bat" (
  if not exist "%BASE%" mkdir "%BASE%"
  echo Downloading Gradle %GRADLE_VERSION% for the first build...
  powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; $z=Join-Path $env:TEMP 'bayclub-gradle-8.9-bin.zip'; Invoke-WebRequest -Uri 'https://services.gradle.org/distributions/gradle-8.9-bin.zip' -OutFile $z; Expand-Archive -Path $z -DestinationPath '%BASE%' -Force; Remove-Item $z -Force"
  if errorlevel 1 exit /b 1
)
call "%GRADLE_HOME%\bin\gradle.bat" %*
exit /b %ERRORLEVEL%
