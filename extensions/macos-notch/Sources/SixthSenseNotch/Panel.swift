import AppKit

// MARK: - Measurements

struct Layout {
    /// `false` on screens without a notch: the panel drops from the menu bar.
    let hasNotch: Bool
    let notchWidth: CGFloat
    /// Height of the top band (the notch, or the header on screens without one).
    let bandHeight: CGFloat
    let expandedSize: CGSize
    /// Width of each "wing" on the sides of the camera housing.
    let wing: CGFloat = 38
    static let inset: CGFloat = 6

    var collapsedSize: CGSize { CGSize(width: notchWidth + 2 * wing, height: bandHeight) }
    var webSize: CGSize {
        CGSize(width: expandedSize.width - 2 * Self.inset, height: expandedSize.height - bandHeight - Self.inset)
    }

    /// `nil` if the screen has no notch.
    static func notch(for screen: NSScreen) -> Layout? {
        let top = screen.safeAreaInsets.top
        guard top > 0, let left = screen.auxiliaryTopLeftArea, let right = screen.auxiliaryTopRightArea else { return nil }
        let width = screen.frame.width - left.width - right.width
        guard width > 0 else { return nil }
        return Layout(hasNotch: true, notchWidth: width, bandHeight: top, expandedSize: expandedSize(on: screen))
    }

    static func floating(for screen: NSScreen) -> Layout {
        Layout(hasNotch: false, notchWidth: 0, bandHeight: 34, expandedSize: expandedSize(on: screen))
    }

    private static func expandedSize(on screen: NSScreen) -> CGSize {
        let want = AppConfig.panelSize
        return CGSize(width: min(want.width, screen.frame.width - 80), height: min(want.height, screen.visibleFrame.height - 20))
    }
}

// MARK: - Window

final class NotchPanel: NSPanel {
    override var canBecomeKey: Bool { true }
    override var canBecomeMain: Bool { false }
}

final class FirstMouseButton: NSButton {
    override func acceptsFirstMouse(for event: NSEvent?) -> Bool { true }
}

/// Black background with the notch band on top and the page below.
/// The page always keeps its expanded size: collapsing clips it with the window instead of hiding it,
/// so WebKit still sees it as visible and audio and the microphone keep running.
final class RootView: NSView {
    let spec: Layout
    let content: NSView
    var onBandClick: (() -> Void)?
    var onBack: (() -> Void)?
    var onReload: (() -> Void)?
    var onPin: (() -> Void)?
    var onOpenInBrowser: (() -> Void)?
    var menuProvider: (() -> NSMenu)?

    var expanded = false { didSet { updateChrome(); needsLayout = true } }
    var pinned = false { didSet { updateChrome() } }
    var micActive = false { didSet { updateChrome() } }

    private let webClip = NSView()
    private let wingIcon = NSImageView()
    private let micDot = NSView()
    private let titleIcon = NSImageView()
    private let titleLabel = NSTextField(labelWithString: "Sixth Sense")
    private let buttons = NSStackView()
    private lazy var pinButton = makeButton("pin", "Fijar abierto", #selector(pinTapped))

    init(layout: Layout, content: NSView) {
        spec = layout
        self.content = content
        super.init(frame: .zero)
        wantsLayer = true
        layer?.masksToBounds = true
        layerContentsRedrawPolicy = .onSetNeedsDisplay

        webClip.wantsLayer = true
        webClip.layer?.cornerRadius = 9
        webClip.layer?.masksToBounds = true
        webClip.layer?.backgroundColor = NSColor(white: 0.08, alpha: 1).cgColor
        webClip.addSubview(content)
        addSubview(webClip)

        wingIcon.image = Self.symbol("waveform", size: 13)
        wingIcon.contentTintColor = .white
        titleIcon.image = Self.symbol("waveform", size: 13)
        titleIcon.contentTintColor = .white
        titleLabel.font = .systemFont(ofSize: 12, weight: .semibold)
        titleLabel.textColor = .white
        micDot.wantsLayer = true
        micDot.layer?.backgroundColor = NSColor.systemOrange.cgColor
        micDot.layer?.cornerRadius = 4
        micDot.toolTip = "Micrófono activo"

        buttons.orientation = .horizontal
        buttons.spacing = 2
        for b in [makeButton("chevron.left", "Atrás (⌘[)", #selector(backTapped)),
                  makeButton("arrow.clockwise", "Recargar (⌘R)", #selector(reloadTapped)),
                  pinButton,
                  makeButton("safari", "Abrir en el navegador", #selector(browserTapped)),
                  makeButton("ellipsis", "Más", #selector(moreTapped(_:)))] {
            buttons.addArrangedSubview(b)
        }
        [wingIcon, micDot, titleIcon, titleLabel, buttons].forEach(addSubview)
        updateChrome()
    }

    required init?(coder: NSCoder) { fatalError() }

    override func acceptsFirstMouse(for event: NSEvent?) -> Bool { true }

    /// Black with rounded bottom corners and square top corners, so it blends into the notch.
    override func draw(_ dirtyRect: NSRect) {
        let r = expanded ? 14.0 : 10.0
        NSColor.black.setFill()
        NSBezierPath(roundedRect: bounds, xRadius: r, yRadius: r).fill()
        NSRect(x: 0, y: r, width: bounds.width, height: max(0, bounds.height - r)).fill()
    }

    // MARK: Layout (AppKit coordinates: y grows upwards)

    override func layout() {
        super.layout()
        needsDisplay = true
        let b = bounds
        let band = spec.bandHeight
        let bandY = b.height - band

        let ws = spec.webSize
        webClip.frame = NSRect(x: ((b.width - ws.width) / 2).rounded(), y: bandY - ws.height,
                               width: ws.width, height: ws.height)
        content.frame = webClip.bounds

        // Collapsed: icon on the left wing, microphone dot on the right one.
        let wingX = ((b.width - spec.collapsedSize.width) / 2).rounded()
        wingIcon.frame = NSRect(x: wingX + (spec.wing - 18) / 2, y: bandY + (band - 18) / 2, width: 18, height: 18)

        // Expanded: title on the left, buttons on the right.
        titleIcon.frame = NSRect(x: 14, y: bandY + (band - 18) / 2, width: 18, height: 18)
        titleLabel.sizeToFit()
        titleLabel.frame.origin = NSPoint(x: 38, y: (bandY + (band - titleLabel.frame.height) / 2).rounded())
        let bs = buttons.fittingSize
        buttons.frame = NSRect(x: b.width - 10 - bs.width, y: bandY + (band - bs.height) / 2,
                               width: bs.width, height: bs.height)

        let dotX = expanded ? titleLabel.frame.maxX + 8 : b.width - wingX - spec.wing / 2 - 4
        micDot.frame = NSRect(x: dotX, y: bandY + band / 2 - 4, width: 8, height: 8)
    }

    private func updateChrome() {
        wingIcon.isHidden = expanded
        [titleIcon, titleLabel, buttons].forEach { $0.isHidden = !expanded }
        micDot.isHidden = !micActive
        pinButton.image = Self.symbol(pinned ? "pin.fill" : "pin", size: 12)
        pinButton.contentTintColor = pinned ? .systemOrange : NSColor(white: 0.72, alpha: 1)
        pinButton.toolTip = pinned ? "Fijado: no se cierra al hacer clic fuera" : "Fijar abierto"
        needsLayout = true
    }

    // MARK: Mouse

    override func mouseDown(with event: NSEvent) {
        let p = convert(event.locationInWindow, from: nil)
        if p.y >= bounds.height - spec.bandHeight { onBandClick?() } else { super.mouseDown(with: event) }
    }

    @objc private func backTapped() { onBack?() }
    @objc private func reloadTapped() { onReload?() }
    @objc private func pinTapped() { onPin?() }
    @objc private func browserTapped() { onOpenInBrowser?() }
    @objc private func moreTapped(_ sender: NSButton) {
        menuProvider?().popUp(positioning: nil, at: NSPoint(x: 0, y: sender.bounds.height + 4), in: sender)
    }

    private func makeButton(_ symbol: String, _ tip: String, _ action: Selector) -> FirstMouseButton {
        let b = FirstMouseButton(image: Self.symbol(symbol, size: 12) ?? NSImage(), target: self, action: action)
        b.isBordered = false
        b.contentTintColor = NSColor(white: 0.72, alpha: 1)
        b.toolTip = tip
        b.translatesAutoresizingMaskIntoConstraints = false
        b.widthAnchor.constraint(equalToConstant: 28).isActive = true
        b.heightAnchor.constraint(equalToConstant: 22).isActive = true
        return b
    }

    static func symbol(_ name: String, size: CGFloat) -> NSImage? {
        NSImage(systemSymbolName: name, accessibilityDescription: nil)?
            .withSymbolConfiguration(.init(pointSize: size, weight: .semibold))
    }
}

// MARK: - Controller

@MainActor
final class PanelController {
    let screen: NSScreen
    let layout: Layout
    let web: WebController
    let panel: NotchPanel
    let root: RootView
    private(set) var expanded = false
    private var pinned = false { didSet { root.pinned = pinned } }
    private var inModal = false
    private var statusItem: NSStatusItem?
    private var resignObserver: Any?
    private let menuTarget = MenuTarget()

    init(screen: NSScreen, layout: Layout, web: WebController) {
        self.screen = screen
        self.layout = layout
        self.web = web

        panel = NotchPanel(contentRect: .zero, styleMask: [.borderless, .nonactivatingPanel],
                           backing: .buffered, defer: false)
        panel.level = .statusBar
        panel.collectionBehavior = [.canJoinAllSpaces, .stationary, .fullScreenAuxiliary, .ignoresCycle]
        panel.isOpaque = false
        panel.backgroundColor = .clear
        panel.hasShadow = true
        panel.isMovable = false
        panel.hidesOnDeactivate = false
        panel.isReleasedWhenClosed = false

        web.webView.removeFromSuperview()
        root = RootView(layout: layout, content: web.webView)
        panel.contentView = root

        menuTarget.controller = self
        root.onBandClick = { [weak self] in self?.toggle() }
        root.onBack = { [weak web] in web?.webView.goBack() }
        root.onReload = { [weak web] in web?.reload() }
        root.onOpenInBrowser = { [weak self] in
            self?.web.openInBrowser()
            self?.collapse()
        }
        root.onPin = { [weak self] in self?.pinned.toggle() }
        root.menuProvider = { [weak self] in self?.moreMenu() ?? NSMenu() }
        web.onMicChange = { [weak self] active in self?.micChanged(active) }
        web.onModal = { [weak self] on in
            guard let self else { return }
            self.inModal = on
            if !on, self.expanded { self.panel.makeKey() }
        }
        micChanged(web.webView.microphoneCaptureState == .active)

        // A click outside collapses it, unless it is pinned.
        resignObserver = NotificationCenter.default.addObserver(
            forName: NSWindow.didResignKeyNotification, object: panel, queue: .main) { [weak self] _ in
            MainActor.assumeIsolated {
                guard let self, !self.pinned, !self.inModal else { return }
                self.collapse()
            }
        }

        if layout.hasNotch {
            panel.hasShadow = false
            panel.setFrame(frame(for: layout.collapsedSize), display: true)
            panel.orderFrontRegardless()
        } else {
            let item = NSStatusBar.system.statusItem(withLength: NSStatusItem.squareLength)
            item.button?.image = RootView.symbol("waveform", size: 13)
            item.button?.image?.isTemplate = true
            item.button?.target = menuTarget
            item.button?.action = #selector(MenuTarget.toggle)
            statusItem = item
            panel.setFrame(frame(for: layout.expandedSize), display: false)
        }
    }

    func tearDown() {
        if let resignObserver { NotificationCenter.default.removeObserver(resignObserver) }
        if let statusItem { NSStatusBar.system.removeStatusItem(statusItem) }
        web.onMicChange = nil
        web.onModal = nil
        web.webView.removeFromSuperview()
        panel.orderOut(nil)
        panel.close()
    }

    func toggle() { expanded ? collapse() : expand() }

    func expand() {
        guard !expanded else { panel.makeKey(); return }
        expanded = true
        root.expanded = true
        if !layout.hasNotch { panel.orderFrontRegardless() }
        setFrame(frame(for: layout.expandedSize))
        panel.makeKey()
        panel.makeFirstResponder(web.webView)
    }

    func collapse() {
        guard expanded else { return }
        expanded = false
        root.expanded = false
        if panel.isKeyWindow { panel.resignKey() }
        if layout.hasNotch {
            setFrame(frame(for: layout.collapsedSize))
        } else {
            panel.orderOut(nil)
        }
    }

    // MARK: Helpers

    /// Pinned to the top and centred: under the notch, or under the menu bar without one.
    private func frame(for size: CGSize) -> NSRect {
        let top = layout.hasNotch ? screen.frame.maxY : screen.visibleFrame.maxY - 4
        return NSRect(x: (screen.frame.midX - size.width / 2).rounded(), y: top - size.height,
                      width: size.width, height: size.height)
    }

    private func setFrame(_ f: NSRect) {
        NSAnimationContext.runAnimationGroup { ctx in
            ctx.duration = 0.18
            ctx.timingFunction = CAMediaTimingFunction(name: .easeOut)
            panel.animator().setFrame(f, display: true)
        }
    }

    private func micChanged(_ active: Bool) {
        root.micActive = active
        statusItem?.button?.image = RootView.symbol(active ? "mic.fill" : "waveform", size: 13)
        statusItem?.button?.image?.isTemplate = !active
        statusItem?.button?.contentTintColor = active ? .systemOrange : nil
    }

    private func moreMenu() -> NSMenu {
        let m = NSMenu()
        let server = NSMenuItem(title: "Servidor…", action: #selector(MenuTarget.chooseServer), keyEquivalent: "")
        server.target = menuTarget
        m.addItem(server)
        let login = NSMenuItem(title: "Abrir al iniciar sesión", action: #selector(MenuTarget.toggleLogin), keyEquivalent: "")
        login.target = menuTarget
        login.state = LoginItem.isEnabled ? .on : .off
        m.addItem(login)
        let hint = NSMenuItem(title: "Atajo: ⌥ Espacio", action: nil, keyEquivalent: "")
        hint.isEnabled = false
        m.addItem(hint)
        m.addItem(.separator())
        m.addItem(withTitle: "Salir de Sixth Sense", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "")
        return m
    }

    fileprivate func chooseServer() {
        web.chooseServer()
        if expanded { panel.makeKey() }
    }
}

/// Objective-C target for the menu bar icon and the `⋯` menu.
final class MenuTarget: NSObject {
    weak var controller: PanelController?

    @MainActor @objc func toggle() { controller?.toggle() }
    @MainActor @objc func chooseServer() { controller?.chooseServer() }
    @objc func toggleLogin() { LoginItem.set(!LoginItem.isEnabled) }
}
