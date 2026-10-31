<?php

use App\Http\Controllers\InstagramOAuthController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 definido em bootstrap/app.php.

Route::get('/instagram/connect', [InstagramOAuthController::class, 'connect'])->name('instagram.connect');
Route::get('/instagram/callback', [InstagramOAuthController::class, 'callback'])->name('instagram.callback');
