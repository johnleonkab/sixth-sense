import AppKit

@MainActor
final class AppDelegate: NSObject, NSApplicationDelegate {
    let web = WebController(home: AppConfig.homeURL)
    private var panel: PanelController?
    private var hotKey: HotKey?

    func applicationDidFinishLaunching(_ note: Notification) {
        NSApp.mainMenu = MainMenu.build(delegate: self)
        present()
        NotificationCenter.default.addObserver(forName: NSApplication.didChangeScreenParametersNotification,
                                               object: nil, queue: .main) { [weak self] _ in
            MainActor.assumeIsolated { self?.present() }
        }
        // ⌥Space opens and closes the panel from any app.
        hotKey = HotKey(keyCode: 49, modifiers: HotKey.option) { [weak self] in
            MainActor.assumeIsolated { self?.panel?.toggle() }
        }
    }

    /// Opening the app again (Applications, Spotlight, Launchpad) drops the panel: there is no window or Dock icon.
    func applicationShouldHandleReopen(_ sender: NSApplication, hasVisibleWindows flag: Bool) -> Bool {
        panel?.expand()
        return false
    }

    /// The notch if a screen has one; otherwise a panel under the menu bar, toggled from a menu bar icon.
    /// The web view is shared, so changing screens neither reloads the page nor drops the conversation.
    private func present() {
        let wasExpanded = panel?.expanded ?? false
        panel?.tearDown()
        if let screen = NSScreen.screens.first(where: { Layout.notch(for: $0) != nil }),
           let layout = Layout.notch(for: screen) {
            panel = PanelController(screen: screen, layout: layout, web: web)
        } else if let screen = NSScreen.main ?? NSScreen.screens.first {
            panel = PanelController(screen: screen, layout: .floating(for: screen), web: web)
        }
        if wasExpanded { panel?.expand() }
    }

    // MARK: Menu actions (keyboard shortcuts)

    @objc func reloadPage(_ sender: Any?) { web.reload() }
    @objc func goBack(_ sender: Any?) { web.webView.goBack() }
    @objc func goForward(_ sender: Any?) { web.webView.goForward() }
    @objc func closePanel(_ sender: Any?) { panel?.collapse() }
}

// `--render <folder>`: loads the page off screen and writes the collapsed and expanded notch as PNGs.
// Useful to review the design without opening the app or capturing the screen.
if let i = CommandLine.arguments.firstIndex(of: "--render"), i + 1 < CommandLine.arguments.count {
    let dir = URL(fileURLWithPath: CommandLine.arguments[i + 1])
    let app = NSApplication.shared
    app.setActivationPolicy(.prohibited)
    MainActor.assumeIsolated { Renderer.start(dir: dir) }
    app.run()
}

let app = NSApplication.shared
app.setActivationPolicy(.accessory)
let delegate = MainActor.assumeIsolated { AppDelegate() }
app.delegate = delegate
app.run()
