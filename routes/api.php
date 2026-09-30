<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sacntum')->get('/me', function (Request $request) {
    return response()->json([
        'user' => $request->user(),
    ]);
});

Route::middleware('auth-sanctum')->post('/logout', [AuthController::class, 'logout']);
