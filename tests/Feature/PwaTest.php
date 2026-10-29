<?php

test('the install manifest is available on this origin', function () {
    $this->get('/site.webmanifest')
        ->assertOk()
        ->assertHeader('content-type', 'application/manifest+json; charset=UTF-8')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('start_url', '/')
        ->assertJsonPath('scope', '/')
        ->assertJsonPath('icons.0.src', '/icon-192.png')
        ->assertJsonPath('icons.1.sizes', '512x512');
});

test('the service worker can control the whole origin', function () {
    $response = $this->get('/sw.js');

    $response->assertOk()
        ->assertHeader('content-type', 'application/javascript; charset=UTF-8')
        ->assertHeader('service-worker-allowed', '/');

    expect($response->getContent())
        ->toContain("addEventListener('fetch'")
        ->toContain("request.mode === 'navigate'");
});
