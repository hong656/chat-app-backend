<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ChatController;
use Illuminate\Support\Facades\Broadcast;

Route::post('register', [UserController::class, 'register']);
Route::post('login', [UserController::class, 'login']);
Route::get('get', [UserController::class, 'index']);
Route::get('user/{id}', [UserController::class, 'getUserById']);
Route::put('user/{id}', [UserController::class, 'updateUser']);
Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::get('profile', [UserController::class, 'profile']);
    Route::post('logout', [UserController::class, 'logout']);

    //Chat and message routes - protected by authentication
    Route::prefix('chat')->group(function () {
        Route::get('/', [ChatController::class, 'index']);
        Route::post('/', [ChatController::class, 'store']);
        Route::get('/{id}', [ChatController::class, 'show']);
        Route::put('/{id}', [ChatController::class, 'update']);
        Route::post('/{id}/member', [ChatController::class, 'addMember']);
        Route::delete('/{chatId}/members/{memberId}', [ChatController::class, 'removeMember']);
    });

    //Message routes - protected by authentication
    Route::prefix('messages')->group(function () {
        Route::get('/{chatId}', [MessageController::class, 'index']);
        Route::post('/', [MessageController::class, 'store']);
        Route::delete('/{messageId}', [MessageController::class, 'destroy']);
    });
});
