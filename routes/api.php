<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware(['basicAuth'])->group(function () {
    Route::prefix('v1')->group(function () {

        Route::prefix('secretcontroller')->group(function () {
                Route::controller(\App\Http\Controllers\V1\SecretController::class)->group(function () {
                Route::post('add', 'add');
                Route::get('secret', 'find');
                Route::delete('delete', 'delete');
            });
        });

    });
});
