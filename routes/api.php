<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ActorApiController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');




Route::get('/actors/prompt-validation', [ActorApiController::class, 'promptValidation']);
Route::post('/actors/prompt-validation', [ActorApiController::class, 'promptValidation']); // accept POST as well
