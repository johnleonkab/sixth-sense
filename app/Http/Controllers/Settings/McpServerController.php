<?php

namespace App\Http\Controllers\Settings;

use App\Connectors\Mcp\McpServers;
use App\Connectors\Mcp\PublicUrl;
use App\Http\Controllers\Controller;
use App\Models\McpServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Remote MCP servers the person connects by URL and token. Adding one checks
 * it at once and keeps the tools it offers.
 */
class McpServerController extends Controller
{
    public function __construct(private McpServers $servers) {}

    public function index(Request $request): Response
    {
        return Inertia::render('settings/mcp', [
            'servers' => $request->user()->mcpServers()->orderBy('name')->get()->map(fn (McpServer $server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'url' => $server->url,
                'has_token' => filled($server->token),
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
        ], [
            'name.unique' => 'Ya tienes un servidor con ese nombre.',
            'name.regex' => 'El nombre solo puede llevar letras, números, espacios, puntos y guiones.',
            'url.url' => 'La URL tiene que empezar por https://.',
        ]);

        $server = $request->user()->mcpServers()->create($validated);
        $ok = $this->servers->check($server);

        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => 'Conectado: '.count($server->tools ?? []).' herramientas.']
            : ['type' => 'warning', 'message' => "Guardado, pero no responde: {$server->last_error}"]);

        return back();
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
