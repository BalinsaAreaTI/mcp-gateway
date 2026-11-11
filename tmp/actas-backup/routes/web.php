<?php

use App\Http\Controllers\Mcp\McpHojasSalidaPdfController;

use App\Http\Controllers\TbproveedorController;
use App\Http\Controllers\ActaConformidadController;
use App\Http\Controllers\CajaChicaController;
use App\Http\Controllers\RegistroLlamadaController;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\CarritoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\SalidasController;
use App\Http\Controllers\EntradaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CondicioneController;
use App\Http\Controllers\CServicioController;
use App\Http\Controllers\CventaController;
use App\Http\Controllers\DatoController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\HmarcaController;
use App\Http\Controllers\PendientesController;
use App\Http\Controllers\MaterialPendienteController;
use App\Http\Controllers\HservicioController;
use App\Http\Controllers\FichaTecnicaController;
use App\Http\Controllers\ObservacioneController;
use App\Http\Controllers\PendientesTecnicosController;
use App\Http\Controllers\PermisoController;
use App\Http\Controllers\PlantillaController;
use App\Http\Controllers\RecomendacioneController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\TbcategoriaController;
use App\Http\Controllers\TbmarcaController;
use App\Http\Controllers\TbproductoController;
use App\Http\Controllers\TbsubcategoriaController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SidebarController;
use App\Http\Controllers\SolicitudCertificacionController;
use App\Http\Controllers\TbprovinciaController;
use App\Http\Controllers\TrealizadoController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\ReportesController;
use App\Http\Controllers\InteraccionesController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AuthorizationCodeController;
use App\Http\Controllers\RecepInstrumentosController;
use App\Http\Controllers\RuleSettingController;
use App\Http\Controllers\ControlServiciosController;
use App\Http\Controllers\TareasOperacionesController;
use App\Http\Controllers\CserviciosController;
use App\Http\Controllers\DiagnosticoController;
use App\Http\Controllers\InformeController;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\HerramientasPersonalController;
use App\Http\Controllers\CajaHerramientaController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ConocimientoController;

use App\Http\Controllers\Mcp\McpCotizacionesPdfController;
use App\Http\Controllers\Mcp\McpCatalogoProductosFotoController;
use App\Http\Controllers\Mcp\McpCertificacionesArchivosController;
use App\Http\Controllers\Mcp\McpCertificacionesPdfController;
use App\Http\Controllers\Mcp\McpReporteTecnicoPdfController;

use App\Http\Controllers\Comunicaciones\CorreoCorporativoController;
use App\Http\Controllers\Comunicaciones\AprobacionEnvioController;
use App\Http\Controllers\Web\ComunicacionesTarjetaWebController;

use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

Route::prefix('estado-servicios')->name('estado-servicios.')->controller(\App\Http\Controllers\EstadoServiciosController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/clientes', 'clientes')->name('clientes');
    Route::get('/historial', 'historial')->name('historial');
});

//Rutas no autenticadas
Route::redirect('/', '/login');
// Ruta pública para descarga de SCTR
Route::get('/configuracion/sctr/descargar', [ConfiguracionController::class, 'descargarSCTR'])->name('configuracion.sctr.descargar');
Route::get('/configuracion/sctr/check', [ConfiguracionController::class, 'checkSCTR'])->name('configuracion.sctr.check');
Route::get('/configuracion/asesor/nombre-completo', [ConfiguracionController::class, 'getNombreCompletoAsesor'])->name('configuracion.asesor.nombre');

// Ruta pública — Vista previa del PDF unificado (SCTR + docs vehículo + docs chofer) para qr en Hoja de Salida
Route::get('/movilidad/documentos-unificados/preview', [\App\Http\Controllers\MovilidadController::class, 'previewDocumentoUnificado'])->name('movilidad.documentos-unificados.preview');

// Ruta pública — PDF unificado (SCTR + docs vehículo + docs chofer) usado internamente por la vista previa
Route::get('/movilidad/documentos-unificados', [\App\Http\Controllers\MovilidadController::class, 'generarDocumentoUnificado'])->name('movilidad.documentos-unificados');


// Rutas públicas de herramientas
Route::get('/checklist/{idCaja}', [HerramientasPersonalController::class, 'publicChecklist'])->name('public.checklist');
Route::get('/obtenerHerramientasCaja/{id}', [HerramientasPersonalController::class, 'obtenerHerramientasUsuario']);
Route::get('/herramientas-personal/imagen-publica/{nombreArchivo}', [HerramientasPersonalController::class, 'verImagenHerramienta'])->name('public.herramientas.imagen');

// Ruta pública — Escaneo de QR de recepción de instrumentos
Route::get('/r/{id}', [RecepInstrumentosController::class, 'verQr'])->name('recep-instrumentos.qr-publico');
// Ruta pública — QR preview (sin registro en BD, datos encriptados en el token)
Route::get('/rp/{token}', [RecepInstrumentosController::class, 'verQrPreview'])->name('recep-instrumentos.qr-preview');

// Rutas públicas de Ficha Técnica
Route::get('/ficha-tecnica/tarea/{token_tarea}', [App\Http\Controllers\FichaTecnicaController::class, 'listadoInstrumentosPublico'])->name('ficha-tecnica.listado-publico');
Route::get('/ficha-tecnica/formulario/{token_ficha}', [App\Http\Controllers\FichaTecnicaController::class, 'formularioPublico'])->name('ficha-tecnica.formulario-publico');
Route::post('/ficha-tecnica/formulario/{token_ficha}', [App\Http\Controllers\FichaTecnicaController::class, 'guardarFichaPublica'])->name('ficha-tecnica.guardar-publico');

Route::middleware(['auth:web'])->group(function () {
    //rutas autenticadas
    Route::get('/dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard-datos', [DashboardController::class, 'obtenerDatos'])->name('dashboard.datos');
    Route::get('/dashboard-codigo', [DashboardController::class, 'isCodigoAutorizacion'])->name('dashboard.iscodigo');

    Route::prefix('actas-firmadas')->name('actas-firmadas.')->controller(\App\Http\Controllers\ActaFirmadaController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/listar', 'listar')->name('listar');
        Route::post('/{id}', 'guardar')->name('guardar');
        Route::delete('/{id}', 'eliminar')->name('eliminar');
    });

    //Acta de Conformidad
    Route::get('/actasConformidad/validar-cotizacion', [ActaConformidadController::class, 'validarCotizacion'])->name('actasConformidad.validarCotizacion');
    Route::get('/actasConformidad/instrumentos-por-tarea', [ActaConformidadController::class, 'obtenerInstrumentosPorTarea'])->name('actasConformidad.instrumentosPorTarea');
    Route::patch('/actasConformidad/{id}/reprogramar', [ActaConformidadController::class, 'reprogramar'])->name('actasConformidad.reprogramar');
    Route::post('/actasConformidad/{id}/actualizar-estado-firma', [ActaConformidadController::class, 'actualizarEstadoPorFoto'])->name('actasConformidad.actualizarEstadoPorFoto');
    Route::resource('/actasConformidad', ActaConformidadController::class);
    Route::get('/fn_obtenerListadeTecnicosActa', [ActaConformidadController::class, 'obtenerListadeTecnicosActa']);
    Route::get('/fn_obtenerListadeActaClientesPdf', [ActaConformidadController::class, 'obtenerListadeActaClientesPdf']);
    Route::get('/fn_obtenerDatosdeActaClientes', [ActaConformidadController::class, 'obtenerDatosdeActaClientes']);
    Route::get('/fn_obtenerListadeActaClientes', [ActaConformidadController::class, 'obtenerListadeActaClientes']);

    Route::patch('/certificados/{id}/editar-fechas', [SolicitudCertificacionController::class, 'editarFechas'])->name('certificados.editarFechas');

    //Solicitud de Certificaciones
    Route::resource('/solicitudCertificacion', SolicitudCertificacionController::class)->except(['show']);
    Route::get('/solicitudCertificacion/certificados', [SolicitudCertificacionController::class, 'vistaCertificados'])
        ->name('solicitudCertificacion.certificados');

    Route::get('/fn_consultarSolicitudesCertificacion', [SolicitudCertificacionController::class, 'consultarSolicitudes']);
    Route::get('/fn_detalleSolicitudCertificacion', [SolicitudCertificacionController::class, 'detalleSolicitudCertificacion']);
    Route::get('/fn_listarArchivosCertificado', [SolicitudCertificacionController::class, 'listarArchivosCertificado']);
    Route::post('/fn_subirArchivosCertificado', [SolicitudCertificacionController::class, 'subirArchivosCertificado']);
    Route::get('/fn_verArchivoCertificado/{id}', [SolicitudCertificacionController::class, 'verArchivoCertificado']);
    Route::get('/fn_consultarSolicitudesConEstado', [SolicitudCertificacionController::class, 'consultarSolicitudesConEstado']);
    Route::get('/fn_contactosPorCliente', [SolicitudCertificacionController::class, 'obtenerContactosPorCliente']);
    // routes/web.php
    Route::get('/fn_capacidadEscala', [SolicitudCertificacionController::class, 'obtenerCapacidadEscala']);
    Route::post('/fn_guardarCapacidadEscala', [SolicitudCertificacionController::class, 'guardarCapacidadEscala']);
    Route::get('/fn_obtenerEscalasCertificacion', [SolicitudCertificacionController::class, 'obtenerEscalasCertificacion']);


    Route::get('/certificaciones/por-caducar', function () {
        $usuario = \Illuminate\Support\Facades\Auth::user();
        $canAccess = !$usuario->hasRole('Servicio Tecnico') && !$usuario->hasRole('Area de Almacen');

        return Inertia::render('SolicitudCertificacion/PorVencer', [
            'canAccess' => $canAccess,
        ]);
    })->name('solicitudCertificacion.porCaducar');

    // ========== RUTAS PARA MANEJO DE ESTADOS DE TAREAS ==========
    Route::middleware(['restringir_horario_servicio'])->group(function () {
        Route::post('/pendientestecnicos/{id}/marcar-completada', [PendientesTecnicosController::class, 'marcarComoCompletada'])->name('pendientestecnicos.marcarCompletada');
        Route::post('/pendientestecnicos/{id}/cambiar-estado', [PendientesTecnicosController::class, 'cambiarEstado'])->name('pendientestecnicos.cambiarEstado');
    });

    // Modulo de los clientes - estado

    Route::resource('/clientes', ClienteController::class);
    Route::post('/clientes/update-ctg', [ClienteController::class, 'updateCtg'])->name('clientes.updateCtg');
    Route::post('/clientes/liberar-masivo', [ClienteController::class, 'liberarClientesMasivo'])->name('clientes.liberarMasivo');
    Route::get('/fn_obtenerAsesoresVentas', [ClienteController::class, 'obtenerAsesoresVentas'])->name('clientes.obtenerAsesoresVentas');



    Route::get('/ingresados', [ClienteController::class, 'ingresados'])->name('clientes-ingresados.index');
    Route::post('/ObtenerClientesIngresados', [ClienteController::class, 'ObtenerClientesIngresados']);

    // === Catálogos para selects ===
    Route::get('/fn_procedenciaBalanza', [SolicitudCertificacionController::class, 'obtenerProcedenciaBalanza']);
    Route::post('/fn_guardarProcedenciaBalanza', [SolicitudCertificacionController::class, 'guardarProcedenciaBalanza']);

    Route::get('/fn_procedenciaTermometro', [SolicitudCertificacionController::class, 'obtenerProcedenciaTermometro']);
    Route::post('/fn_guardarProcedenciaTermometro', [SolicitudCertificacionController::class, 'guardarProcedenciaTermometro']);

    Route::get('/fn_valoresNominalesPesa', [SolicitudCertificacionController::class, 'obtenerValoresNominalesPesa']);
    Route::post('/fn_guardarValorNominalPesa', [SolicitudCertificacionController::class, 'guardarValorNominalPesa']);

    Route::get('/fn_clasesExactitudPesa', [SolicitudCertificacionController::class, 'obtenerClasesExactitudPesa']);
    Route::post('/fn_guardarClaseExactitudPesa', [SolicitudCertificacionController::class, 'guardarClaseExactitudPesa']);

    Route::get('/fn_elementosSensorTermometro', [SolicitudCertificacionController::class, 'obtenerElementosSensorTermometro']);
    Route::post('/fn_guardarElementoSensorTermometro', [SolicitudCertificacionController::class, 'guardarElementoSensorTermometro']);

    // routes/web.php
    Route::post('/solicitudes/renovar', [SolicitudCertificacionController::class, 'renovar'])
        ->name('solicitudes.renovar');

    // si aún no tienes un endpoint “rico” para la lista, expón uno:
    Route::get('/api/solicitudes', [SolicitudCertificacionController::class, 'consultarSolicitudes'])
        ->name('api.solicitudes');

    Route::resource('/cajaChica', CajaChicaController::class);

    Route::resource('/pendientestecnicos', PendientesTecnicosController::class)->middleware('restringir_horario_servicio');

    Route::get('/hojas-salida', function () {
        if (!\Illuminate\Support\Facades\Auth::user()->can('gestionar-operaciones')) {
            abort(403);
        }
        $canAccess = true;
        return Inertia::render('HojasSalida/Index', compact('canAccess'));
    })->middleware('can:gestionar-operaciones')->name('hojas-salida.index');

    // Recepción de Instrumentos
    Route::get('/recep-instrumentos', [RecepInstrumentosController::class, 'index'])
        ->name('recep-instrumentos.index');
    Route::get('/recep-instrumentos/create', [RecepInstrumentosController::class, 'create'])
        ->name('recep-instrumentos.create');
    Route::post('/recep-instrumentos', [RecepInstrumentosController::class, 'store'])
        ->name('recep-instrumentos.store');
    Route::get('/recep-instrumentos/{instrumento}/edit', [RecepInstrumentosController::class, 'edit'])
        ->name('recep-instrumentos.edit');
    Route::put('/recep-instrumentos/{instrumento}', [RecepInstrumentosController::class, 'update'])
        ->name('recep-instrumentos.update');
    Route::get('/fn_clientes-recep-instrumentos', [RecepInstrumentosController::class, 'obtenerClientes'])
        ->name('recep-instrumentos.clientes');
    Route::post('/recep-instrumentos/imprimir-tickets', [RecepInstrumentosController::class, 'imprimirTickets'])
        ->name('recep-instrumentos.imprimir-tickets');
    Route::post('/recep-instrumentos/imprimir-previo', [RecepInstrumentosController::class, 'imprimirPrevio'])
        ->name('recep-instrumentos.imprimir-previo');
    Route::post('/recep-instrumentos/imprimir-qr-preview', [RecepInstrumentosController::class, 'imprimirQrPreview'])
        ->name('recep-instrumentos.imprimir-qr-preview');
    Route::post('/recep-instrumentos/{id}/devolver', [RecepInstrumentosController::class, 'devolver'])
        ->name('recep-instrumentos.devolver');
    Route::post('/recep-instrumentos/{instrumento}/subir-evidencia', [RecepInstrumentosController::class, 'subirEvidencia'])
        ->name('recep-instrumentos.subir-evidencia');
    Route::post('/recep-instrumentos/clases-exactitud', [RecepInstrumentosController::class, 'guardarClaseExactitud'])
        ->name('recep-instrumentos.clases-exactitud.store');


    Route::get('/gen_code', function () {
        return Inertia::render('CodigoAutorizacion/index');
    })->name('gen_code');

    // Inventario de productos
    Route::resource('/categories', CategoryController::class);
    Route::resource('/productos', ProductoController::class);
    route::post('/producto/{producto}', [ProductoController::class, 'update'])->name('producto.update');
    Route::resource('/salidas', SalidasController::class);
    Route::resource('/entradas', EntradaController::class);
    Route::post('/comprobarSalida', [SalidasController::class, 'comprobarSalida']);
    Route::post('/validarstock', [SalidasController::class, 'validarstock']);

    Route::put('/salidas.update', [SalidasController::class, 'update']);

    // Hojas de Servicio - Restricción horaria: 00:00 - 17:00
    // Route::resource('pendientesvendedor', PendientesController::class)->middleware('restringir_horario_servicio:0,23');
    Route::resource('pendientesvendedor', PendientesController::class);

    // ========== NUEVA RUTA API PARA OBTENER INSTRUMENTOS DE UN PENDIENTE ==========
    // PROPÓSITO: Permite obtener las características detalladas de los instrumentos de un pendiente específico
    // MÉTODO: GET
    // RUTA: /api/pendientes/{id}/instrumentos
    // USO: Se llama desde el frontend cuando se edita un pendiente para cargar los instrumentos existentes
    // FUNCIONALIDAD: Permite que los datos se muestren en la tabla de la tercera hoja del PDF
    Route::get('/api/pendientes/{id}/instrumentos', [PendientesController::class, 'getInstrumentos']);

    // ========== NUEVA RUTA API PARA ACTUALIZAR UN INSTRUMENTO INDIVIDUAL ==========
    // PROPÓSITO: Permite actualizar las características de un instrumento específico en el modal de detalles
    // MÉTODO: PUT
    // RUTA: /api/instrumentos-pendiente/{id}
    // USO: Se llama desde el frontend cuando se edita un instrumento en el modal de detalles del pendiente
    // FUNCIONALIDAD: Permite editar marca, modelo, serie, capacidad, etc. de instrumentos individuales
    Route::put('/api/instrumentos-pendiente/{id}', [PendientesController::class, 'updateInstrumento']);

    // Catalago de productos
    Route::resource('/tbproductos', TbproductoController::class);
    Route::resource('/tbmarcas', TbmarcaController::class);
    Route::resource('/tbcategorias', TbcategoriaController::class);
    Route::resource('/tbsubcategorias', TbsubcategoriaController::class);
    Route::resource('/sidebar', SidebarController::class);
    Route::post('/tbproveedores', [TbproveedorController::class, 'store'])->name('tbproveedores.store');
    Route::get('/fn_obtenerProveedoresRegistrados', [TbproveedorController::class, 'obtenerRegistrados']);

    // Usuarios - roles - permisos - restore
    Route::get('trashed_users', [UserController::class, 'trashed_users'])->name('users.trashed');
    Route::get('restore_users/{id}', [UserController::class, 'restore'])->name('users.restore');
    Route::delete('delete_users_deletePermanently/{id}', [UserController::class, 'deletePermanently'])->name('users.deletePermanently');
    Route::post('/user/update-photo', [UserController::class, 'updateProfilePhoto'])->name('user.update-photo');
    Route::delete('/user/delete-photo', [UserController::class, 'deleteProfilePhoto'])->name('user.delete-photo');

    // Cotizaciones por Servicio
    Route::get('/c_servicos/directa', [CServicioController::class, 'directa'])->name('c_servicos.directa');
    Route::get('/c_servicos/cotizaqciones', [CServicioController::class, 'getCotizacionesServicio'])->name('c_servicos.cotizaciones');
    Route::get('/c_servicos/{id}/datos', [CServicioController::class, 'getById'])->name('c_servicos.getById');
    Route::resource('c_servicos', CServicioController::class);
    Route::post('/cservicios/cambiar_estado', [CServicioController::class, 'cambiarEstado'])->name('cservicios.cambiar_estado');
    Route::post('/cservicios/actualizar-precios', [CServicioController::class, 'actualizarPrecios'])->name('cservicios.actualizar_precios');
    Route::post('/cservicios/actualizar-trabajos', [CServicioController::class, 'actualizarTrabajos'])->name('cservicios.actualizar_trabajos');
    Route::post('/cservicios/actualizar-caracteristicas', [CServicioController::class, 'actualizarCaracteristicas'])->name('cservicios.actualizar_caracteristicas');
    Route::post('/cservicios/actualizar-condiciones-servicio', [CServicioController::class, 'actualizarCondicionesServicio'])->name('cservicios.actualizar_condiciones_servicio');
    Route::post('/cservicios/actualizar-fecha', [CServicioController::class, 'actualizarFecha'])->name('cservicios.actualizar_fecha');
    Route::patch('/cservicios/{cservicio}/condiciones-comerciales', [\App\Http\Controllers\CondicionesComercialesController::class, 'servicio'])->name('cservicios.condiciones_comerciales');
    Route::post('/consultarDatosProductosCotServ', [CServicioController::class, 'consultarDatosProductosCotServ']);
    Route::get('/api/busqueda-global-cotizaciones', [CServicioController::class, 'busquedaGlobalCotizaciones'])->name('busqueda.global.cotizaciones');
    Route::post('/cservicios/codigoSunat', [CServicioController::class, 'codigoSunat'])->name('cservicios.codigoSunat');
    Route::get('/balanzas', [CServicioController::class, 'balanza'])->name('balanza');
    Route::get('requiere/{id}', [CServicioController::class, 'cotiza'])->name('requiere.cotiza');
    Route::resource('plantillas', PlantillaController::class);
    Route::resource('trealizados', TrealizadoController::class);
    Route::resource('condiciones', CondicioneController::class);
    Route::resource('observaciones', ObservacioneController::class);
    Route::resource('documentos', DocumentoController::class);
    Route::resource('recomendaciones', RecomendacioneController::class);

    //Consulta para consumir la api de sunat para el modulo de clientes
    Route::get('/consultar-reniec', function (Request $request) {
        $numeroDocumento = $request->query('numero');

        Log::info('Consultando RENIEC/SUNAT', ['numero' => $numeroDocumento]);

        if (!$numeroDocumento || strlen($numeroDocumento) != 11) {
            Log::warning('RUC inválido', ['numero' => $numeroDocumento]);
            return response()->json(['error' => 'RUC inválido'], 400);
        }

        $token = 'apis-token-10192.lgbp44SaTMi8tlleXcrrFYn79hAwerEU';

        try {
            Log::info('Consultando API externa...');

            // CORRECCIÓN: Deshabilitar verificación SSL para evitar error de certificados en desarrollo
            $response = Http::timeout(10)
                ->withoutVerifying() // Deshabilita verificación SSL
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token
                ])->get('https://api.apis.net.pe/v2/sunat/ruc', [
                        'numero' => $numeroDocumento
                    ]);

            Log::info('Respuesta de API', [
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                Log::info('Datos obtenidos correctamente');
                return $response->json();
            } else {
                Log::warning('API respondió con error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json(['error' => 'No se encontró información'], 404);
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Error de conexión con API SUNAT', [
                'message' => $e->getMessage(),
                'numero' => $numeroDocumento
            ]);
            return response()->json(['error' => 'No se pudo conectar con el servicio de consulta. Intenta nuevamente.'], 500);
        } catch (\Exception $e) {
            Log::error('Error consultando API SUNAT', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'numero' => $numeroDocumento
            ]);
            return response()->json(['error' => 'Error al consultar la API: ' . $e->getMessage()], 500);
        }
    });

    // Modulo de clientes - datos - estado
    Route::resource('datos', DatoController::class);
    Route::resource('/tbprovincias', TbprovinciaController::class);

    // Cotizaciones por venta
    Route::resource('/cventas', CventaController::class);
    Route::post('/guardarProductosCotizacion', [CventaController::class, 'guardarProductosCotizacion'])->name('cventas.guardarProductosCotizacion');
    Route::post('/consultarDatosCot', [CventaController::class, 'consultarDatosCot']);
    Route::post('/consultarDatosProductosCot', [CventaController::class, 'consultarDatosProductosCot']);
    Route::post('/cventas/cambiar_estado', [CventaController::class, 'cambiarEstado'])->name('cventas.cambiar_estado');
    Route::post('/cventas/actualizar-precios', [CventaController::class, 'actualizarPrecios'])->name('cventas.actualizar_precios');
    Route::post('/cventas/actualizar-fecha', [CventaController::class, 'actualizarFecha'])->name('cventas.actualizar_fecha');
    Route::patch('/cventas/{cventa}/condiciones-comerciales', [\App\Http\Controllers\CondicionesComercialesController::class, 'venta'])->name('cventas.condiciones_comerciales');

    // Hojas de Salida de los servicios
    // Route::resource('hservicios', HservicioController::class);
    Route::resource('hmarcas', HmarcaController::class);
    Route::resource('servicios', ServicioController::class);
    Route::post('/servicios/cambiar_estado', [ServicioController::class, 'cambiarEstado'])->name('servicios.cambiar_estado');


    // Nuevas rutas para el nuevo componente de Pendientes tecnicos
    Route::prefix('command-center')->group(function () {
        Route::get('/', [TareasOperacionesController::class, 'index'])->name('command-center.index');

        // Lectura
        Route::get('/indicadores', [TareasOperacionesController::class, 'getIndicadores'])->name('command.indicadores');
        Route::get('/bandeja', [TareasOperacionesController::class, 'getBandejaEntrada'])->name('command.bandeja');
        Route::get('/tareas', [TareasOperacionesController::class, 'getTareasProgramadas'])->name('command.tareas');

        // Escritura
        Route::post('/tareas', [TareasOperacionesController::class, 'store']);
        Route::put('/tareas/{id}', [TareasOperacionesController::class, 'update']);
        Route::delete('/tareas/{id}', [TareasOperacionesController::class, 'destroy']);
        Route::post('/tareas/{id}/reprogramar', [TareasOperacionesController::class, 'reprogramar']);

        Route::get('/pendientes/{id}/instrumentos', [TareasOperacionesController::class, 'getInstrumentosPendiente']);
        Route::get('/tareas/{id}/detalles', [TareasOperacionesController::class, 'getDetalleTarea']);
        Route::get('/tareas/{id}/detalle-ventana', [TareasOperacionesController::class, 'showDetalleVentana'])->name('tareas.detalle-ventana');

        Route::post('/tareas/{id}/forzar-estado', [TareasOperacionesController::class, 'forzarEstado']);

    });


    // Carrito de Compras
    Route::resource('carritos', CarritoController::class);

    Route::get('/inventario', [InventarioController::class, 'index'])->name('inventario.index');

    // registro de llamadas-area de ventas
    // IMPORTANTE: Las rutas específicas deben ir ANTES del resource para evitar conflictos
    Route::get('registro-llamadas/obtenerContactos/{id}', [RegistroLlamadaController::class, 'getContactocliente'])->name('registro-llamadas.obtenerContactos');
    Route::get('registro-llamadas/cotizaciones/{id}', [RegistroLlamadaController::class, 'getCotizacionesCliente'])->name('registro-llamadas.cotizaciones');
    Route::get('registro-llamadas/verificar-cliente/{clienteId}', [RegistroLlamadaController::class, 'verificarClienteAsignado'])->name('registro-llamadas.verificarCliente');
    Route::get('registro-llamadas/agenda', [RegistroLlamadaController::class, 'getAgenda'])->name('registro-llamadas.agenda');
    Route::get('registro-llamadas/agenda-view', [RegistroLlamadaController::class, 'agendaView'])->name('registro-llamadas.agenda-view');
    Route::post('registro-llamadas/{id}/conclusion', [RegistroLlamadaController::class, 'updateConclusion'])->name('registro-llamadas.updateConclusion');
    Route::get('/registro-llamadas/datos', [RegistroLlamadaController::class, 'obtenerDatos'])->name('registro-llamadas.datos');
    Route::resource('registro-llamadas', RegistroLlamadaController::class)->only(['index', 'create', 'store']);


    Route::get('/dashboard-operaciones/kpis', [ControlServiciosController::class, 'obtenerKPIs'])
        ->name('control-servicios.kpis');

    Route::get('/dashboard-operaciones/grafico', [ControlServiciosController::class, 'obtenerDatosGrafico'])
        ->name('control-servicios.grafico');



    // Codigos de autorización para acciones sensibles
    Route::post('/authorization-codes/generate', [AuthorizationCodeController::class, 'generate'])
        ->middleware(['auth:sanctum', 'role:Administracion,Gerencia'])
        ->name('gen_code.genrar');

    // Ruta para consumir/validar un código para una acción
    Route::post('/authorization-codes/consume', [AuthorizationCodeController::class, 'consumeCode'])->name('autorizacion.canjear');
});

Route::middleware(['auth:web'])->group(function () {
    Route::resource('/users', UserController::class);
    Route::resource('roles', RoleController::class);
    Route::resource('permisos', PermisoController::class);
});

Route::middleware(['auth:web', 'role:Administracion,Gerencia'])->group(function () {

    Route::get('/configuraciones', function () {
        return Inertia::render('Configuraciones/Index');
    })->name('configuraciones.index');

    Route::get('/control-servicios', function () {
        return Inertia::render('ControlServicios/Index');
    })->name('control-servicios.index');

    // Rutas CRUD para Movilidades (Vehículos y Choferes) — solo Gerencia
    Route::prefix('configuraciones/movilidades')->name('movilidades.')->group(function () {
        // Vehículos (tb_placas_transporte)
        Route::get('/vehiculos', [\App\Http\Controllers\MovilidadController::class, 'listarVehiculos'])->name('vehiculos.index');
        Route::post('/vehiculos', [\App\Http\Controllers\MovilidadController::class, 'guardarVehiculo'])->name('vehiculos.store');
        Route::put('/vehiculos/{id}', [\App\Http\Controllers\MovilidadController::class, 'actualizarVehiculo'])->name('vehiculos.update');
        Route::delete('/vehiculos/{id}', [\App\Http\Controllers\MovilidadController::class, 'eliminarVehiculo'])->name('vehiculos.destroy');
        // Choferes (tb_chofer_existentes)
        Route::get('/choferes', [\App\Http\Controllers\MovilidadController::class, 'listarChoferes'])->name('choferes.index');
        Route::post('/choferes', [\App\Http\Controllers\MovilidadController::class, 'guardarChofer'])->name('choferes.store');
        Route::put('/choferes/{id}', [\App\Http\Controllers\MovilidadController::class, 'actualizarChofer'])->name('choferes.update');
        Route::delete('/choferes/{id}', [\App\Http\Controllers\MovilidadController::class, 'eliminarChofer'])->name('choferes.destroy');
        // Documentos (vehículos y choferes)
        Route::post('/documentos', [\App\Http\Controllers\MovilidadController::class, 'subirDocumento'])->name('documentos.store');
        Route::get('/documentos/{tipo}/{id}', [\App\Http\Controllers\MovilidadController::class, 'listarDocumentos'])->name('documentos.index');
        // Tipos de documento personalizados
        Route::get('/tipos-documento/{tipo}', [\App\Http\Controllers\MovilidadController::class, 'listarTiposDocumento'])->name('tipos-documento.index');
        Route::post('/tipos-documento', [\App\Http\Controllers\MovilidadController::class, 'guardarTipoDocumento'])->name('tipos-documento.store');
        Route::put('/tipos-documento/{clave}', [\App\Http\Controllers\MovilidadController::class, 'actualizarTipoDocumento'])->name('tipos-documento.update');
        Route::delete('/tipos-documento/{clave}', [\App\Http\Controllers\MovilidadController::class, 'eliminarTipoDocumento'])->name('tipos-documento.destroy');

        // Otros documentos (categorías + archivos)
        Route::get('/otros-documentos/categorias', [\App\Http\Controllers\MovilidadController::class, 'listarOtrosDocumentosCategorias'])->name('otros-documentos.categorias.index');
        Route::post('/otros-documentos/categorias', [\App\Http\Controllers\MovilidadController::class, 'guardarCategoriaOtrosDocumento'])->name('otros-documentos.categorias.store');
        Route::put('/otros-documentos/categorias/{id}', [\App\Http\Controllers\MovilidadController::class, 'actualizarCategoriaOtrosDocumento'])->name('otros-documentos.categorias.update');
        Route::delete('/otros-documentos/categorias/{id}', [\App\Http\Controllers\MovilidadController::class, 'eliminarCategoriaOtrosDocumento'])->name('otros-documentos.categorias.destroy');
        Route::get('/otros-documentos/categorias/{id}/documentos', [\App\Http\Controllers\MovilidadController::class, 'listarDocumentosOtrosPorCategoria'])->name('otros-documentos.documentos.index');
        Route::post('/otros-documentos', [\App\Http\Controllers\MovilidadController::class, 'subirDocumentoOtros'])->name('otros-documentos.store');
        Route::delete('/otros-documentos/{id}', [\App\Http\Controllers\MovilidadController::class, 'eliminarDocumentoOtro'])->name('otros-documentos.destroy');
    });
    // 2. Rutas internas (AJAX) para que Vue consulte y guarde las reglas
    Route::get('/configuraciones/rules', [RuleSettingController::class, 'index'])->name('configuraciones.rules.index');
    Route::get('/configuraciones/sales-roles', [RuleSettingController::class, 'roles'])->name('configuraciones.sales-roles');
    Route::post('/configuraciones/rules/{ruleId}', [RuleSettingController::class, 'update'])->name('configuraciones.rules.update');
    Route::get('/configuraciones/roles/{roleId}/users', [RuleSettingController::class, 'getRoleUsers'])->name('configuraciones.roles.users');
    Route::get('/configuraciones/rules/{ruleId}/exceptions', [RuleSettingController::class, 'getExceptions'])->name('configuraciones.rules.exceptions');
    Route::delete('/configuraciones/rules/{ruleId}/exceptions/{userId}', [RuleSettingController::class, 'destroyException'])->name('configuraciones.rules.exceptions.destroy');

    // Modalidad asesores
    Route::get('/configuraciones/modalidad-asesores', [RuleSettingController::class, 'getModalidadAsesores'])->name('configuraciones.modalidad.index');
    Route::post('/configuraciones/modalidad-asesores/{userId}', [RuleSettingController::class, 'updateModalidad'])->name('configuraciones.modalidad.update');
    Route::post('/configuraciones/modalidad-asesores-bulk', [RuleSettingController::class, 'updateModalidadBulk'])->name('configuraciones.modalidad.bulk');

    // Rutas para configuración de reglas de negocio
    // Route::get('/settings/rules', [RuleSettingController::class, 'index'])->name('settings.rules.index');
    // Route::post('/settings/rules/{ruleId}', [RuleSettingController::class, 'update'])->name('settings.rules.update');
    // Route::get('/settings/rules/{ruleId}/exceptions', [RuleSettingController::class, 'getUserExceptions'])->name('settings.rules.exceptions.index');
    // Route::post('/settings/rules/{ruleId}/exceptions', [RuleSettingController::class, 'updateUserExceptions'])->name('settings.rules.exceptions.update');
});

// inicio de rutas con primevue

// rutas para pendientes tecnicos
Route::middleware(['restringir_horario_servicio'])->group(function () {
    Route::post('/guardar-pendientes', [PendientesTecnicosController::class, 'guardarPendientes']);
    Route::get('/api/archivos-precios', [PendientesTecnicosController::class, 'listarArchivos']);
    Route::get('/fn_obtenerIndexListadePendientes', [PendientesTecnicosController::class, 'obtenerIndexListadePendientes']);
    Route::get('/pendientes-tecnicos/pdf-preview', [PendientesTecnicosController::class, 'pdfPreview'])->name('pendientes-tecnicos.pdf-preview');
    Route::get('/api/verificar-tarea-completada/{pendienteId}', [PendientesTecnicosController::class, 'verificarTareaCompletada']);
    Route::get('/fn_obtenerPendientesPorFecha', [PendientesTecnicosController::class, 'obtenerPendientesVendedoresPorFecha']);

    Route::get('/pendientesporprogramar/data-fecha', [PendientesTecnicosController::class, 'obtenerPendientesPorProgramarRango'])->name('pendientesporprogramar.data-fecha');
    Route::get('/pendientesprogramados/data-fecha', [PendientesTecnicosController::class, 'obtenerPendientesProgramadosRango'])->name('pendientesprogramados.data-fecha');
    Route::get('/pendientes-tecnicos/generar-cuadro', [PendientesTecnicosController::class, 'generarCuadro'])->name('pendientes-tecnicos.generar-cuadro');
    Route::post('/pendientes-tecnicos/{id}/actualizar-observacion', [PendientesTecnicosController::class, 'actualizarObservacion'])->name('pendientes-tecnicos.actualizar-observacion');
    Route::get('/pendientestecnicos/{id}/detalle-tarea', [PendientesTecnicosController::class, 'ShowDetalleTarea'])->name('pendientestecnicos.detalle-tarea');
    Route::get('/api/pendientestecnicos/{id}/detalle-reporte', [PendientesTecnicosController::class, 'obtenerDetalleReporteTarea'])->name('pendientestecnicos.detalle-reporte');
});

// ========== RUTAS PARA GESTIÓN DE TABLAS DE TÉCNICOS Y CHÓFERES ==========
Route::get('/api/tecnicos-existentes', [PendientesTecnicosController::class, 'obtenerTecnicosExistentes']);
Route::get('/api/choferes-existentes', [PendientesTecnicosController::class, 'obtenerChoferesExistentes']);
Route::post('/api/usuarios/agregar-tecnico-existente', [PendientesTecnicosController::class, 'guardarTecnicoExistente']);
Route::post('/api/usuarios/agregar-chofer-existente', [PendientesTecnicosController::class, 'guardarChoferExistente']);
Route::post('/api/usuarios/eliminar-tecnico-existente/{id}', [PendientesTecnicosController::class, 'eliminarTecnicoExistente']);
Route::post('/api/usuarios/eliminar-chofer-existente/{id}', [PendientesTecnicosController::class, 'eliminarChoferExistente']);

// ========== RUTAS NUEVAS PARA GESTIÓN BIDIRECCIONAL DE PENDIENTES ==========

/**
 * RUTA: Marcar pendiente como asignado
 * MÉTODO: POST /pendientestecnicos/marcar-asignado
 * PROPÓSITO: Marcar un pendiente de vendedor como asignado cuando se crea una tarea técnica
 */
Route::post('/pendientestecnicos/marcar-asignado', [PendientesTecnicosController::class, 'marcarPendienteAsignado'])
    ->name('pendientestecnicos.marcarAsignado')
    ->middleware('restringir_horario_servicio');

/**
 * RUTA: Obtener pendiente de vendedor por ID
 * MÉTODO: GET /api/obtener-pendiente-vendedor/{id}
 * PROPÓSITO: Consultar un pendiente específico para restaurarlo a la lista
 */
Route::get('/api/obtener-pendiente-vendedor/{id}', [PendientesTecnicosController::class, 'obtenerPendienteVendedor']);

Route::put('/datos/update/{id}', [DatoController::class, 'update'])->name('datos.updateById');
Route::get('/fn_obtenerDatosdeClientesparaPendientes', [PendientesController::class, 'obtenerDatosdeClientesparaPendientes']);
Route::get('/fn_obtenerContactosDeCliente/{clienteId}', [PendientesController::class, 'obtenerContactosDeCliente'])->name('fn.contactos');
Route::post('/contactos', [PendientesController::class, 'storeContacto'])->name('contactos.store');

// Nueva ruta para pendientes vendedores 07-01
Route::get('/pendientes/data-fecha', [PendientesController::class, 'obtenerPendientesPorFecha'])->name('pendientes.data-fecha');
Route::get('/pendientes/{id}/instrumentos', [PendientesController::class, 'getInstrumentos'])->name('pendientes.instrumentos');
Route::get('/pendientes/buscar-cotizacion', [PendientesController::class, 'buscarCotizacion'])->name('pendientes.buscarCotizacion');
Route::post('/pendientes/actualizar-servicio', [PendientesController::class, 'actualizarServicio'])->name('pendientes.actualizarServicio');
Route::get('/pendientes/clientes/buscar-ligero', [PendientesController::class, 'buscarClientes'])
    ->name('pendientes.clientes.buscar');
Route::get('/pendientes/clientes/{id}/contactos', [PendientesController::class, 'obtenerContactos'])
    ->name('pendientes.clientes.contactos');
Route::post('/pendientes/contactos/store', [PendientesController::class, 'guardarContactoRapido'])
    ->name('pendientes.contactos.store');
Route::get('/pendientes/instrumentos/catalogos', [PendientesController::class, 'obtenerCatalogos'])->name('pendientes.instrumentos.catalogos.index');
Route::post('/pendientes/instrumentos/marcas', [PendientesController::class, 'guardarMarca'])->name('pendientes.instrumentos.marcas.store');
Route::post('/pendientes/instrumentos/modelos', [PendientesController::class, 'guardarModelo'])->name('pendientes.instrumentos.modelos.store');
Route::post('/pendientes/instrumentos/materiales', [PendientesController::class, 'guardarMaterial'])->name('pendientes.instrumentos.materiales.store');
Route::post('/pendientes/instrumentos/clases-exactitud', [PendientesController::class, 'guardarClaseExactitud'])->name('pendientes.instrumentos.clases-exactitud.store');
Route::get('/pendientes/obtener/{id}', [PendientesController::class, 'obtenerPedienteEdicion'])->name('pendientes.obtenerPedienteEdicion');
Route::get('/pendientes/validar-cotizacion', [PendientesController::class, 'validarCotizacion'])->name('pendientes.validarCotizacion');
Route::post('/pendientes/{id}/update', [PendientesController::class, 'editarPendiente'])->name('pendientes.editarPendiente');
Route::delete('/pendientes/{id}', [PendientesController::class, 'eliminarPendiente'])->name('pendientes.eliminarPendiente');
Route::put('/pendientes/instrumentos/{id}/rapido', [PendientesController::class, 'updateInstrumentoRapido'])
    ->name('pendientes.instrumentos.update-rapido');
Route::get('/pendientes/{id}/detalle-ventana', [PendientesController::class, 'showDetallePage'])
    ->name('pendientes.detalle-ventana');

//ruta de previsualizacion de cot en detalle del pendiente vendedor    
Route::get('/cotizacion/preview/{tipo}/{id}/{nCotizacion}', function ($tipo, $id, $nCotizacion) {
    $extra = [];
    if ($tipo === 'servicio') {
        $cot = \Illuminate\Support\Facades\DB::table('cservicios')->where('id', $id)->first();
        if ($cot) {
            $extra = [
                'fecha' => $cot->fecha,
                'moneda' => $cot->moneda,
                'asesor' => $cot->asesor,
                'validez' => $cot->validez,
                'fpago' => $cot->fpago,
                'subtotal' => $cot->subtotal,
                'descripcion' => $cot->descripcion,
                'id_plantilla' => $cot->id_plantilla,
                'precio_unitario' => $cot->precio_unitario,
            ];
        }
    }
    return Inertia::render('CuadroPendientes/PreviewCotizacion', [
        'cotizacionId' => (int) $id,
        'tipo' => $tipo,
        'nCotizacion' => $nCotizacion,
        'extra' => $extra,
    ]);
})->name('cotizacion.preview')->middleware('auth');

// ========== RUTAS PARA MATERIALES DE PENDIENTES ==========
Route::get('/api/materiales-pendientes', [MaterialPendienteController::class, 'index']);
Route::post('/api/materiales-pendientes', [MaterialPendienteController::class, 'store']);
Route::get('/api/materiales-pendientes/search', [MaterialPendienteController::class, 'search']);

Route::get('/fn_obtenerClientesParaInformes', [CServicioController::class, 'obtenerClientesParaInformes']);
Route::get('/api/usuarios-soporte-tecnico', [UserController::class, 'obtenerUsuariosSoporteTecnico']);
Route::post('/api/crear-usuario-soporte-tecnico', [UserController::class, 'crearUsuarioSoporteTecnico']);
Route::get('/fn_obtenerDatosClientesRelacionados', [DatoController::class, 'obtenerDatosClientesRelacionados']);

Route::get('/fn_obtenerDatosConsultarClientes', [ClienteController::class, 'obtenerDatosConsultarClientes'])->name('clientes.obtenerConsultarClientes');
Route::get('/fn_obtenerDatosCiudades', [ClienteController::class, 'obtenerDatosCiudades'])->name('clientes.obtenerDatosCiudades');
Route::get('/fn_obtenerSectorComercial', [ClienteController::class, 'obtenerSectorComercial'])->name('clientes.obtenerSectorComercial');



Route::get('/fn_obtenerDatosdeCompras', [CarritoController::class, 'obtenerDatosdeCompras']);

Route::get('/fn_obtenerCategoriasProductos', [ProductoController::class, 'obtenerCategoriasProductos']);
Route::get('/fn_obtenerIndexInventario', [ProductoController::class, 'obtenerIndexInventario']);
Route::get('/productos/miniatura/{filename}', \App\Http\Controllers\ProductThumbnailController::class)
    ->middleware('auth')->name('productos.miniatura');


Route::get('/fn_ObtenerProductosCategorias', [CategoryController::class, 'obtenerProductosCategorias']);
Route::get('/fn_obtenerCategorias', [CategoryController::class, 'obtenerCategorias']);
Route::get('/fn_buscarProductosPorCategorias', [CategoryController::class, 'buscarProductosPorCategorias'])->name('categories.buscar_productos');

Route::get('/salidas/edit/{salida}', [SalidasController::class, 'editSalidas'])->name('salidas.editSalidas');
Route::get('/fn_obtenerDatosConsultarProductosSalidas', [SalidasController::class, 'obtenerDatosConsultarProductosSalidas']);
Route::get('/fn_obtenerDatosUsuariosSalidas', [SalidasController::class, 'obtenerDatosUsuariosSalidas']);
Route::get('/listarSalidasRelacionadas', [SalidasController::class, 'listarSalidasRelacionadas'])->name('salidas.listarSalidasRelacionadas');
Route::get('/fn_obtenerListadeSalidasUnicas', [SalidasController::class, 'obtenerListadeSalidasUnicas']);
Route::get('/fn_obtenerIndexListadeSalidas', [SalidasController::class, 'obtenerIndexListadeSalidas']);



Route::get('/fn_obtenerDatosConsultarProductos', [EntradaController::class, 'obtenerDatosConsultarProductos']);
Route::get('/fn_obtenerEntradas', [EntradaController::class, 'obtenerEntradas']);

Route::get('/fn_obtenerIndexCategorias', [CategoryController::class, 'obtenerIndexCategorias']);



route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
Route::get('/fn_obtenerRolesdeUsuariosIndex', [RoleController::class, 'obtenerRolesdeUsuariosIndex']);
Route::get('/fn_obtenerPermisosdeUsuariosIndex', [PermisoController::class, 'obtenerPermisosdeUsuariosIndex']);
route::get('/fn_obtenerPermisosdeRoles/{role}', [RoleController::class, 'obtenerPermisosdeRoles']);
Route::get('/fn_obtenerDatosdeUsuarios', [UserController::class, 'obtenerDatosdeUsuarios']);
Route::get('/fn_obtenerRolesdeUsuarios', [UserController::class, 'obtenerRolesdeUsuarios']);
route::post('/fn_registrarDatosdeUsuario', [UserController::class, 'store'])->name('fn_registrarDatosdeUsuario');

// termino de rutas con primevue

Route::get('/fn_consultarDatosDiagnosticosServ', [HservicioController::class, 'consultarDatosDiagnosticosServ']);

Route::get('/fn_registrarDatosCajaChica', [CajaChicaController::class, 'registrarDatosCajaChica']);
Route::get('/fn_obtenerDatosCajaChica', [CajaChicaController::class, 'obtenerDatosCajaChica']);
Route::get('/fn_consulta_EliminarPago', [CajaChicaController::class, 'consulta_EliminarPago']);
Route::get('/fn_consulta_AgregarDetalleEgresoEditar', [CajaChicaController::class, 'consulta_AgregarDetalleEgresoEditar']);

Route::get('/fn_obtenerFechaHojaServicio', [HservicioController::class, 'obtenerFechaHojaServicio']);
Route::post('/fn_registrarInformesTecnicosdeCliente', [HservicioController::class, 'fn_registrarInformesTecnicosdeCliente'])->name('hservicios.fn_registrarInformesTecnicosdeCliente');
Route::get('/fn_verificarFechayDatos', [HservicioController::class, 'verificarFechayDatos']);
Route::get('/fn_obtenerHojasServicioAnteriores', [HservicioController::class, 'obtenerHojasServicioAnteriores']);
Route::get('/fn_guardarCotizacion', [CventaController::class, 'guardarCotizacion']);
Route::get('/fn_ObtenerHojasServicio', [HservicioController::class, 'obtenerHojasServicio']);
Route::get('/fn_ObtenerHojasServicioDiarias', [HservicioController::class, 'obtenerHojasServicioDiarias']);
Route::get('/validarIdCot', [CventaController::class, 'validarIdCot']);
route::get('/fn_ObtenerDatosSalidas', [ProductoController::class, 'fn_ObtenerDatosSalidas'])->name('fn_ObtenerDatosSalidas');
Route::get('/fn_obtenerSubcategoriasRegistradas', [TbsubcategoriaController::class, 'obtenerSubcategoriasRegistradas']);
Route::get('/fn_obtenerMarcasRegistradas', [TbmarcaController::class, 'obtenerMarcasRegistradas']);
Route::get('/fn_obtenerProvinciasRegistradas', [TbprovinciaController::class, 'obtenerProvinciasRegistradas']);

// Nuevas rutas para informes técnicos - Hojas de Servicio
// 1. Ruta para mostrar la vista del componente Create2.vue
Route::get('/hservicios/nuevo-informe', [HservicioController::class, 'create'])
    ->name('hservicio.create');
// 2. Ruta API para obtener las tareas pendientes (usada por Axios en el componente)
Route::get('/hservicios/mis-pendientes', [HservicioController::class, 'getMisPendientes'])
    ->name('hservicio.pendientes');
// 3. Ruta para guardar el informe completo (usada por el form.post)
Route::post('/hservicios/guardar-informe', [HservicioController::class, 'store'])
    ->name('hservicio.store');
Route::get('/hservicios/historial', [HservicioController::class, 'index'])
    ->name('hservicio.index');
Route::get('/hservicios/galeria', [HservicioController::class, 'galeria'])
    ->name('hservicios.galeria');
Route::post('/hservicios/galeria', [HservicioController::class, 'storeGaleria'])
    ->name('hservicios.galeria.store');
Route::delete('/hservicios/galeria/{id}', [HservicioController::class, 'destroyGaleria'])
    ->name('hservicios.galeria.destroy');
// 2. API Interna: Trae los datos JSON filtrados por fecha
Route::get('/hservicios/buscarinformes', [HservicioController::class, 'getInformesPorFecha'])
    ->name('hservicio.buscarinformes');
// Ver Detalle
Route::get('/hservicios/detalle/{id}', [HservicioController::class, 'verInforme'])
    ->name('hservicio.verinforme');
Route::get('/api/hservicio-detalle/{id}', [HservicioController::class, 'apiDetalleHservicio'])
    ->name('api.hservicio.detalle');
// Editar un instrumento específico (Modal en Show.vue)
Route::post('/hservicios/instrumento/{id}/update', [HservicioController::class, 'updateInstrumento'])
    ->name('hservicio.instrumento.update');
// Eliminar o Resetear instrumento
Route::delete('/hservicios/instrumento/{id}/reset', [HservicioController::class, 'resetOrDeleteInstrumento'])
    ->name('hservicio.instrumento.reset');
Route::post('/hservicios/{id}/toggle-bloqueo', [HservicioController::class, 'toggleBloqueo'])
    ->name('hservicio.toggleBloqueo');
Route::post('/hservicios/instrumento/{id}/toggle-realizado', [HservicioController::class, 'toggleRealizado'])
    ->name('hservicio.instrumento.toggleRealizado');
Route::post('/hservicios/{id}/finalizar', [HservicioController::class, 'finalizarInforme'])
    ->name('hservicio.finalizar');


Route::get('/nuevo-reporte', [ReportesController::class, 'create'])
    ->name('reportes.create');
Route::get('/reportes/mis-pendientes', [ReportesController::class, 'getMisPendientes'])
    ->name('reportes.pendientes');
Route::post('/reportes/store', [ReportesController::class, 'store'])->name('reportes.store');
Route::post('/reportes/instrumento/guardar', [ReportesController::class, 'guardarInstrumentoParcial'])->name('reportes.instrumento.guardar');
Route::get('/reportes/historial', [ReportesController::class, 'index'])
    ->name('reportes.index');
// 2. API Interna: Trae los datos JSON filtrados por fecha
Route::get('/reportes/buscarinformes', [ReportesController::class, 'getReportesPorFecha'])
    ->name('reportes.buscarinformes');
Route::get('/reportes/detalle/{id}', [ReportesController::class, 'verReporte'])
    ->name('reportes.verreporte');
Route::get('/api/reporte-detalle/{id}', [ReportesController::class, 'apiDetalleReporte'])
    ->name('api.reporte.detalle');

Route::post('/diagnosticos/{id}/toggle', [ReportesController::class, 'toggleEstadoDiagnostico'])->name('diagnosticos.toggle');
Route::put('/reportes/instrumentos/{id}', [ReportesController::class, 'updateInstrumento'])
    ->middleware('auth')->name('reportes.instrumentos.update');
Route::post('/diagnosticos/{id}/update', [ReportesController::class, 'updateDiagnostico'])->name('diagnosticos.update');
Route::delete('/diagnosticos/{id}/reset', [ReportesController::class, 'resetDiagnostico'])->name('diagnosticos.reset');

Route::post('/informes/store/{instrumentoId}', [ReportesController::class, 'storeInforme'])->name('reportes.informes.store');
Route::post('/informes/{id}/update', [ReportesController::class, 'updateInforme'])->name('informes.update');
Route::delete('/informes/{id}/reset', [ReportesController::class, 'resetInforme'])->name('informes.reset');

// finalizar reportes de servicio tecnico
Route::post('/reportes/{id}/finalizar', [ReportesController::class, 'finalizarReporte'])->name('reportes.finalizar');
Route::delete('/reportes/{id}/eliminar', [ReportesController::class, 'eliminarReporte'])->name('reportes.eliminar');
Route::post('/reportes/{id}/subir-acta-firmada', [ReportesController::class, 'subirActaFirmada'])->name('reportes.subirActaFirmada');
Route::post('/pendientes/{pendienteId}/finalizar-servicio', [ReportesController::class, 'finalizarServicio'])->name('pendientes.finalizarServicio');
Route::delete('/reportes/{id}/quitar-acta-firmada', [ReportesController::class, 'quitarActaFirmada'])
    ->name('reportes.quitarActaFirmada');

//Diagnostico
Route::get('/diagnosticos/pendientes', [DiagnosticoController::class, 'pendientes'])->name('diagnosticos.pendientes');
Route::get('/diagnosticos/tarea/{tarea}', [DiagnosticoController::class, 'show'])->name('diagnosticos.show');
Route::post('/diagnosticos', [DiagnosticoController::class, 'store'])->name('diagnosticos.store');
Route::put('/diagnosticos/caracteristicas', [DiagnosticoController::class, 'updateCaracteristicas'])->name('diagnosticos.actualizar-caracteristicas');

// Informe
Route::get('/informes/pendientes', [InformeController::class, 'pendientes'])->name('informes.pendientes');
Route::get('/informes/tarea/{tarea}', [InformeController::class, 'show'])->name('informes.show');
Route::post('/informes', [InformeController::class, 'store'])->name('informes.store');
Route::put('/informes/caracteristicas', [InformeController::class, 'updateCaracteristicas'])->name('informes.actualizar-caracteristicas');

// Fichas Técnicas
Route::get('/fichas-tecnicas', [FichaTecnicaController::class, 'index'])->name('fichas-tecnicas.index');
Route::post('/ficha-tecnica/preparar', [FichaTecnicaController::class, 'prepararFichas'])->name('ficha-tecnica.preparar');
Route::get('/fichas-tecnicas/clientes/buscar', [FichaTecnicaController::class, 'buscarClientes'])->name('fichas-tecnicas.clientes.buscar');
Route::get('/fichas-tecnicas/tareas', [FichaTecnicaController::class, 'obtenerTareas'])->name('fichas-tecnicas.tareas');

Route::resource('hservicios', HservicioController::class);

//codigo de luis
Route::post('/cotizacion/guardar', [CServicioController::class, 'store'])->name('store');
Route::get('/cotizacion/guardar', [CServicioController::class, 'generarCodCotizacion'])->name('generarCodCotizacion');
Route::get('/plantilla/obtener/{id}', [CServicioController::class, 'obtenerDatos'])->name('obtenerDatos');
Route::get('/cliente/obtener/{n_cotizacion}', [CServicioController::class, 'ObtenerDatosCliente'])->name('ObtenerDatosCliente');
Route::get('/Instrumento/obtener/{n_cotizacion}', [CServicioController::class, 'obtenerDatosInstrumento'])->name('obtenerDatosInstrumento');

Route::get('/cotizacion/obtenerTodo/{n_cotizacion}/{id_plantilla}', [CServicioController::class, 'obtenerTodoPDF']);

//certificados
// Route::post('/certificado/guardar', [SolicitudCertificacionController::class, 'GuardarDatosCertificado'])->name('guardarCompleta');
// Route::get('/fn_consultarSolicitudesCertificacion', [SolicitudCertificacionController::class, 'consultarSolicitudes']);


//Almacén
Route::get('/fn_buscarCategoriaProducto', [CategoryController::class, 'buscarCategoriaProducto']);
Route::get('/fn_buscarProductosGlobal', [CategoryController::class, 'buscarProductosGlobal']);


// Archivos: listar, subir, ver
Route::get('/fn_archivosCertificado', [SolicitudCertificacionController::class, 'listarArchivosCertificado']);
Route::post('/fn_archivosCertificado', [SolicitudCertificacionController::class, 'subirArchivosCertificado']);
Route::get('/fn_archivosCertificado/ver/{id}', [SolicitudCertificacionController::class, 'verArchivoCertificado'])
    ->name('archivosCertificado.ver');

Route::post('/ComparacionCode', [SalidasController::class, 'ComparacionCode'])->name('ComparacionCode');
Route::post('/salidas/store', [SalidasController::class, 'store'])->name('salidas.storeCustom');
// Route::post('/ComparacionPassword', [SalidasController::class, 'ComparacionPassword'])->name('comparar.password');
Route::post('/ComparacionPassword', [SalidasController::class, 'ComparacionPassword'])->name('comparar.password');
Route::get('/productos/{producto}/salida/{usuario}', [ProductoController::class, 'salida'])->name('productos.salida');
Route::get('/productos/{producto}/entrada/{usuario}', [ProductoController::class, 'entrada'])->name('productos.entrada');
Route::post('/productos/{id}/IngresoProducto', [ProductoController::class, 'IngresoProducto'])->name('productos.IngresoProducto');
Route::get('/api/productos/ultimo-consecutivo', [ProductoController::class, 'obtenerUltimoConsecutivo']);
Route::post('/salidas/devolver-multiples', [SalidasController::class, 'devolverMultiples'])->name('salidas.devolverMultiples');
Route::post('/productos/buscar-codigo', [ProductoController::class, 'buscarPorCodigo'])->name('productos.buscar_codigo');
// Route::get('/dashboard', function () {
//     return Inertia::render('Dashboard');
// })->name('dashboard');

Route::get('/scanner-salida', function () {
    return Inertia::render('Productos/ScannerSalida');
})->name('scanner.salida');
Route::get('/api/areas', [ProductoController::class, 'obtenerAreas'])->name('areas.index');
Route::post('/api/areas', [ProductoController::class, 'guardarArea'])->name('areas.store');
// Buscar productos por categoría y término
Route::post('/salidas/validar-password', [SalidasController::class, 'validarPassword'])->name('salidas.validarPassword');
Route::get('entradas/ultimosDosMeses', [EntradaController::class, 'obtenerEntradas'])->name('entradas.ultimosDosMeses');

//certificados
Route::post('/certificado/guardar', [SolicitudCertificacionController::class, 'GuardarDatosCertificado'])->name('guardarCompleta');
Route::get('/fn_consultarSolicitudesCertificacion', [SolicitudCertificacionController::class, 'consultarSolicitudes']);


// Listado de solicitudes con estado (pendiente/recibido)
Route::get('/fn_consultarSolicitudesCertConEstado', [SolicitudCertificacionController::class, 'consultarSolicitudesConEstado']);

// Archivos: listar, subir, ver
Route::get('/fn_archivosCertificado', [SolicitudCertificacionController::class, 'listarArchivosCertificado']);
Route::post('/fn_archivosCertificado', [SolicitudCertificacionController::class, 'subirArchivosCertificado']);
Route::get('/fn_archivosCertificado/ver/{id}', [SolicitudCertificacionController::class, 'verArchivoCertificado'])
    ->name('archivosCertificado.ver');
Route::get('/fn_obtenerDatosdeSolicitudCertificacion', [SolicitudCertificacionController::class, 'obtenerDatosdeSolicitudCertificacion']);
Route::get('/fn_obtenerMarcasCertificacion', [SolicitudCertificacionController::class, 'obtenerMarcasCertificacion']);
Route::get('/fn_obtenerModelosCertificacion', [SolicitudCertificacionController::class, 'obtenerModelosCertificacion']);
Route::post('/fn_guardarModeloCertificacion', [SolicitudCertificacionController::class, 'guardarModeloCertificacion']);
Route::post('/api/pdf-preview', [CServicioController::class, 'servirPdfBase64']);
Route::get('/fn_consultaAgregarNuevaMarca', [SolicitudCertificacionController::class, 'consultaAgregarNuevaMarca']);
Route::get('/fn_guardarContacto', [SolicitudCertificacionController::class, 'guardarContacto']);
Route::get('/fn_guardarDivisionEscala', [SolicitudCertificacionController::class, 'guardarDivisionEscala']);

// Herramientas Personal
Route::get('/herramientas-personal', [HerramientasPersonalController::class, 'index'])->name('herramientas-personal.index');
Route::get('/herramientas-personal/{id}', [HerramientasPersonalController::class, 'herramientas'])->name('herramientas-personal.herramientas');
Route::get('/fn_obtenerUsuariosTecnicos', [HerramientasPersonalController::class, 'obtenerUsuariosTecnicos']);

// Cajas de Herramientas
Route::get('/api/cajas-herramientas', [CajaHerramientaController::class, 'index'])->name('cajas.index');
Route::get('/cajas-herramientas', [CajaHerramientaController::class, 'getCajas'])->name('cajas.getCajas');
Route::post('/api/cajas-herramientas', [CajaHerramientaController::class, 'store'])->name('cajas.store');
Route::put('/api/cajas-herramientas/{id}', [CajaHerramientaController::class, 'update'])->name('cajas.update');
Route::post('/api/cajas-herramientas/asignar', [CajaHerramientaController::class, 'asignarTecnico'])->name('cajas.asignar');
Route::post('/api/cajas-herramientas/eliminar-masivo', [CajaHerramientaController::class, 'eliminarMasivo'])->name('cajas.eliminarMasivo');

Route::get('/api/tecnico/{tecnico_id}/caja-id', [HerramientasPersonalController::class, 'getCajaIdByTecnico'])->name('api.tecnico.caja');

Route::get('/fn_obtenerHerramientasCaja/{id}', [HerramientasPersonalController::class, 'obtenerHerramientasUsuario']);
Route::get('/herramientaspersonal/create', [HerramientasPersonalController::class, 'create'])->name('herramientas.create');
Route::post('/herramientaspersonal', [HerramientasPersonalController::class, 'store'])->name('herramientaspersonal.store');
Route::get('/herramientas-personal/imagen/{nombreArchivo}', [HerramientasPersonalController::class, 'verImagenHerramienta'])->name('herramientas-personal.imagen');
Route::delete('/herramientas/{herramienta}', [HerramientasPersonalController::class, 'destroy'])->name('herramientas.destroy');

Route::get('/herramientas/{herramienta}/edit', [HerramientasPersonalController::class, 'edit'])->name('herramientas.edit');


Route::put('/herramientas/{herramienta}', [HerramientasPersonalController::class, 'update'])->name('herramientas.update');

Route::get('/herramientas/entrada', [HerramientasPersonalController::class, 'entrada'])->name('herramientas.entrada');

Route::post('/herramientas/{idHerramienta}/storeEntrada', [HerramientasPersonalController::class, 'storeEntrada'])->name('herramientas.storeEntrada');

Route::get('/herramientas-personal/ingresos/{idCaja}', [HerramientasPersonalController::class, 'listarIngresos'])->name('herramientas-personal.listarIngresos');
Route::get('/fn_obtenerIngresosCaja/{idCaja}', [HerramientasPersonalController::class, 'obtenerIngresosTecnico'])->name('herramientas.obtenerIngresosTecnico');
Route::get('/herramientas-personal/checklist/{idCaja}', [HerramientasPersonalController::class, 'createChecklist'])->name('herramientas.checklist');
Route::post('/herramientas-personal/checklist', [HerramientasPersonalController::class, 'storeChecklist'])->name('herramientas.storeChecklist');
Route::post('/fn_updateColorTecnico', [HerramientasPersonalController::class, 'updateColor'])->name('tecnicos.updateColor');
Route::get('/herramientas-personal/listado-checklist/{idCaja}', [HerramientasPersonalController::class, 'listadoChecklist'])->name('herramientas-personal.listadoChecklist');
Route::post('/herramientas-personal/store-subcategoria', [HerramientasPersonalController::class, 'storeSubcategoria'])->name('herramientas.storeSubcategoria');
Route::get('/api/subcategorias-herramientas', [HerramientasPersonalController::class, 'listarSubcategorias']);
Route::post('/herramientas/mover', [HerramientasPersonalController::class, 'moverHerramienta'])->name('herramientas.mover'); //nuevo
Route::post('/herramientas/mover-masivo', [HerramientasPersonalController::class, 'moverHerramientasMasivo'])->name('herramientas.moverMasivo'); //nuevo

// ========== ENDPOINTS API PARA PRODUCTOS Y CATEGORÍAS ==========

// Endpoints para Productos
Route::prefix('api/productos')->group(function () {
    Route::get('/', [ProductoController::class, 'apiIndex'])->name('api.productos.index');
    Route::post('/', [ProductoController::class, 'apiStore'])->name('api.productos.store');
    Route::get('/{id}', [ProductoController::class, 'apiShow'])->name('api.productos.show');
    Route::put('/{id}', [ProductoController::class, 'apiUpdate'])->name('api.productos.update');
    Route::delete('/{id}', [ProductoController::class, 'apiDestroy'])->name('api.productos.destroy');
    Route::get('/{id}/numeros-serie', [ProductoController::class, 'obtenerNumerosSerie'])->name('api.productos.numeros-serie');
    Route::get('/categoria/{categoriaId}', [ProductoController::class, 'apiByCategory'])->name('api.productos.by-category');
});

// Endpoints para Categorías
Route::prefix('api/categories')->group(function () {
    Route::get('/', [CategoryController::class, 'apiIndex'])->name('api.categories.index');
    Route::post('/', [CategoryController::class, 'apiStore'])->name('api.categories.store');
    Route::get('/{id}', [CategoryController::class, 'apiShow'])->name('api.categories.show');
    Route::put('/{id}', [CategoryController::class, 'apiUpdate'])->name('api.categories.update');
    Route::delete('/{id}', [CategoryController::class, 'apiDestroy'])->name('api.categories.destroy');
});

// Rutas para almacen
Route::get('/categorias-listado', [CategoryController::class, 'obtenerListaCategorias']);
Route::get('/areas-listado', [CategoryController::class, 'obtenerListaAreas']);
Route::post('/categories/store', [CategoryController::class, 'storeCategoria']);
Route::put('/categories/update/{id}', [CategoryController::class, 'updateCategoria'])->name('update.categories');
Route::get('/fn_buscarProductosPorCategoriasNuevo', [CategoryController::class, 'fn_buscarProductosPorCategoriasNuevo']);
Route::get('/productos-exportar/{categoryId}', [CategoryController::class, 'exportarProductosPorCategoria'])
    ->name('exportar.productos.categoria');
Route::post('/productos/update/{id}', [CategoryController::class, 'updateProductoCategorias'])
    ->name('update.productos.categoria');

// Ruta para actualizar productos desde categorías
Route::post('/categorycontroller/updatesP', [CategoryController::class, 'updatesP'])->name('categorycontroller.updatesP');

// Ruta para obtener números de serie
Route::get('/api/categorycontroller/{productoId}/numeros-serie', [CategoryController::class, 'obtenerNumerosSerie']);

// Obtener todos los transportes únicos
Route::get('/api/transportes-tecnicos', [PendientesTecnicosController::class, 'obtenerTransportes']);

// Buscar transportes (para autocomplete)
Route::get('/api/transportes-tecnicos/search', [PendientesTecnicosController::class, 'buscarTransportes']);

// Agregar nuevo transporte
Route::post('/api/transportes-tecnicos/agregar', [PendientesTecnicosController::class, 'guardarTransporte']);
Route::post('/api/transportes-tecnicos/eliminar', [PendientesTecnicosController::class, 'eliminarTransporte']);

// ruta para actualizar productos en buscador global
Route::post('/api/categorycontroller', [CategoryController::class, 'updatesP']);
Route::get('/api/categorycontroller/{id}/numeros-serie', [CategoryController::class, 'obtenerNumerosSerie']);

// Rutas para el Chatbot
//Route::post('/chatbot/ask', [ChatbotController::class, 'ask'])->name('chatbot.ask');


//Rutas para el seguimiento de clientes

// Vista principal del componente refactorizado
Route::get('/crm/seguimiento', [InteraccionesController::class, 'index'])
    ->name('seguimientos.index');

// Endpoint para la carga asíncrona de datos (Axios)
Route::get('/crm/interacciones', [InteraccionesController::class, 'getInteracciones'])
    ->name('seguimientos.interacciones');

// Rutas para creación (las usaremos en el siguiente paso)
Route::get('/crm/seguimiento/nuevo', [InteraccionesController::class, 'create'])
    ->name('seguimientos.interacciones.create');

Route::get('/api/crm/clientes/buscar', [InteraccionesController::class, 'buscarClientes'])
    ->name('seguimientos.clientes.buscar');
Route::get('/api/crm/contactos/{cliente_id}', [InteraccionesController::class, 'getContactosPorCliente'])->name('seguimientos.contactos.por_cliente');
Route::post('/api/crm/contactos', [InteraccionesController::class, 'storeContacto'])->name('seguimientos.contactos.store');

Route::post('/crm/seguimiento', [InteraccionesController::class, 'store'])
    ->name('seguimientos.interacciones.store');

Route::get('/crm/agenda', [InteraccionesController::class, 'agenda'])
    ->name('seguimientos.agenda.index');
// Ruta para la Agenda (FullCalendar)
Route::get('/api/crm/agenda', [InteraccionesController::class, 'getAgendaData'])
    ->name('seguimientos.agenda');

// Marcar como realizado para detener notificaciones
Route::post('/api/crm/agenda/completar/{id}', [InteraccionesController::class, 'marcarRealizado'])
    ->name('seguimientos.agenda.completar');
Route::post('/api/crm/agenda/cancelar/{id}', [InteraccionesController::class, 'marcarCancelado'])
    ->name('seguimientos.agenda.cancelar');

//Rutas para las notificaciones
Route::get('/api/notificaciones', [NotificationController::class, 'index'])
    ->name('notificaciones.index');
// Marcar una notificación como leída
Route::post('/api/notificaciones/{id}/leer', [NotificationController::class, 'markAsRead'])
    ->name('notificaciones.leer');

// Rutas para notificaciones de seguimiento (Agenda)
Route::get('/api/mis-notificaciones', [DashboardController::class, 'getMisNotificaciones'])
    ->name('agenda.notificaciones');
Route::get('/api/pendientes-agenda', [DashboardController::class, 'getPendientesAgenda'])
    ->name('agenda.pendientes');
Route::post('/agenda/completar/{id}', [DashboardController::class, 'markAsCompleted'])
    ->name('agenda.completar');

Route::get('/api/agenda-datos', [DashboardController::class, 'getDatosAgenda'])->name('agenda.datos');
Route::post('/api/agenda/notificado/{id}', [DashboardController::class, 'markAsNotified'])->name('agenda.marcar_notificado');

// Rutas de Configuración y SCTR
Route::post('/configuracion/sctr/actualizar', [ConfiguracionController::class, 'actualizarSCTR'])->name('configuracion.sctr.actualizar');

// Rutas de Capacitaciones, Funciones y Procedimientos por Área
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/conocimiento/{area}', [ConocimientoController::class, 'index'])->name('conocimiento.index');
    Route::post('/conocimiento/{area}/capacitaciones', [ConocimientoController::class, 'storeCapacitacion'])->name('conocimiento.capacitaciones.store');
    Route::delete('/conocimiento/capacitaciones/{id}', [ConocimientoController::class, 'destroyCapacitacion'])->name('conocimiento.capacitaciones.destroy');

    Route::post('/conocimiento/{area}/funciones', [ConocimientoController::class, 'storeFuncion'])->name('conocimiento.funciones.store');
    //ordenar bloques de contenido de procedimientos
    Route::post('/conocimiento/{area}/procedimientos/reordenar-bloques', [ConocimientoController::class, 'reorderBloques'])->name('conocimiento.procedimientos.reorder-bloques');
    //ordenar procedimientos
    Route::post('/conocimiento/procedimientos/reorder', [ConocimientoController::class, 'reorderProcedimientos'])->name('procedimientos.reorder');
    Route::delete('/conocimiento/funciones/{id}', [ConocimientoController::class, 'destroyFuncion'])->name('conocimiento.funciones.destroy');
    Route::post('/conocimiento/{area}/procedimientos', [ConocimientoController::class, 'storeProcedimiento'])->name('conocimiento.procedimientos.store');
    Route::delete('/conocimiento/procedimientos/{id}', [ConocimientoController::class, 'destroyProcedimiento'])->name('conocimiento.procedimientos.destroy');
    Route::post('/conocimiento/upload-imagen', [ConocimientoController::class, 'uploadImagen'])->name('conocimiento.upload-imagen');
});

Route::get('/mcp/conectar', function () {
    return Inertia::render('McpConectar');
})->middleware('auth')->name('mcp.conectar');


Route::middleware(['throttle:20,1'])->group(function () {
    Route::get('mcp/cotizaciones/venta/{id}/pdf', [McpCotizacionesPdfController::class, 'abrirPdfVenta'])
        ->whereNumber('id')
        ->name('mcp.cotizacion.pdf.venta');

    Route::get('mcp/cotizaciones/servicio/{id}/pdf', [McpCotizacionesPdfController::class, 'abrirPdfServicio'])
        ->whereNumber('id')
        ->name('mcp.cotizacion.pdf.servicio');
});

Route::middleware(['auth'])->prefix('perfil/correo-corporativo')
    ->name('comunicaciones.correo.')
    ->group(function () {
        Route::get('/', [CorreoCorporativoController::class, 'index'])->name('perfil');
        Route::get('/estado', [CorreoCorporativoController::class, 'estado'])->name('estado');

        Route::middleware(['throttle:5,10'])->group(function () {
            Route::post('/conectar', [CorreoCorporativoController::class, 'conectar'])->name('conectar');
            Route::post('/probar', [CorreoCorporativoController::class, 'probar'])->name('probar');
        });
        Route::delete('/', [CorreoCorporativoController::class, 'desconectar'])->name('desconectar');
    });


Route::get('/mcp/almacen/productos/{id}/foto/subir', [McpCatalogoProductosFotoController::class, 'formulario'])
    ->name('mcp.almacen.foto.form')
    ->whereNumber('id')
    ->middleware(['auth', 'signed', 'throttle:30,1']);
Route::post('/mcp/almacen/productos/{id}/foto/subir', [McpCatalogoProductosFotoController::class, 'guardar'])
    ->name('mcp.almacen.foto.guardar')
    ->whereNumber('id')
    ->middleware(['auth', 'signed', 'throttle:10,1']);
Route::get('mcp/hojas-salida/{id}/pdf', [McpHojasSalidaPdfController::class, 'abrirPdf'])
    ->whereNumber('id')->middleware('throttle:20,1')->name('mcp.hojas_salida.pdf');


Route::middleware(['auth', 'throttle:20,1'])->group(function () {
    Route::get('comunicaciones/envios/{id}/aprobar', [AprobacionEnvioController::class, 'ver'])
        ->whereNumber('id')->name('comunicaciones.envios.aprobar');
    Route::post('comunicaciones/envios/{id}/aprobar', [AprobacionEnvioController::class, 'aprobar'])
        ->whereNumber('id')->middleware('throttle:5,1')
        ->name('comunicaciones.envios.aprobar.post');
    Route::match(
        ['GET', 'POST'],
        'comunicaciones/tarjeta/{id}',
        [ComunicacionesTarjetaWebController::class, 'manejar']
    )
        ->name('comunicaciones.tarjeta.web')
        ->whereNumber('id')
        ->middleware(['auth', 'signed', 'throttle:10,1']);

});

Route::middleware(['auth', 'throttle:20,1'])->group(function () {
    Route::get('mcp/certificaciones/{id}/subir', [McpCertificacionesArchivosController::class, 'formulario'])->whereNumber('id')->name('mcp.certificaciones.subir');
    Route::post('mcp/certificaciones/{id}/subir', [McpCertificacionesArchivosController::class, 'guardar'])->whereNumber('id');
    Route::get('mcp/certificaciones/{id}/archivos/{archivoId}', [McpCertificacionesArchivosController::class, 'abrirArchivo'])->whereNumber('id')->whereNumber('archivoId')->name('mcp.certificaciones.archivo');
    Route::get('mcp/certificaciones/{id}/solicitud.pdf', [McpCertificacionesPdfController::class, 'abrirPdf'])->whereNumber('id')->name('mcp.certificaciones.solicitud_pdf');
});

// Enlace temporal: firma relativa y permisos dentro del controlador, NO middleware signed.
Route::get('mcp/reportes/{id}/instrumentos/{instrumentoId}/pdf', [McpReporteTecnicoPdfController::class, 'abrir'])
    ->whereNumber(['id', 'instrumentoId'])->middleware('throttle:20,1')->name('mcp.reporte_tecnico.pdf');






