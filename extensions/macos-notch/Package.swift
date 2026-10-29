// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "SixthSenseNotch",
    platforms: [.macOS(.v14)],
    targets: [
        // Notch / menu bar app with sixth-sense inside (AppKit + WebKit), no dependencies.
        .executableTarget(name: "SixthSenseNotch"),
    ]
)
