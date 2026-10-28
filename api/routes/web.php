<?php

use Illuminate\Support\Facades\Route;

// API only: the React app in web/ is the frontend.
Route::get('/', fn () => ['name' => config('app.name').' API']);
