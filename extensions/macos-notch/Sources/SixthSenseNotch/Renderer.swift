import AppKit
import WebKit

/// `--render <folder>`: loads the page in an off-screen window, snapshots it and draws the notch
/// collapsed and expanded (with and without the microphone on) as PNGs. It neither touches the screen nor asks for permissions.
@MainActor
enum Renderer {
    private static var web: WebController?
    private static var window: NSWindow?

    static func start(dir: URL) {
        // A 13–14" MacBook Air/Pro: notch of ~185 × 32 pt.
        let layout = Layout(hasNotch: true, notchWidth: 185, bandHeight: 32, expandedSize: AppConfig.panelSize)
        let w = WebController(home: AppConfig.homeURL, persistent: false)
        let win = NSWindow(contentRect: NSRect(origin: CGPoint(x: -20000, y: -20000), size: layout.webSize),
                           styleMask: .borderless, backing: .buffered, defer: false)
        win.contentView = w.webView
        win.orderFrontRegardless()
        web = w
        window = win

        // Wait for the page to load (and paint) before the snapshot.
        DispatchQueue.main.asyncAfter(deadline: .now() + 6) {
            w.webView.takeSnapshot(with: nil) { image, error in
                MainActor.assumeIsolated {
                    guard let image else { print("snapshot failed:", error ?? "?"); exit(1) }
                    try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
                    let shots: [(String, Bool, Bool)] = [("collapsed", false, false), ("collapsed-mic", false, true),
                                                         ("expanded", true, false), ("expanded-mic-pinned", true, true)]
                    for (name, expanded, mic) in shots {
                        let url = dir.appendingPathComponent("\(name).png")
                        render(layout: layout, page: image, expanded: expanded, mic: mic, to: url)
                        print("→", url.path)
                    }
                    exit(0)
                }
            }
        }
    }

    private static func render(layout: Layout, page: NSImage, expanded: Bool, mic: Bool, to url: URL) {
        let size = expanded ? layout.expandedSize : layout.collapsedSize
        let pad: CGFloat = 24
        let canvas = NSView(frame: NSRect(x: 0, y: 0, width: size.width + 2 * pad, height: size.height + pad))
        canvas.wantsLayer = true
        canvas.layer?.backgroundColor = NSColor(calibratedRed: 0.86, green: 0.88, blue: 0.92, alpha: 1).cgColor

        let pageView = NSImageView(image: page)
        pageView.imageScaling = .scaleAxesIndependently
        let root = RootView(layout: layout, content: pageView)
        root.frame = NSRect(x: pad, y: 0, width: size.width, height: size.height)
        root.expanded = expanded
        root.micActive = mic
        root.pinned = mic && expanded
        canvas.addSubview(root)

        let win = NSWindow(contentRect: NSRect(origin: CGPoint(x: -20000, y: -20000), size: canvas.frame.size),
                           styleMask: .borderless, backing: .buffered, defer: false)
        win.contentView = canvas
        win.orderFrontRegardless()
        canvas.layoutSubtreeIfNeeded()
        canvas.displayIfNeeded()
        CATransaction.flush()
        RunLoop.main.run(until: Date().addingTimeInterval(0.3))

        let scale: CGFloat = 2
        guard let rep = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: Int(canvas.frame.width * scale),
                                         pixelsHigh: Int(canvas.frame.height * scale), bitsPerSample: 8,
                                         samplesPerPixel: 4, hasAlpha: true, isPlanar: false,
                                         colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0),
              let ctx = NSGraphicsContext(bitmapImageRep: rep)?.cgContext,
              let layer = canvas.layer else { return }
        ctx.scaleBy(x: scale, y: scale)
        layer.render(in: ctx)
        try? rep.representation(using: .png, properties: [:])?.write(to: url)
    }
}
