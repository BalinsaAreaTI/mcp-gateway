<?php
// Incluir desde routes/web.php antes de fallbacks. Conserva sesión y CSRF del grupo web.
use App\Http\Controllers\Mcp\McpActasController;
use Illuminate\Support\Facades\Route;

Route::prefix('mcp/actas')->middleware(['auth', 'throttle:20,1'])->group(function () {
    Route::get('{id}/pdf', [McpActasController::class, 'abrirPdf'])->whereNumber('id')->name('mcp.actas.pdf');
    Route::get('{id}/firmada', [McpActasController::class, 'abrirFirmada'])->whereNumber('id')->name('mcp.actas.firmada');
    Route::get('{id}/carga', [McpActasController::class, 'abrirCarga'])->whereNumber('id')->name('mcp.actas.carga');
    Route::post('{id}/carga', [McpActasController::class, 'guardar'])->whereNumber('id')->middleware('throttle:10,1');
});
