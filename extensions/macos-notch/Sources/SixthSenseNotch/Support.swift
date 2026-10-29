import AppKit
import Carbon.HIToolbox
import ServiceManagement

enum AppConfig {
    static let defaultURL = "https://sixth-sense.on-forge.com/login"

    /// The sixth-sense instance. Set from the `⋯ → Servidor…` menu, or with
    /// `defaults write io.github.johnleonkab.sixth-sense.notch url https://your-server/login`
    static var homeURL: URL {
        if let s = UserDefaults.standard.string(forKey: "url"), let u = URL(string: s) { return u }
        // /login redirects to the assistant when the session is already open.
        return URL(string: defaultURL)!
    }

    /// Size of the expanded panel, in points. `defaults write … width -int 800` (and `height`), then reopen.
    static var panelSize: CGSize {
        let d = UserDefaults.standard
        let w = d.integer(forKey: "width"), h = d.integer(forKey: "height")
        return CGSize(width: w > 0 ? w : 720, height: h > 0 ? h : 600)
    }
}

// MARK: - Global shortcut

/// Global shortcut through Carbon: it needs no Accessibility permission.
final class HotKey {
    static let option = UInt32(optionKey)

    private var ref: EventHotKeyRef?
    private var handler: EventHandlerRef?
    private let action: () -> Void

    init(keyCode: UInt32, modifiers: UInt32, action: @escaping () -> Void) {
        self.action = action
        var spec = EventTypeSpec(eventClass: OSType(kEventClassKeyboard), eventKind: UInt32(kEventHotKeyPressed))
        InstallEventHandler(GetApplicationEventTarget(), { _, _, ctx in
            guard let ctx else { return noErr }
            Unmanaged<HotKey>.fromOpaque(ctx).takeUnretainedValue().action()
            return noErr
        }, 1, &spec, Unmanaged.passUnretained(self).toOpaque(), &handler)
        let id = EventHotKeyID(signature: OSType(0x5358_5448), id: 1) // "SXTH"
        let status = RegisterEventHotKey(keyCode, modifiers, id, GetApplicationEventTarget(), 0, &ref)
        if status != noErr { NSLog("SixthSenseNotch: another app already uses the shortcut (\(status))") }
    }

    deinit {
        if let ref { UnregisterEventHotKey(ref) }
        if let handler { RemoveEventHandler(handler) }
    }
}

// MARK: - Main menu

/// The app shows no menu bar, but without a menu ⌘C, ⌘V, ⌘Z, ⌘R… do nothing inside the page.
enum MainMenu {
    @MainActor
    static func build(delegate: AppDelegate) -> NSMenu {
        let main = NSMenu()

        let app = NSMenu()
        app.addItem(withTitle: "Salir de Sixth Sense", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "q")
        main.addItem(submenu: app, title: "Sixth Sense")

        let edit = NSMenu(title: "Edición")
        edit.addItem(withTitle: "Deshacer", action: Selector(("undo:")), keyEquivalent: "z")
        edit.addItem(withTitle: "Rehacer", action: Selector(("redo:")), keyEquivalent: "Z")
        edit.addItem(.separator())
        edit.addItem(withTitle: "Cortar", action: #selector(NSText.cut(_:)), keyEquivalent: "x")
        edit.addItem(withTitle: "Copiar", action: #selector(NSText.copy(_:)), keyEquivalent: "c")
        edit.addItem(withTitle: "Pegar", action: #selector(NSText.paste(_:)), keyEquivalent: "v")
        edit.addItem(withTitle: "Seleccionar todo", action: #selector(NSText.selectAll(_:)), keyEquivalent: "a")
        main.addItem(submenu: edit, title: "Edición")

        let view = NSMenu(title: "Ver")
        for (title, sel, key) in [("Recargar", #selector(AppDelegate.reloadPage(_:)), "r"),
                                  ("Atrás", #selector(AppDelegate.goBack(_:)), "["),
                                  ("Adelante", #selector(AppDelegate.goForward(_:)), "]"),
                                  ("Cerrar panel", #selector(AppDelegate.closePanel(_:)), "w")] {
            view.addItem(withTitle: title, action: sel, keyEquivalent: key).target = delegate
        }
        main.addItem(submenu: view, title: "Ver")
        return main
    }
}

private extension NSMenu {
    func addItem(submenu: NSMenu, title: String) {
        let item = NSMenuItem(title: title, action: nil, keyEquivalent: "")
        item.submenu = submenu
        addItem(item)
    }
}

// MARK: - Open at login

/// Off by default: the user turns it on from the `⋯` menu.
enum LoginItem {
    static var isEnabled: Bool { SMAppService.mainApp.status == .enabled }

    /// Returns the final state (macOS may refuse).
    @discardableResult
    static func set(_ on: Bool) -> Bool {
        do {
            if on { try SMAppService.mainApp.register() } else { try SMAppService.mainApp.unregister() }
        } catch {
            NSLog("SixthSenseNotch: login item \(on ? "register" : "unregister") failed: \(error)")
        }
        return isEnabled
    }
}
