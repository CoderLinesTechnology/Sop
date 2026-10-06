<?php

use App\Http\Controllers\RuntimeController;
use Illuminate\Support\Facades\Route;

/*
| Runtime routes: registered without the "web" group (no session, cookies or
| CSRF). They are authenticated by an HMAC signature (pipeline continuation)
| or by a secret token (heartbeat ping).
*/

Route::post('/internal/pipeline/{aiJob}', [RuntimeController::class, 'continuePipeline'])
    ->middleware(['runtime.signed', 'throttle:120,1'])
    ->whereUuid('aiJob')
    ->name('internal.pipeline.continue');

Route::match(['GET', 'POST', 'HEAD'], '/system/heartbeat/{token}', [RuntimeController::class, 'heartbeat'])
    ->middleware('throttle:20,1')
    ->where('token', '[A-Za-z0-9]{20,100}')
    ->name('system.heartbeat');
