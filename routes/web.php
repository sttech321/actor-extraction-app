<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ActorController;


// Default redirect to form page
Route::get('/', fn() => redirect()->route('actors.create'));

// Form page
Route::get('/actors/create', [ActorController::class, 'create'])->name('actors.create');

// Handle form submission
Route::post('/actors', [ActorController::class, 'store'])->name('actors.store');

// Show all past submissions
Route::get('/actors', [ActorController::class, 'index'])->name('actors.index');