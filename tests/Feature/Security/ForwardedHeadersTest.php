<?php

use Database\Seeders\ContentSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => config()->set('statementra.runtime.tasks', []));

it('never builds links from a forwarded Host header, even when every proxy is trusted', function () {
    $this->seed(ContentSeeder::class);
    config(['trustedproxy.proxies' => '*']);

    $this->get('/how-it-works', ['X-Forwarded-Host' => 'evil.example'])
        ->assertOk()
        ->assertDontSee('evil.example');
});

it('takes the client IP from X-Forwarded-For only when the proxy is trusted', function () {
    Route::get('/_test/client-ip', fn () => (string) request()->ip());

    config(['trustedproxy.proxies' => null]);
    $this->get('/_test/client-ip', ['X-Forwarded-For' => '203.0.113.77'])->assertSeeText('127.0.0.1');

    config(['trustedproxy.proxies' => ['127.0.0.1']]);
    $this->get('/_test/client-ip', ['X-Forwarded-For' => '203.0.113.77'])->assertSeeText('203.0.113.77');
});
