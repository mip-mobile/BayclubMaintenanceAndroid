#!/usr/bin/env sh
set -eu
GRADLE_VERSION=8.9
BASE="${GRADLE_USER_HOME:-$HOME/.gradle}/bayclub-bootstrap"
GRADLE_HOME="$BASE/gradle-$GRADLE_VERSION"
if [ ! -x "$GRADLE_HOME/bin/gradle" ]; then
  mkdir -p "$BASE"
  ZIP="$BASE/gradle-$GRADLE_VERSION-bin.zip"
  echo "Downloading Gradle $GRADLE_VERSION for the first build..."
  if command -v curl >/dev/null 2>&1; then curl -fL "https://services.gradle.org/distributions/gradle-$GRADLE_VERSION-bin.zip" -o "$ZIP"; else wget "https://services.gradle.org/distributions/gradle-$GRADLE_VERSION-bin.zip" -O "$ZIP"; fi
  unzip -q -o "$ZIP" -d "$BASE"
  rm -f "$ZIP"
fi
exec "$GRADLE_HOME/bin/gradle" "$@"
