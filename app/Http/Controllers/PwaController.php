<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class PwaController extends Controller
{
    /**
     * The install manifest. Relative URLs so the same app installs on localhost
     * and on sixth-sense.on-forge.com, each origin as its own copy.
     */
    public function manifest(): Response
    {
        return response(json_encode([
            'id' => '/',
            'name' => 'Sixth Sense',
            'short_name' => 'Sixth Sense',
            'description' => 'Tu jornada, hablando.',
            'lang' => 'es',
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0f1010',
            'theme_color' => '#0f1010',
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/manifest+json; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * The service worker, served from the origin root so it can control every page.
     */
    public function serviceWorker(): Response
    {
        return response(file_get_contents(resource_path('pwa/sw.js')), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
