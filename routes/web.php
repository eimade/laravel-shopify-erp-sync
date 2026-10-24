<?php

use Illuminate\Support\Facades\Route;

// Front JS de démonstration consommant l'API GraphQL interne.
Route::view('/', 'catalogue')->name('catalogue');
