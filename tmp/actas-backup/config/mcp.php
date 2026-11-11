<?php

return [
    'bridge_secret' => env('MCP_BRIDGE_SECRET'),
    'gateway_callback' => env('MCP_GATEWAY_CALLBACK'),
    'token_days' => 30,

    'roles_acceso_total' => ['Gerencia', 'Area de TI'],

    // Quién puede usar las herramientas de Ventas
    'ventas_roles' => ['Asesor de Ventas', 'Jefe de Ventas', 'Gerencia', 'Area de TI'],

    // Quién ve los datos de todos (igual que RegistroLlamadaController)
    'ventas_roles_ve_todo' => ['Administracion', 'Gerencia', 'Area de TI', 'Jefe de Ventas'],

    // Quién puede registrar seguimientos desde el asistente
    'ventas_roles_registro' => ['Asesor de Ventas', 'Jefe de Ventas', 'Gerencia', 'Area de TI'],

    'seguimiento_dias_atras' => 7,   // hasta cuántos días atrás se puede registrar un seguimiento
    'seguimiento_bloqueo_dias' => 7,  // días de bloqueo si otro asesor atendió al cliente
    'cliente_inactivo_meses' => 2,   // cliente "vencido": última factura con más de 2 meses

    // Significado de agenda_seguimientos.estado (provisional: confirma los códigos reales)
    'agenda_estados' => [0 => 'Pendiente', 1 => 'Finalizado'],

    // clientes.estado_eliminado: valor que significa "activo". null = no filtrar (confirma el significado)
    'clientes_estado_eliminado_activo' => 1,

    // ── Cotizaciones ──
    'cotizaciones_roles' => ['Asesor de Ventas', 'Jefe de Ventas', 'Gerencia', 'Area de TI'],
    'cotizaciones_roles_ve_todo' => ['Area de TI', 'Gerencia', 'Jefe de Ventas'],
    'cotizaciones_permiso_ver_todo' => 'Ver todas las Cotizaciones',
    'cotizaciones_roles_edicion' => ['Asesor de Ventas', 'Jefe de Ventas', 'Gerencia', 'Area de TI'],   // también son quienes pueden registrar

    'cotizacion_estados' => ['Por Enviar', 'Pendiente', 'Aceptado', 'Rechazado', 'Finalizado', 'Sin Respuesta'],

    // Estados definitivos: no admiten cambio de estado ni ediciones.
    // Por Enviar, Pendiente y Sin Respuesta pueden pasar a cualquier otro estado.
    'cotizacion_estados_bloqueados' => ['Aceptado', 'Rechazado', 'Finalizado'],

    'cotizacion_igv' => 0.18,
    'cotizacion_dias_atras' => 2,   // hasta cuántos días atrás se puede fechar una cotización nueva

    // Condiciones por defecto de una cotización de servicio (copia de CServicioController::CONDICIONES_SERVICIO_GENERALES)
    'cotizacion_condiciones_servicio_generales' => [
        'La presente cotización está realizada solo y exclusivamente en base al requerimiento solicitado por parte del cliente como Calibración y Certificación acreditada.',
        'En caso el equipo no se encuentre, no hay derecho de reemplazo por otro.',
        'La balanza tiene que estar operativa y sin piezas faltantes.',
        'No incluye repuestos, ni cambio de piezas parciales o totales.',
        'De no cumplir con lo antes mencionado el Cliente pierde el derecho de esta cotización, sin reembolso alguno.',
        'Los certificados de calibración serán entregados posterior al abono correspondiente.',
    ],

    // Entran al módulo (el resto recibe 403). Gerencia entra siempre (mcp.roles_acceso_total).
    'cotizacion_pdf_roles' => ['Asesor de Ventas', 'Jefe de Ventas', 'Gerencia', 'Area de TI'],

    // Ven las cotizaciones de todos (copiado de los listados: CventaController@index y CServicioController@getCotizacionesServicio).
    'cotizacion_pdf_roles_ve_todo' => ['Gerencia', 'Area de TI', 'Jefe de Ventas'],

    // Permiso spatie que también da "ver todo" en los listados. Déjalo en null para ignorarlo.
    'cotizacion_pdf_permiso_ver_todo' => 'Ver todas las Cotizaciones',

    // Minutos de vida del enlace del PDF (una previsualización se reabre más que una descarga).
    'cotizacion_pdf_enlace_minutos' => 30,

    'cotizaciones_pdf_servicio' => [
        'max_instrumentos' => 100, // entre 1 y 500; nunca recortar silenciosamente
        'origen_condiciones' => 'pantalla', // reproduce las ocho condiciones fijas de generarPDF
        'tipo_cambio_texto' => '3.80', // texto fijo del frontend; NO se usa para convertir importes
    ],

    // ── Pendientes ──
    'zona_horaria' => 'America/Lima',   // para "hoy" al validar fechas de servicio (si ya existe, no la repitas)

    'pendientes_roles' => ['Asesor de Ventas', 'Jefe de Ventas', 'Gerencia', 'Area de TI', 'Administracion'],   // pueden consultar
    'pendientes_roles_ve_todo' => ['Jefe de Ventas', 'Gerencia', 'Area de TI', 'Administracion'],                     // ven todos (el Asesor solo los suyos)
    'pendientes_roles_escritura' => ['Asesor de Ventas', 'Gerencia', 'Area de TI', 'Jefe de Ventas'],                                             // registran, editan y eliminan (solo los propios)

    // Un pendiente solo se modifica/elimina en estos estados (y sin asignar a un técnico).
    'pendientes_estados_editables' => ['Pendiente'],

    // Servicios que no llevan N° de cotización (igual que la pantalla de detalle).
    'pendientes_servicios_sin_cotizacion' => ['diagnostico tecnico', 'garantia', 'devolucion'],

    // Opcional: lista cerrada de tipos. Si las dejas en null, se aceptan los que ya existen en el sistema
    // (se avisa cuando uno es nuevo). Ej.: ['Calibración', 'Mantenimiento', 'Venta', 'Diagnóstico técnico', 'Garantía']
    'pendientes_tipos_servicio' => null,
    'pendientes_tipos_instrumento' => null,
    'pendientes_codigos_catalogo' => ['balanza' => 1, 'termometro' => 2, 'pesas' => 3, 'otro' => 0],

    // ── Clientes ──
    // Quién puede REGISTRAR y EDITAR clientes desde el asistente.
    'clientes_roles_edicion' => ['Asesor de Ventas', 'Jefe de Ventas', 'Gerencia', 'Area de TI'],
    // Quién puede editar CUALQUIER cliente. El resto solo edita clientes libres, propios o vencidos.
    'clientes_roles_edita_cualquiera' => ['Jefe de Ventas', 'Gerencia', 'Area de TI'],
    // Quién puede LIBERAR cualquier cliente (igual que la liberación masiva de la pantalla) y quién puede ASIGNAR a un asesor.
    'clientes_roles_liberar' => ['Jefe de Ventas', 'Gerencia', 'Administracion', 'Area de TI'],
    'clientes_roles_asignar' => ['Jefe de Ventas', 'Gerencia', 'Administracion', 'Area de TI'],
    // Un Asesor de Ventas puede liberar SUS PROPIOS clientes (false = solo los roles de arriba).
    'clientes_asesor_libera_propios' => false,
    // Roles que debe tener un usuario para poder recibir clientes asignados.
    'clientes_roles_asignables' => ['Asesor de Ventas'],
    // Categorías (clientes.ctg) válidas además de las de la tabla tbsector_comercial.
    // 'clientes_categorias_base'      => ['Vip', 'Potencial', 'Regular', 'Sin Informacion'],

    // ── Validación de RUC con SUNAT (mismo servicio que usa ClienteController::store) ──
    // El token YA NO va en el código: ponlo en el .env  →  SUNAT_API_TOKEN=...
    'sunat_api_url' => env('SUNAT_API_URL', 'https://api.apis.net.pe/v2/sunat/ruc'),
    'sunat_api_token' => env('SUNAT_API_TOKEN'),
    'sunat_timeout' => 10,   // segundos

    // ── Productos ──

    'almacen_catalogo_habilitar_escrituras' => true,
    // Lectura global del catalogo (TbproductoController::index y ::edit).
    'almacen_catalogo_roles' => [
        'Administracion',
        'Gerencia',
        'Area de TI',
        'Asesor de Ventas',
        'Jefe de Ventas',
        'Operaciones',
    ],
    'almacen_catalogo_roles_ve_todo' => [
        'Administracion',
        'Gerencia',
        'Area de TI',
        'Jefe de Ventas',
    ],
    // Basado en canRegister de TbproductoController::index, nunca amplie estos roles sin verificar rutas.
    'almacen_catalogo_roles_registro' => [
        'Administracion',
        'Gerencia',
        'Area de TI',
        'Asesor de Ventas',
        'Jefe de Ventas',
    ],
    // Basado en edit/update del controlador original. Gerencia accede por roles_acceso_total.
    'almacen_catalogo_roles_edicion' => [
        'Administracion',
        'Gerencia',
        'Area de TI',
        'Operaciones',
        'Asesor de Ventas',
        'Jefe de Ventas',
    ],

    // Habilitar otros roles solo despues de revisar middlewares y politica de negocio.
    'almacen_catalogo_roles_altas_catalogos' => [
        'Administracion',
        'Gerencia',
        'Area de TI',
        'Operaciones',
        'Asesor de Ventas',
        'Jefe de Ventas',
    ],

    // Valores que permite el SELECT del componente Vue (TbproductoRequest tambien acepta USD,
    // pero la pantalla actual solo muestra S/ y $). No ampliar sin validar el negocio.
    'almacen_catalogo_monedas' => ['S/', '$'],
    // Create.vue y Edit.vue: valores exactos del SELECT; Create.vue lo exige.
    'almacen_catalogo_garantias' => ['3 meses', '6 meses', '1 año', 'sin garantia'],

    'almacen_catalogo_habilitar_archivado' => true,
    // Basado en TbproductoController::destroy; Gerencia tambien tiene acceso total.
    'almacen_catalogo_roles_archivar' => ['Administracion', 'Area de TI'],
    // Fotos: puede subir quien puede editar ficha, pero no se habilita hasta validar el flujo.
    'almacen_catalogo_habilitar_fotos' => true,
    'almacen_catalogo_roles_fotos' => [
        'Administracion',
        'Area de TI',
        'Operaciones',
        'Asesor de Ventas',
        'Jefe de Ventas',
    ],
    'almacen_catalogo_foto_minutos' => 10,

    // ------- Hoja de Salida -----
    'hojas_salida_roles' => ['Operaciones', 'Servicio Tecnico', 'Gerencia', 'Area de TI'],

    // Edición MCP confirmada: mismos alcances; únicamente completada bloquea.
    'hojas_salida_roles_edicion' => ['Operaciones', 'Servicio Tecnico', 'Gerencia', 'Area de TI'],
    'hojas_salida_roles_ve_todo' => ['Operaciones', 'Gerencia', 'Area de TI'],
    'hojas_salida_estados' => ['pendiente', 'en_proceso', 'completada'],
    'hojas_salida_max_dias' => 93,
    'hojas_salida_pdf_roles' => ['Operaciones', 'Servicio Tecnico', 'Gerencia', 'Area de TI'],
    'hojas_salida_pdf_enlace_minutos' => 10,

    'reportes_roles' => ['Servicio Tecnico', 'Operaciones', 'Gerencia', 'Area de TI'],
    'reportes_roles_escritura' => ['Servicio Tecnico', 'Operaciones', 'Gerencia', 'Area de TI'],
    'reportes_roles_ve_todo' => ['Operaciones', 'Gerencia', 'Area de TI'],

    // Historial Hojaservico/Index: informes (diagnosticos) y resultados (informes_finales).
    // Técnicos: solo asignados. Personal comercial/administrativo y Operaciones: lectura global como en Index.
    'historial_reportes_roles' => ['Servicio Tecnico', 'Operaciones', 'Asesor de Ventas', 'Jefe de Ventas', 'Administracion'],
    'historial_reportes_roles_ve_todo' => ['Operaciones', 'Asesor de Ventas', 'Jefe de Ventas', 'Administracion'],
    'historial_reportes_roles_finalizar' => ['Operaciones'],
    'historial_reportes_permiso_finalizar' => 'Finalizar Reporte Operaciones',
    'historial_reportes_max_dias' => 93,

    // Detalle Show.vue: permisos separados para edición de textos y ficha.
    'detalle_reportes_roles_edicion' => ['Servicio Tecnico', 'Operaciones', 'Administracion'],
    'detalle_reportes_roles_supervisor' => ['Operaciones', 'Administracion'],
    'detalle_reportes_roles_finalizar' => ['Operaciones'],
    'reporte_tecnico_pdf_roles' => ['Servicio Tecnico', 'Operaciones', 'Administracion', 'Asesor de Ventas', 'Jefe de Ventas'],
    'reporte_tecnico_pdf_enlace_minutos' => 10,

    'certificaciones_roles' => ['Administracion', 'Gerencia', 'Area de TI', 'Asesor de Ventas', 'Jefe de Ventas', 'Operaciones'],
    // El bloqueo de la pantalla prevalece incluso con múltiples roles.
    'certificaciones_roles_bloqueados' => ['Servicio Tecnico', 'Area de Almacen'],
    'certificaciones_roles_registrar' => ['Administracion', 'Gerencia', 'Area de TI', 'Asesor de Ventas'],
    'certificaciones_roles_editar_fechas' => ['Administracion', 'Gerencia', 'Area de TI', 'Asesor de Ventas', 'Jefe de Ventas', 'Operaciones'],
    'certificaciones_roles_renovar' => ['Administracion', 'Gerencia', 'Area de TI', 'Asesor de Ventas', 'Jefe de Ventas', 'Operaciones'],
    'certificaciones_roles_subir' => ['Administracion', 'Gerencia', 'Area de TI', 'Asesor de Ventas', 'Jefe de Ventas', 'Operaciones'],
    'certificaciones_roles_eliminar' => ['Area de TI', 'Gerencia', 'Administracion'],
    'certificaciones_formas' => ['PARALELEPIPEDA', 'CILINDRICA CON BOTON', 'CILINDRICA SOLA'],
    'certificaciones_materiales' => ['ACERO INOXIDABLE', 'BRONCE', 'FIERRO FUNDIDO', 'CROMADO', 'BRONCE CROMADO', 'ALEACION METALICA'],

    'aprobacion_flexible' => [
        'modo' => env('BALINSA_APROBACION_FLEXIBLE_MODO', 'desactivado'),
        'version' => '1.0.0',
    ],





];