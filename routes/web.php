<?php

use Illuminate\Support\Facades\Route;

// Serve the React SPA for every non-API path. Client-side routing takes over from there.
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|sanctum|up).*$');
