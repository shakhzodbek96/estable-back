<?php

use Illuminate\Support\Facades\Route;

// Bu — API backend. Bosh sahifa hech narsa oshkor qilmasligi kerak:
// Laravel welcome sahifasi o'rniga oddiy 404.
Route::get('/', fn () => response('Not Found', 404));
