<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\PartController;
use App\Http\Controllers\Api\StocktakeController;
use Illuminate\Support\Facades\Route;

Route::get('/catalog', [CatalogController::class, 'show']);
Route::post('/catalog/import', [CatalogController::class, 'import']);
Route::post('/parts', [PartController::class, 'store']);
Route::put('/parts/{part}', [PartController::class, 'update']);
Route::delete('/parts/{part}', [PartController::class, 'destroy']);

Route::get('/employees', [EmployeeController::class, 'index']);
Route::put('/employees', [EmployeeController::class, 'upsert']);

Route::get('/equipment', [EquipmentController::class, 'index']);
Route::post('/equipment', [EquipmentController::class, 'store']);
Route::post('/equipment/import', [EquipmentController::class, 'import']);
Route::put('/equipment/{equipment}', [EquipmentController::class, 'update']);
Route::delete('/equipment/{equipment}', [EquipmentController::class, 'destroy']);
Route::post('/equipment/{equipment}/test', [EquipmentController::class, 'sendToTest']);

Route::get('/stocktakes', [StocktakeController::class, 'index']);
Route::post('/stocktakes', [StocktakeController::class, 'store']);
Route::put('/stocktakes/{stocktake:public_id}', [StocktakeController::class, 'update']);
