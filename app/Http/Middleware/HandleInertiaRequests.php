<?php

namespace App\Http\Middleware;

use App\Services\PostHogService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private PostHogService $posthog) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->posthog->contextFromHeaders($request->headers->all());

        if (($user = $request->user()) !== null) {
            $context['distinctId'] = (string) $user->getAuthIdentifier();
        }

        return $this->posthog->withContext(
            $context,
            fn (): Response => parent::handle($request, $next),
        );
    }

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    ...$request->user()->only('id', 'name', 'email'),
                    'display_scale' => $request->user()->display_size->scale(),
                ] : null,
            ],
        ];
    }
}
