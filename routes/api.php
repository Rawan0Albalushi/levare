<?php

use App\Http\Controllers\Api\AssemblyIssueController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CouplingController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\PartController;
use App\Http\Controllers\Api\StocktakeController;
use Illuminate\Support\Facades\Route;

Route::get('/assemblies/{assembly}/issues', [AssemblyIssueController::class, 'index']);
Route::post('/assemblies/{assembly}/issues', [AssemblyIssueController::class, 'store']);
Route::get('/couplings', [CouplingController::class, 'index']);
Route::get('/couplings/template', [CouplingController::class, 'template']);
Route::post('/couplings/import', [CouplingController::class, 'import']);
Route::post('/couplings/included', [CouplingController::class, 'importIncluded']);
Route::post('/couplings/suggestions', [CouplingController::class, 'suggest']);
Route::post('/couplings/strings', [CouplingController::class, 'chain']);

Route::get('/catalog', [CatalogController::class, 'show']);
Route::post('/catalog/import', [CatalogController::class, 'import']);
Route::post('/parts', [PartController::class, 'store']);
Route::get('/parts/{part}/adjustments', [PartController::class, 'adjustments']);
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
Route::delete('/equipment/{equipment}/test', [EquipmentController::class, 'returnFromTest']);

Route::get('/stocktakes', [StocktakeController::class, 'index']);
Route::post('/stocktakes', [StocktakeController::class, 'store']);
Route::put('/stocktakes/{stocktake:public_id}', [StocktakeController::class, 'update']);
