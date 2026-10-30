<?php

namespace App\Http\Controllers\Settings;

use App\Connectors\Mcp\McpError;
use App\Connectors\Mcp\McpOAuth;
use App\Connectors\Mcp\McpServers;
use App\Connectors\Mcp\PublicUrl;
use App\Http\Controllers\Controller;
use App\Models\McpServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Remote MCP servers the person connects by URL, with a token or by signing
 * in (OAuth). Adding one checks it at once and keeps the tools it offers.
 */
class McpServerController extends Controller
{
    public function __construct(
        private McpServers $servers,
        private McpOAuth $oauth,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('settings/mcp', [
            'servers' => $request->user()->mcpServers()->orderBy('name')->get()->map(fn (McpServer $server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'url' => $server->url,
                'has_token' => filled($server->token),
                'auth' => $server->auth,
                'signed_in' => $server->signedIn(),
                'enabled' => $server->enabled,
                'checked_at' => $server->checked_at?->toIso8601String(),
                'error' => $server->last_error,
                'tools' => array_map(fn (array $tool): array => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'read_only' => $tool['read_only'],
                ], $server->tools ?? []),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[\pL\pN _.-]+$/u', Rule::unique('mcp_servers')->where('user_id', $request->user()->id)],
            'url' => ['required', 'url:https', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (($problem = PublicUrl::problem((string) $value)) !== null) {
                    $fail($problem);
                }
            }],
            'token' => ['nullable', 'string', 'max:4000'],
            'client_id' => ['nullable', 'string', 'max:500'],
            'client_secret' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.unique' => 'Ya tienes un servidor con ese nombre.',
            'name.regex' => 'El nombre solo puede llevar letras, números, espacios, puntos y guiones.',
            'url.url' => 'La URL tiene que empezar por https://.',
        ]);

        $server = $request->user()->mcpServers()->create(Arr::only($validated, ['name', 'url', 'token']));

        // No token and the server asks to sign in: connect it by signing in.
        if (blank($server->token) && $this->oauth->asksToSignIn($server)) {
            $server->update([
                'auth' => 'oauth',
                'oauth' => array_filter(Arr::only($validated, ['client_id', 'client_secret'])),
                'last_error' => 'Falta iniciar sesión.',
            ]);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Este servidor pide iniciar sesión: pulsa «Iniciar sesión».']);

            return back();
        }

        $ok = $this->servers->check($server);

        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => 'Conectado: '.count($server->tools ?? []).' herramientas.']
            : ['type' => 'warning', 'message' => "Guardado, pero no responde: {$server->last_error}"]);

        return back();
    }

    /**
     * Send the person to the server's sign-in page.
     */
    public function connect(Request $request, McpServer $server): RedirectResponse|SymfonyResponse
    {
        $this->owned($request, $server);

        try {
            return Inertia::location($this->oauth->start($server, route('settings.mcp.callback')));
        } catch (McpError $error) {
            $server->update(['last_error' => $error->getMessage()]);
            Inertia::flash('toast', ['type' => 'warning', 'message' => $error->getMessage()]);

            return redirect()->route('settings.mcp.index');
        }
    }

    /**
     * Back from signing in: keep the tokens and list the tools.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (filled($request->query('error')) || blank($request->query('code')) || blank($request->query('state'))) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'No se completó el inicio de sesión: '.($request->query('error_description') ?? $request->query('error') ?? 'falta el código.')]);

            return redirect()->route('settings.mcp.index');
        }

        try {
            $server = $this->oauth->finish($request->user(), (string) $request->query('state'), (string) $request->query('code'));
        } catch (McpError $error) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $error->getMessage()]);

            return redirect()->route('settings.mcp.index');
        }

        $ok = $this->servers->check($server);

        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => "Conectado a {$server->name}: ".count($server->tools ?? []).' herramientas.']
            : ['type' => 'warning', 'message' => "Sesión iniciada, pero no responde: {$server->last_error}"]);

        return redirect()->route('settings.mcp.index');
    }

    /**
     * Connect again and refresh its tools.
     */
    public function refresh(Request $request, McpServer $server): RedirectResponse
    {
        $this->owned($request, $server);
        $ok = $this->servers->check($server);

        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => count($server->tools ?? []).' herramientas.']
            : ['type' => 'warning', 'message' => (string) $server->last_error]);

        return back();
    }

    public function update(Request $request, McpServer $server): RedirectResponse
    {
        $this->owned($request, $server);
        $server->update($request->validate(['enabled' => ['required', 'boolean']]));

        return back();
    }

    public function destroy(Request $request, McpServer $server): RedirectResponse
    {
        $this->owned($request, $server);
        $server->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Servidor quitado.']);

        return back();
    }

    private function owned(Request $request, McpServer $server): void
    {
        abort_unless($server->user_id === $request->user()->id, 404);
    }
}
