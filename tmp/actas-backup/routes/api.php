<?php

use App\Http\Controllers\Mcp\McpHojasSalidaPdfController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


// ========== RUTAS PARA MARCAS ==========
use App\Http\Controllers\MarcaController;
use App\Http\Controllers\MarcaPendienteController;
use App\Http\Controllers\ModeloPendienteController;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TecnicoAppController;
use App\Http\Controllers\Api\OrionController;

use App\Http\Controllers\Mcp\BridgeController;
use App\Http\Controllers\Mcp\McpVentasController;
use App\Http\Controllers\Mcp\McpCotizacionesController;
use App\Http\Controllers\Mcp\McpCotizacionesPdfController;
use App\Http\Controllers\Mcp\McpPendientesController;
use App\Http\Controllers\Mcp\McpClientesController;
use App\Http\Controllers\Mcp\McpCatalogoProductosController;
use App\Http\Controllers\Mcp\McpCatalogoProductosEscrituraController;
use App\Http\Controllers\Mcp\McpCatalogoProductosArchivoController;
use App\Http\Controllers\Mcp\McpCatalogoProductosFotoController;
use App\Http\Controllers\Mcp\McpHojasSalidaController;
use App\Http\Controllers\Mcp\McpReportesController;
use App\Http\Controllers\Mcp\McpDetalleReportesController;
use App\Http\Controllers\Mcp\McpReporteTecnicoPdfController;
use App\Http\Controllers\Mcp\McpHistorialReportesController;
use App\Http\Controllers\Mcp\McpCertificacionesController;
use App\Http\Controllers\Mcp\McpCertificacionesEscrituraController;
use App\Http\Controllers\Mcp\McpCertificacionesArchivosController;
use App\Http\Controllers\Mcp\McpCertificacionesPdfController;
use App\Http\Controllers\Mcp\ComunicacionesSolicitudesController;
use App\Http\Controllers\Mcp\ComunicacionesConsultasController;
use App\Http\Controllers\Mcp\ComunicacionesConfirmacionRapidaController;
use App\Http\Controllers\Mcp\ComunicacionesTarjetaEnlaceController;
use App\Http\Controllers\Mcp\ComunicacionesContactosCrmController;
use App\Http\Controllers\Mcp\ComunicacionesVerificarDestinatarioCotizacionController;
use App\Http\Controllers\Mcp\ComunicacionesOperacionesController;
use App\Http\Controllers\Mcp\ComunicacionesImpresionController;



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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Ruta para obtener los instrumentos de un pendiente técnico (nueva versión)
Route::get('/instrumentos-pendiente-v2/{id}', function (Request $request, $id) {
    try {
        $tareaId = $request->input('tarea_id');
        $soloLibres = $request->input('solo_libres') == '1';

        // ================================================================
        // PRIORIDAD 1: tb_instrumento_servicios (pendientes con el sistema
        //              de múltiples servicios activado)
        // ================================================================
        $query = DB::table('tb_instrumento_servicios as s')
            ->join('tb_instrumento_pendiente as i', 'i.id', '=', 's.instrumento_id')
            ->where('i.pendiente_id', $id)
            ->select(
                'i.id',
                'i.tipo_instrumento',
                'i.marca',
                'i.modelo',
                'i.serie',
                'i.capacidad_rango',
                'i.resolucion_division',
                'i.clase_tipo_metrologico',
                'i.fecha_calibracion',
                'i.hora_servicio',
                'i.material',
                'i.observaciones',
                'i.tipo_servicio',
                'i.codigo_int',
                's.id as servicio_id',
                's.tarea_id',
                's.n_cotizacion',
                's.fecha_programada',
                's.hora_programada',
                's.sin_programacion',
                's.nombre_tecnico',
                's.estado_servicio'
            );

        if ($tareaId) {
            $query->where('s.tarea_id', $tareaId);
        }

        if ($soloLibres) {
            $query->whereNull('s.tarea_id');
        }

        $instrumentos = $query->get();

        // ================================================================
        // PRIORIDAD 2 (fallback): tb_instrumento_tarea — copia dedicada
        //              vinculada directamente a la tarea (incluye todos
        //              los instrumentos aunque no usen múltiples servicios)
        // ================================================================
        if ($instrumentos->isEmpty() && $tareaId) {
            $instrumentos = DB::table('tb_instrumento_tarea')
                ->where('tarea_id', $tareaId)
                ->select(
                    'id',
                    'tipo_instrumento',
                    'marca',
                    'modelo',
                    'serie',
                    'capacidad_rango',
                    'resolucion_division',
                    'clase_tipo_metrologico',
                    'fecha_calibracion',
                    'hora_servicio',
                    'material',
                    'observaciones',
                    'tipo_servicio',
                    'codigo_int',
                    DB::raw('NULL as servicio_id'),
                    'tarea_id',
                    DB::raw('NULL as n_cotizacion'),
                    DB::raw('NULL as fecha_programada'),
                    DB::raw('NULL as hora_programada'),
                    DB::raw('NULL as sin_programacion'),
                    DB::raw('NULL as nombre_tecnico'),
                    DB::raw('NULL as estado_servicio')
                )
                ->get();
        }

        // ================================================================
        // PRIORIDAD 3 (fallback final): tb_instrumento_pendiente — todos
        //              los instrumentos del pendiente sin filtro de tarea.
        //              Aplica cuando no hay servicios ni copia en tarea.
        // ================================================================
        if ($instrumentos->isEmpty()) {
            $instrumentos = DB::table('tb_instrumento_pendiente')
                ->where('pendiente_id', $id)
                ->select(
                    'id',
                    'tipo_instrumento',
                    'marca',
                    'modelo',
                    'serie',
                    'capacidad_rango',
                    'resolucion_division',
                    'clase_tipo_metrologico',
                    'fecha_calibracion',
                    'hora_servicio',
                    'material',
                    'observaciones',
                    'tipo_servicio',
                    'codigo_int',
                    DB::raw('NULL as servicio_id'),
                    DB::raw('NULL as tarea_id'),
                    DB::raw('NULL as n_cotizacion'),
                    DB::raw('NULL as fecha_programada'),
                    DB::raw('NULL as hora_programada'),
                    DB::raw('NULL as sin_programacion'),
                    DB::raw('NULL as nombre_tecnico'),
                    DB::raw('NULL as estado_servicio')
                )
                ->get();
        }

        // Agrupar por ID del instrumento (cada instrumento puede tener múltiples servicios)
        $instrumentosAgrupados = $instrumentos->groupBy('id');

        $instrumentosMapeados = $instrumentosAgrupados->map(function ($grupo, $instrumentoId) {
            $primer = $grupo->first();

            $servicios = $grupo->map(function ($inst) {
                return [
                    'servicio_id' => $inst->servicio_id ?? null,
                    'tarea_id' => $inst->tarea_id ?? null,
                    'tipo_servicio' => $inst->tipo_servicio ?? '',
                    'n_cotizacion' => $inst->n_cotizacion ?? null,
                    'fecha_programada' => $inst->fecha_programada ?? null,
                    'hora_programada' => $inst->hora_programada ?? null,
                    'sin_programacion' => $inst->sin_programacion ?? null,
                    'nombre_tecnico' => $inst->nombre_tecnico ?? null,
                    'estado_servicio' => $inst->estado_servicio ?? null,
                ];
            })->values();

            $tareasAsignadas = $servicios->pluck('tarea_id')->filter()->unique()->values()->all();

            return [
                'id' => $instrumentoId,
                'tipo' => $primer->tipo_instrumento ?? '',
                'marca' => $primer->marca ?? '',
                'modelo' => $primer->modelo ?? '',
                'serie' => $primer->serie ?? '',
                'codigo_int' => $primer->codigo_int ?? '',
                'capacidad' => $primer->capacidad_rango ?? '',
                'div_min' => $primer->resolucion_division ?? '',
                'fecha_cal' => $primer->fecha_calibracion ?? '',
                'hora_servicio' => $primer->hora_servicio ?? '',
                'material' => $primer->material ?? '',
                'clase_tipo_metrologico' => $primer->clase_tipo_metrologico ?? '',
                'observaciones' => $primer->observaciones ?? '',
                'tipo_servicio' => $primer->tipo_servicio ?? '',
                'tarea_id' => $servicios->pluck('tarea_id')->filter()->first(),
                'tareas_asignadas' => $tareasAsignadas,
                'servicios' => $servicios,
                'tecnico' => '',
            ];
        })->values()->all();

        return response()->json($instrumentosMapeados);

    } catch (\Exception $e) {
        Log::error('Error al obtener instrumentos del pendiente ' . $id . ': ' . $e->getMessage());
        return response()->json([
            'error' => 'Error al obtener instrumentos: ' . $e->getMessage()
        ], 500);
    }
});

// Ruta para guardar instrumentos de un pendiente técnico
Route::post('/instrumentos-pendiente/{id}', function (Request $request, $id) {
    try {
        // Validar que se reciban instrumentos
        $instrumentos = $request->input('instrumentos', []);

        if (empty($instrumentos)) {
            return response()->json([
                'error' => 'No se recibieron instrumentos para guardar'
            ], 400);
        }

        // Eliminar instrumentos existentes para este pendiente
        DB::table('tb_instrumento_pendiente')
            ->where('pendiente_id', $id)
            ->delete();

        // Insertar los nuevos instrumentos con todos los campos usando nombres correctos
        $instrumentosParaInsertar = [];
        foreach ($instrumentos as $instrumento) {
            $instrumentosParaInsertar[] = [
                'pendiente_id' => $id,
                'tipo_instrumento' => $instrumento['tipo'] ?? '',
                'marca' => $instrumento['marca'] ?? '',
                'modelo' => $instrumento['modelo'] ?? '',
                'serie' => $instrumento['serie'] ?? '',
                'capacidad_rango' => $instrumento['capacidad'] ?? '',
                'resolucion_division' => $instrumento['div_min'] ?? '',
                'clase_tipo_metrologico' => $instrumento['clase_tipo_metrologico'] ?? '',
                'fecha_calibracion' => $instrumento['fecha_cal'] ?? '',
                'hora_servicio' => $instrumento['hora_servicio'] ?? null,
                'material' => $instrumento['material'] ?? '',
                'observaciones' => $instrumento['observaciones_tecnicas'] ?? '',
                'tipo_servicio' => $instrumento['tipo_servicio'] ?? '',
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        // Insertar todos los instrumentos
        DB::table('tb_instrumento_pendiente')->insert($instrumentosParaInsertar);

        return response()->json([
            'success' => true,
            'message' => 'Instrumentos guardados exitosamente',
            'total_guardados' => count($instrumentosParaInsertar)
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Error al guardar instrumentos: ' . $e->getMessage()
        ], 500);
    }
});

// Obtener todas las marcas
Route::get('/marcas', [MarcaController::class, 'index']);

// Crear nueva marca
Route::post('/marcas', [MarcaController::class, 'store']);

// Buscar marcas (para autocomplete)
Route::get('/marcas/search', [MarcaController::class, 'search']);

// ========== RUTAS PARA MARCAS DE PENDIENTES ==========
// Obtener todas las marcas de pendientes
Route::get('/marcas-pendientes', [MarcaPendienteController::class, 'index']);

// Crear nueva marca de pendiente
Route::post('/marcas-pendientes', [MarcaPendienteController::class, 'store']);

// Buscar marcas de pendientes (para autocomplete)
Route::get('/marcas-pendientes/search', [MarcaPendienteController::class, 'search']);

// ========== RUTAS PARA MODELOS DE PENDIENTES ==========
// Obtener todos los modelos de pendientes
Route::get('/modelos-pendientes', [ModeloPendienteController::class, 'index']);

// Crear nuevo modelo de pendiente
Route::post('/modelos-pendientes', [ModeloPendienteController::class, 'store']);

// Buscar modelos de pendientes (para autocomplete)
Route::get('/modelos-pendientes/search', [ModeloPendienteController::class, 'search']);

// Rutas para Api de los Tecnicos

// Rutas públicas de la API para los técnicos
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas (El técnico debe estar logueado para acceder)

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->middleware(\App\Http\Middleware\EnsurePwaUser::class);

    // Ruta de prueba para verificar que la sesión sigue viva
    Route::get('/user', function (Request $request) {
        return $request->user();
    });


    Route::get('/tecnico/agenda-hoy', [TecnicoAppController::class, 'agendaHoy'])->middleware(\App\Http\Middleware\EnsurePwaUser::class);

    Route::post('/tecnico/sincronizar', [TecnicoAppController::class, 'sincronizar'])->middleware(\App\Http\Middleware\EnsurePwaUser::class);

    Route::post('/tecnico/sync-ficha', [TecnicoAppController::class, 'syncFicha'])->middleware(\App\Http\Middleware\EnsurePwaUser::class);
    Route::post('/tecnico/sync-fotos', [TecnicoAppController::class, 'syncFotos'])->middleware(\App\Http\Middleware\EnsurePwaUser::class);
    Route::get('/tecnico/ficha/{instrumentoTareaId}', [TecnicoAppController::class, 'getFicha'])->middleware(\App\Http\Middleware\EnsurePwaUser::class);
});


// Rutas para el MCP (Middleware RestrictMcpTokens)
Route::prefix('mcp-bridge')->group(function () {
    Route::post('authorize', [BridgeController::class, 'authorizeConnection'])
        ->middleware(['auth:sanctum', 'throttle:10,1']);
    Route::post('exchange', [BridgeController::class, 'exchange'])
        ->middleware('throttle:20,1');
});

Route::prefix('mcp')->middleware(['auth:sanctum', 'abilities:mcp', 'throttle:60,1'])->group(function () {
    require __DIR__.'/cotizaciones-desde-informe.php';
    Route::get('me', [BridgeController::class, 'me']);

    Route::prefix('ventas')->group(function () {
        Route::get('seguimientos', [McpVentasController::class, 'seguimientos']);
        Route::post('seguimientos', [McpVentasController::class, 'registrar'])->middleware('throttle:20,1');
        Route::get('resumen', [McpVentasController::class, 'resumen']);
        Route::get('catalogos', [McpVentasController::class, 'catalogos']);
        Route::get('clientes', [McpVentasController::class, 'clientes']);
        Route::get('clientes/filtros', [McpVentasController::class, 'filtrosClientes']);
        Route::get('clientes/{cliente}/contactos', [McpVentasController::class, 'contactos'])->whereNumber('cliente');
        Route::get('agenda', [McpVentasController::class, 'agenda']);
    });

    Route::prefix('ventas/cotizaciones')->group(function () {
        Route::get('filtros', [McpCotizacionesController::class, 'filtros']);
        Route::get('productos', [McpCotizacionesController::class, 'buscarProductos']);
        Route::get('plantillas', [McpCotizacionesController::class, 'plantillas']);

        // Cotización por venta
        Route::get('venta', [McpCotizacionesController::class, 'buscarVentas']);
        Route::post('venta', [McpCotizacionesController::class, 'registrarVenta'])->middleware('throttle:10,1');
        Route::get('venta/{id}', [McpCotizacionesController::class, 'verVenta'])->whereNumber('id');
        Route::post('venta/{id}/estado', [McpCotizacionesController::class, 'estadoVenta'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('venta/{id}/precios', [McpCotizacionesController::class, 'preciosVenta'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('venta/{id}/pdf', [McpCotizacionesPdfController::class, 'pdfVenta'])->whereNumber('id');

        // Cotización por servicio
        Route::get('servicio', [McpCotizacionesController::class, 'buscarServicios']);
        Route::post('servicio', [McpCotizacionesController::class, 'registrarServicio'])->middleware('throttle:10,1');
        Route::get('servicio/{id}', [McpCotizacionesController::class, 'verServicio'])->whereNumber('id');
        Route::post('servicio/{id}/estado', [McpCotizacionesController::class, 'estadoServicio'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('servicio/{id}/precios', [McpCotizacionesController::class, 'preciosServicio'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('servicio/{id}/trabajos', [McpCotizacionesController::class, 'trabajosServicio'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('servicio/{id}/caracteristicas', [McpCotizacionesController::class, 'caracteristicasServicio'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('servicio/{id}/condiciones', [McpCotizacionesController::class, 'condicionesServicio'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('servicio/{id}/pdf', [McpCotizacionesPdfController::class, 'pdfServicio'])->whereNumber('id');

    });

    Route::prefix('ventas/pendientes')->group(function () {
        Route::get('catalogos', [McpPendientesController::class, 'catalogos']);
        Route::get('cliente/{id}', [McpPendientesController::class, 'datosCliente'])->whereNumber('id');
        Route::get('cotizacion', [McpPendientesController::class, 'cotizacion']);
        Route::post('catalogo', [McpPendientesController::class, 'agregarCatalogo'])->middleware('throttle:20,1');

        Route::get('/', [McpPendientesController::class, 'buscar']);
        Route::post('/', [McpPendientesController::class, 'registrar'])->middleware('throttle:10,1');

        Route::get('{id}', [McpPendientesController::class, 'ver'])->whereNumber('id');
        Route::post('{id}/datos', [McpPendientesController::class, 'editarDatos'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('{id}/instrumentos', [McpPendientesController::class, 'editarInstrumentos'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('{id}/eliminar', [McpPendientesController::class, 'eliminar'])->whereNumber('id')->middleware('throttle:10,1');
    });

    Route::prefix('ventas/clientes')->group(function () {
        Route::get('catalogos', [McpClientesController::class, 'catalogos']);
        Route::get('documento', [McpClientesController::class, 'validarDocumento'])->middleware('throttle:20,1');
        Route::post('/', [McpClientesController::class, 'registrar'])->middleware('throttle:10,1');

        Route::get('{cliente}', [McpClientesController::class, 'ver'])->whereNumber('cliente');
        Route::post('{cliente}/editar', [McpClientesController::class, 'editar'])->whereNumber('cliente')->middleware('throttle:20,1');
        Route::post('{cliente}/liberar', [McpClientesController::class, 'liberar'])->whereNumber('cliente')->middleware('throttle:20,1');
        Route::post('{cliente}/asignar', [McpClientesController::class, 'asignar'])->whereNumber('cliente')->middleware('throttle:20,1');
    });

    Route::prefix('almacen/catalogo')->group(function () {
        Route::get('categorias', [McpCatalogoProductosController::class, 'categorias']);
        Route::post('categorias', [McpCatalogoProductosEscrituraController::class, 'registrarCategoria'])->middleware('throttle:10,1');
        Route::get('subcategorias', [McpCatalogoProductosController::class, 'subcategorias']);
        Route::post('subcategorias', [McpCatalogoProductosEscrituraController::class, 'registrarSubcategoria'])->middleware('throttle:10,1');
        Route::get('marcas', [McpCatalogoProductosController::class, 'marcas']);
        Route::post('marcas', [McpCatalogoProductosEscrituraController::class, 'registrarMarca'])->middleware('throttle:10,1');
        Route::get('proveedores', [McpCatalogoProductosController::class, 'proveedores']);
        Route::post('proveedores', [McpCatalogoProductosEscrituraController::class, 'registrarProveedor'])->middleware('throttle:10,1');
        Route::get('productos', [McpCatalogoProductosController::class, 'buscarProductos']);
        Route::post('productos', [McpCatalogoProductosEscrituraController::class, 'registrarProducto'])->middleware('throttle:10,1');
        Route::get('productos/archivados', [McpCatalogoProductosArchivoController::class, 'archivados']);
        Route::get('productos/{id}', [McpCatalogoProductosController::class, 'verProducto'])->whereNumber('id');
        Route::post('productos/{id}/ficha', [McpCatalogoProductosEscrituraController::class, 'editarFicha'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('productos/{id}/precios', [McpCatalogoProductosEscrituraController::class, 'editarPrecios'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('productos/{id}/foto/preparar', [McpCatalogoProductosFotoController::class, 'preparar'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('productos/{id}/archivar', [McpCatalogoProductosArchivoController::class, 'archivar'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('productos/{id}/restaurar', [McpCatalogoProductosArchivoController::class, 'restaurar'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('productos/{id}/clasificacion', [McpCatalogoProductosEscrituraController::class, 'editarClasificacion'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::prefix('servicio-tecnico/hojas-salida')->group(function () {
        Route::get('/', [McpHojasSalidaController::class, 'buscar']);
        // Rutas fijas antes que {id}:
        Route::get('resumen', [McpHojasSalidaController::class, 'resumen']);
        Route::get('{id}/instrumentos', [McpHojasSalidaController::class, 'instrumentos'])->whereNumber('id');
        Route::post('{id}/instrumentos/{instrumentoId}/datos', [McpHojasSalidaController::class, 'editarInstrumento'])->whereNumber(['id', 'instrumentoId'])->middleware('throttle:20,1');
        Route::get('{id}/pdf', [McpHojasSalidaPdfController::class, 'pdf'])->whereNumber('id');
        Route::get('{id}', [McpHojasSalidaController::class, 'ver'])->whereNumber('id');
    });

    Route::prefix('servicio-tecnico/reportes')->group(function () {
        Route::get('/', [McpReportesController::class, 'buscar']);
        Route::get('{id}', [McpReportesController::class, 'ver'])->whereNumber('id');
        Route::post('{id}/diagnosticos', [McpReportesController::class, 'diagnostico'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('{id}/resultados', [McpReportesController::class, 'resultado'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('{id}/caracteristicas', [McpReportesController::class, 'caracteristicas'])->whereNumber('id')->middleware('throttle:20,1');
    });

    // Historial: IDs de reporte y pendiente; independiente del registro por tarea.
    Route::prefix('servicio-tecnico/historial-reportes')->group(function () {
        Route::get('/', [McpHistorialReportesController::class, 'buscar']);
        Route::get('resumen', [McpHistorialReportesController::class, 'resumen']);
        Route::get('servicios/{id}', [McpHistorialReportesController::class, 'verServicio'])->whereNumber('id');
        Route::post('servicios/{id}/finalizar', [McpHistorialReportesController::class, 'finalizarServicio'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('{id}/instrumentos', [McpHistorialReportesController::class, 'instrumentos'])->whereNumber('id');
        Route::get('{id}', [McpHistorialReportesController::class, 'ver'])->whereNumber('id');
    });

    Route::prefix('comunicaciones')->group(function () {
        Route::post('envios/preparar', [ComunicacionesSolicitudesController::class, 'preparar'])
            ->middleware('throttle:10,1');
        Route::post('envios/{id}/confirmar', [ComunicacionesSolicitudesController::class, 'confirmar'])
            ->whereNumber('id')->middleware('throttle:10,1');
        Route::get('envios/{id}', [ComunicacionesSolicitudesController::class, 'estado'])
            ->whereNumber('id')->middleware('throttle:30,1');

        Route::get('correo/estado', [ComunicacionesConsultasController::class, 'correo'])
            ->middleware('throttle:30,1');
        Route::get('documentos/tipos', [ComunicacionesConsultasController::class, 'tipos'])
            ->middleware('throttle:30,1');
        Route::get('envios', [ComunicacionesConsultasController::class, 'historial'])
            ->middleware('throttle:30,1');

        Route::get('envios/{id}/revisar', [ComunicacionesConfirmacionRapidaController::class, 'revisar'])
            ->whereNumber('id')->middleware('throttle:20,1');
        Route::post('envios/{id}/confirmar-rapido', [ComunicacionesConfirmacionRapidaController::class, 'confirmar'])
            ->whereNumber('id')->middleware('throttle:balinsa-confirmar-nivel1');

        Route::post('envios/{id}/enlace-tarjeta', [ComunicacionesTarjetaEnlaceController::class, 'crear'])
            ->whereNumber('id')->middleware('throttle:5,1');

        Route::get(
            'contactos/clientes/{clienteId}',
            [ComunicacionesContactosCrmController::class, 'listar']
        )
            ->whereNumber('clienteId')->middleware('throttle:30,1');
        Route::post(
            'contactos/preparar',
            [ComunicacionesContactosCrmController::class, 'preparar']
        )
            ->middleware('throttle:20,1');
        Route::get(
            'contactos/altas/{id}/revisar',
            [ComunicacionesContactosCrmController::class, 'revisar']
        )
            ->whereNumber('id')->middleware('throttle:30,1');
        Route::post(
            'contactos/altas/{id}/confirmar',
            [ComunicacionesContactosCrmController::class, 'confirmar']
        )
            ->whereNumber('id')->middleware('throttle:20,1');

        Route::post(
            'destinatarios/cotizaciones/verificar',
            [ComunicacionesVerificarDestinatarioCotizacionController::class, 'verificar']
        )
            ->middleware('throttle:30,1');

        Route::get(
            'impresoras',
            [ComunicacionesImpresionController::class, 'impresoras']
        )
            ->middleware('throttle:30,1');
        Route::post(
            'impresiones',
            [ComunicacionesImpresionController::class, 'solicitar']
        )
            ->middleware('throttle:12,1');
        Route::get(
            'impresiones/{id}',
            [ComunicacionesImpresionController::class, 'estado']
        )
            ->whereNumber('id')->middleware('throttle:30,1');

        Route::get(
            'operaciones',
            [ComunicacionesOperacionesController::class, 'index']
        )
            ->middleware('throttle:30,1');

    });



    Route::prefix('servicio-tecnico/detalle-reportes')->group(function () {
        Route::get('{id}/acciones', [McpDetalleReportesController::class, 'acciones'])->whereNumber('id');
        Route::post('{id}/finalizar', [McpDetalleReportesController::class, 'finalizar'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('{id}/instrumentos/{instrumentoId}/ficha', [McpDetalleReportesController::class, 'instrumento'])->whereNumber(['id', 'instrumentoId'])->middleware('throttle:20,1');
        Route::post('{id}/instrumentos/{instrumentoId}/informe', [McpDetalleReportesController::class, 'informe'])->whereNumber(['id', 'instrumentoId'])->middleware('throttle:20,1');
        Route::post('{id}/instrumentos/{instrumentoId}/resultado', [McpDetalleReportesController::class, 'resultado'])->whereNumber(['id', 'instrumentoId'])->middleware('throttle:20,1');
        Route::get('{id}/instrumentos/{instrumentoId}/pdf', [McpReporteTecnicoPdfController::class, 'pdf'])->whereNumber(['id', 'instrumentoId']);
    });

    Route::prefix('certificaciones')->group(function () {
        Route::get('clientes', [McpCertificacionesController::class, 'clientes']);
        Route::get('solicitudes/{id}/instrumentos/{instrumentoId}/series', [McpCertificacionesController::class, 'series'])->whereNumber('id')->whereNumber('instrumentoId');
        Route::post('solicitudes/prevalidar', [McpCertificacionesEscrituraController::class, 'prevalidar'])->middleware('throttle:20,1');
        Route::get('solicitudes', [McpCertificacionesController::class, 'buscar']);
        Route::get('certificados', [McpCertificacionesController::class, 'certificados']);
        Route::get('por-vencer', [McpCertificacionesController::class, 'porVencer']);
        Route::get('filtros', [McpCertificacionesController::class, 'filtros']);
        Route::get('catalogos', [McpCertificacionesController::class, 'catalogos']);
        Route::get('solicitudes/{id}', [McpCertificacionesController::class, 'ver'])->whereNumber('id');
        Route::get('solicitudes/{id}/instrumentos', [McpCertificacionesController::class, 'instrumentos'])->whereNumber('id');
        Route::get('solicitudes/{id}/archivos', [McpCertificacionesController::class, 'archivos'])->whereNumber('id');
        Route::post('solicitudes', [McpCertificacionesEscrituraController::class, 'registrar'])->middleware('throttle:10,1');
        Route::post('solicitudes/{id}/fechas', [McpCertificacionesEscrituraController::class, 'editarFechas'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('solicitudes/{id}/renovar', [McpCertificacionesEscrituraController::class, 'renovar'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('solicitudes/{id}/eliminar', [McpCertificacionesEscrituraController::class, 'eliminar'])->whereNumber('id')->middleware('throttle:10,1');
        Route::get('clientes/{id}/contactos', [McpCertificacionesEscrituraController::class, 'contactos'])->whereNumber('id')->middleware('throttle:60,1');
        Route::post('clientes/{id}/contactos', [McpCertificacionesEscrituraController::class, 'registrarContacto'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('catalogos', [McpCertificacionesEscrituraController::class, 'agregarCatalogo'])->middleware('throttle:10,1');
        Route::post('solicitudes/{id}/subida', [McpCertificacionesArchivosController::class, 'preparar'])->whereNumber('id')->middleware('throttle:10,1');
        Route::get('solicitudes/{id}/archivos/{archivoId}/enlace', [McpCertificacionesArchivosController::class, 'enlaceArchivo'])->whereNumber('id')->whereNumber('archivoId')->middleware('throttle:20,1');
        Route::get('solicitudes/{id}/pdf', [McpCertificacionesPdfController::class, 'prepararPdf'])->whereNumber('id')->middleware('throttle:20,1');

    });


});






