#!/bin/zsh
# Builds a release "Sixth Sense.app" into build/, signed ad hoc (not notarized).
# Usage: scripts/build-app.sh
set -e
cd "$(dirname "$0")/.."
APP="build/Sixth Sense.app"
VERSION="0.1.0"

# One build per architecture, joined with lipo: a multi-arch `swift build` needs full Xcode.
BINS=()
for ARCH in arm64 x86_64; do
  swift build -c release --arch $ARCH
  BINS+=("$(swift build -c release --arch $ARCH --show-bin-path)/SixthSenseNotch")
done

rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"
lipo -create "${BINS[@]}" -output "$APP/Contents/MacOS/SixthSenseNotch"

cat > "$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleIdentifier</key><string>io.github.johnleonkab.sixth-sense.notch</string>
  <key>CFBundleName</key><string>Sixth Sense</string>
  <key>CFBundleDisplayName</key><string>Sixth Sense</string>
  <key>CFBundleExecutable</key><string>SixthSenseNotch</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>${VERSION}</string>
  <key>CFBundleVersion</key><string>${VERSION}</string>
  <key>LSMinimumSystemVersion</key><string>14.0</string>
  <key>LSUIElement</key><true/>
  <key>NSHighResolutionCapable</key><true/>
  <key>NSMicrophoneUsageDescription</key><string>Sixth Sense usa el micrófono para que puedas hablarle.</string>
  <key>NSHumanReadableCopyright</key><string>MIT · github.com/johnleonkab/sixth-sense</string>
</dict>
</plist>
PLIST

codesign --force --sign - --timestamp=none "$APP"
echo "OK → $(pwd)/$APP"
