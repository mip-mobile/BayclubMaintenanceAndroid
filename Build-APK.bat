@echo off
setlocal
cd /d "%~dp0"
if "%ANDROID_HOME%"=="" if "%ANDROID_SDK_ROOT%"=="" (
  if exist "%LOCALAPPDATA%\Android\Sdk\platforms\android-35" (
    set "ANDROID_HOME=%LOCALAPPDATA%\Android\Sdk"
    set "ANDROID_SDK_ROOT=%LOCALAPPDATA%\Android\Sdk"
  ) else (
    echo ERROR: Android SDK Platform 35 not found.
    echo Install Android Studio and Android SDK Platform 35, then set ANDROID_HOME or ANDROID_SDK_ROOT.
    pause
    exit /b 2
  )
)
call gradlew.bat clean assembleRelease
if errorlevel 1 (
  echo Build failed. Check Android Studio SDK setup and internet access for Gradle dependencies.
  pause
  exit /b 1
)
if not exist release mkdir release
copy /y app\build\outputs\apk\release\app-release.apk release\Bayclub-Maintenance.apk >nul
if errorlevel 1 (
  echo Build completed, but output APK could not be copied.
  pause
  exit /b 1
)
echo.
echo SUCCESS: release\Bayclub-Maintenance.apk
pause
