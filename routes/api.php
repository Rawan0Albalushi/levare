<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\StocktakeController;
use Illuminate\Support\Facades\Route;

Route::get('/catalog', [CatalogController::class, 'show']);
Route::post('/catalog/import', [CatalogController::class, 'import']);

Route::get('/employees', [EmployeeController::class, 'index']);
Route::put('/employees', [EmployeeController::class, 'upsert']);

Route::get('/stocktakes', [StocktakeController::class, 'index']);
Route::post('/stocktakes', [StocktakeController::class, 'store']);
Route::put('/stocktakes/{stocktake:public_id}', [StocktakeController::class, 'update']);
