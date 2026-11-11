<?php
// Incluir DENTRO del grupo api/mcp con auth:sanctum, abilities:mcp y throttle:60,1.
use App\Http\Controllers\Mcp\McpActasController;
use Illuminate\Support\Facades\Route;

Route::prefix('operaciones/actas')->group(function () {
    Route::get('/', [McpActasController::class, 'buscar']);
    Route::get('{id}', [McpActasController::class, 'ver'])->whereNumber('id');
    Route::get('{id}/pdf', [McpActasController::class, 'enlacePdf'])->whereNumber('id');
    Route::get('{id}/carga', [McpActasController::class, 'enlaceCarga'])->whereNumber('id');
    Route::get('{id}/firmada', [McpActasController::class, 'enlaceFirmada'])->whereNumber('id');
});
