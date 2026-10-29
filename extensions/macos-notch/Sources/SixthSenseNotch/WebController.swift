import AppKit
import WebKit

/// The sixth-sense page. It lives as long as the app: collapsing the panel neither reloads it nor cuts the voice.
@MainActor
final class WebController: NSObject, WKNavigationDelegate, WKUIDelegate {
    let webView: WKWebView
    private(set) var home: URL
    /// `true` while the page is using the microphone.
    var onMicChange: ((Bool) -> Void)?
    /// Tells the panel a dialog opens (or closes), so it does not collapse when it loses focus.
    var onModal: ((Bool) -> Void)?

    private var micObservation: NSKeyValueObservation?
    private var showingError = false

    init(home: URL, persistent: Bool = true) {
        self.home = home
        let config = WKWebViewConfiguration()
        // Default store: cookies and the session survive restarts.
        config.websiteDataStore = persistent ? .default() : .nonPersistent()
        // The spoken answer has to play without a click first.
        config.mediaTypesRequiringUserActionForPlayback = []
        config.preferences.javaScriptCanOpenWindowsAutomatically = true
        webView = WKWebView(frame: .zero, configuration: config)
        // Safari's user agent: Google refuses OAuth sign-in in web views with the default one.
        webView.customUserAgent = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15"
        webView.allowsBackForwardNavigationGestures = true
        webView.isInspectable = true
        webView.underPageBackgroundColor = .black
        super.init()
        webView.navigationDelegate = self
        webView.uiDelegate = self
        micObservation = webView.observe(\.microphoneCaptureState, options: [.new]) { [weak self] wv, _ in
            let active = wv.microphoneCaptureState == .active
            DispatchQueue.main.async { MainActor.assumeIsolated { self?.onMicChange?(active) } }
        }
        webView.load(URLRequest(url: home))
    }

    func reload() {
        if showingError || webView.url == nil {
            showingError = false
            webView.load(URLRequest(url: home))
        } else {
            webView.reload()
        }
    }

    func openInBrowser() {
        let url = showingError ? home : (webView.url ?? home)
        NSWorkspace.shared.open(url)
    }

    /// Asks for the instance URL, saves it and loads it.
    func chooseServer() {
        let alert = NSAlert()
        alert.messageText = "Servidor de Sixth Sense"
        alert.informativeText = "La dirección de tu instancia, por ejemplo https://sixth-sense.tu-dominio.com"
        let field = NSTextField(frame: NSRect(x: 0, y: 0, width: 320, height: 24))
        field.stringValue = home.absoluteString
        alert.accessoryView = field
        alert.addButton(withTitle: "Guardar")
        alert.addButton(withTitle: "Cancelar")
        alert.layout()
        alert.window.initialFirstResponder = field
        guard runModal({ alert.window.level = Self.aboveNotch; return alert.runModal() }) == .alertFirstButtonReturn else { return }

        var text = field.stringValue.trimmingCharacters(in: .whitespacesAndNewlines)
        if text.isEmpty { text = AppConfig.defaultURL }
        if !text.contains("://") { text = "https://" + text }
        guard var url = URL(string: text), url.host != nil else { return }
        // The bare domain goes to /login, which redirects to the assistant when the session is open.
        if url.path.isEmpty || url.path == "/" { url = url.appendingPathComponent("login") }
        UserDefaults.standard.set(url.absoluteString, forKey: "url")
        home = url
        showingError = false
        webView.load(URLRequest(url: url))
    }

    // MARK: Navigation

    func webView(_ webView: WKWebView, decidePolicyFor action: WKNavigationAction,
                 decisionHandler: @escaping @MainActor (WKNavigationActionPolicy) -> Void) {
        guard let url = action.request.url, let scheme = url.scheme?.lowercased() else { return decisionHandler(.allow) }
        // mailto:, slack:, zoommtg:… go to their app.
        if !["http", "https", "about", "blob", "data"].contains(scheme) {
            NSWorkspace.shared.open(url)
            return decisionHandler(.cancel)
        }
        decisionHandler(.allow)
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        if webView.url?.scheme?.hasPrefix("http") == true { showingError = false }
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        let e = error as NSError
        guard e.code != NSURLErrorCancelled else { return }
        showingError = true
        webView.loadHTMLString(Self.errorPage(e.localizedDescription), baseURL: nil)
    }

    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
        webView.reload()
    }

    // MARK: New windows, permissions and dialogs

    /// Links that open a new tab go to the default browser.
    func webView(_ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration,
                 for action: WKNavigationAction, windowFeatures: WKWindowFeatures) -> WKWebView? {
        if let url = action.request.url { NSWorkspace.shared.open(url) }
        return nil
    }

    /// The microphone without asking every time, only for the configured instance.
    func webView(_ webView: WKWebView, requestMediaCapturePermissionFor origin: WKSecurityOrigin,
                 initiatedByFrame frame: WKFrameInfo, type: WKMediaCaptureType,
                 decisionHandler: @escaping @MainActor (WKPermissionDecision) -> Void) {
        decisionHandler(origin.host == home.host && type == .microphone ? .grant : .prompt)
    }

    func webView(_ webView: WKWebView, runJavaScriptAlertPanelWithMessage message: String,
                 initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping @MainActor () -> Void) {
        _ = runAlert(message, buttons: ["OK"])
        completionHandler()
    }

    func webView(_ webView: WKWebView, runJavaScriptConfirmPanelWithMessage message: String,
                 initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping @MainActor (Bool) -> Void) {
        completionHandler(runAlert(message, buttons: ["OK", "Cancelar"]) == .alertFirstButtonReturn)
    }

    func webView(_ webView: WKWebView, runOpenPanelWith parameters: WKOpenPanelParameters,
                 initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping @MainActor ([URL]?) -> Void) {
        let open = NSOpenPanel()
        open.allowsMultipleSelection = parameters.allowsMultipleSelection
        open.canChooseDirectories = parameters.allowsDirectories
        open.level = Self.aboveNotch
        let result = runModal { open.runModal() }
        completionHandler(result == .OK ? open.urls : nil)
    }

    // MARK: Helpers

    /// Above the notch panel (which sits at status bar level).
    static let aboveNotch = NSWindow.Level(rawValue: NSWindow.Level.statusBar.rawValue + 1)

    private func runModal(_ body: () -> NSApplication.ModalResponse) -> NSApplication.ModalResponse {
        onModal?(true)
        NSApp.activate(ignoringOtherApps: true)
        let r = body()
        onModal?(false)
        return r
    }

    private func runAlert(_ message: String, buttons: [String]) -> NSApplication.ModalResponse {
        let alert = NSAlert()
        alert.messageText = "Sixth Sense"
        alert.informativeText = message
        buttons.forEach { alert.addButton(withTitle: $0) }
        alert.layout()
        alert.window.level = Self.aboveNotch
        return runModal { alert.runModal() }
    }

    private static func errorPage(_ detail: String) -> String {
        let safe = detail.replacingOccurrences(of: "<", with: "&lt;")
        return """
        <html><body style="margin:0;height:100vh;display:flex;align-items:center;justify-content:center;
        background:#111;color:#ddd;font:14px -apple-system,sans-serif;text-align:center">
        <div><div style="font-size:16px;font-weight:600;color:#fff;margin-bottom:8px">No se pudo abrir Sixth Sense</div>
        <div style="color:#999;margin-bottom:16px">\(safe)</div>
        <div style="color:#999">Comprueba la conexión o el servidor (⋯ → Servidor…) y pulsa ↻ (o ⌘R).</div></div></body></html>
        """
    }
}
