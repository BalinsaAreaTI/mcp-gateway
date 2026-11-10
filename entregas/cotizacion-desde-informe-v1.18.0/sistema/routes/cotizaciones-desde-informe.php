<?php

// Cargar dentro del grupo existente /api/mcp con su autenticación y restricciones MCP.
use App\Http\Controllers\Mcp\McpCotizacionesController;
use Illuminate\Support\Facades\Route;

Route::get('ventas/cotizaciones/informes', [McpCotizacionesController::class, 'buscarInformesCotizables']);
Route::get('ventas/cotizaciones/informes/{id}', [McpCotizacionesController::class, 'verInformeCotizable'])->whereNumber('id');
Route::post('ventas/cotizaciones/servicio/desde-informe', [McpCotizacionesController::class, 'registrarServicioDesdeInforme'])->middleware('throttle:10,1');
