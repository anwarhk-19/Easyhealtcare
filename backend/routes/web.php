<?php

use Illuminate\Support\Facades\Route;

/*
| Serves the prebuilt Vue app (frontend/ → public/spa) for every non-API path,
| so the whole product runs with `php artisan serve` — no Node/npm needed at runtime.
| Rebuild after frontend changes: cd frontend && npm run build.
*/
Route::get('/{any?}', function () {
    $index = public_path('spa/index.html');
    abort_unless(is_file($index), 404, 'Frontend build missing. Run: cd frontend && npm install && npm run build');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('any', '^(?!api/|up$|spa/|storage/).*$');
