# Cotización por servicio desde informe — v1.18.0

Implementa el flujo de `CServicioController::cotiza` y `C_Servicio/Servicio.vue`: buscar un reporte RPT, revisar sus diagnósticos y crear una cotización Pendiente cuyo `n_informe` conserva el ID del reporte. La herramienta directa continúa disponible.

## Herramientas

1. `ventas_buscar_informes_cotizables`: busca por cliente o número RPT y fechas (90 días por defecto, máximo 366), 20 resultados por página.
2. `ventas_ver_informe_cotizable`: consulta cliente e instrumentos, diagnóstico y trabajos sugeridos; 20 instrumentos por página.
3. `ventas_registrar_cotizacion_servicio_desde_informe`: recibe `reporte_id`, `plantilla_id`, `moneda`, `forma_pago` e `instrumentos: [{id, precio, titulo_servicio?, trabajos?}]`, más las condiciones comerciales opcionales de la herramienta directa.

Consultar todas las páginas necesarias, elegir instrumentos con diagnóstico registrado y confirmar el resumen con el usuario antes de guardar. Si no se envían trabajos se toman los sugeridos del último diagnóstico; un texto vacío explícito los deja vacíos. Si se omite título se usa el de la plantilla. Los trabajos heredados que excedan 2000 caracteres requieren enviar un texto comercial resumido y confirmado.

El sistema deriva cliente, asesor, datos técnicos y totales; no acepta sobrescribirlos. Conserva las reglas MCP actuales de roles, cartera de clientes y cotizaciones pendientes de los últimos 14 días. Se reutiliza la configuración existente, incluido el límite de inactividad MCP (2 meses por defecto); la pantalla consultada usa 3 meses. No se cambió esa política previa del MCP. La lectura se limita por cartera para asesores; los roles con acceso global conservan su visibilidad. El registro vuelve a comprobar el cliente. No modifica diagnósticos ni resultados. El vínculo persistido es al reporte, como en la pantalla; la tabla de copias de instrumentos no tiene un campo para guardar el ID del instrumento de origen.

## Instalación en el sistema central (Laravel 10)

La actualización ya está integrada en la copia local `Sistema_Balinsa_23_10`. Para instalarla en otro entorno, usar los siguientes pasos; desplegar solo el gateway no habilita los endpoints.

1. Respaldar y comparar el controlador actual antes de integrar [McpCotizacionesController.php](sistema/app/Http/Controllers/Mcp/McpCotizacionesController.php). La copia parte de la versión local del sistema revisada durante esta tarea. Si hay cambios posteriores, integrar el trait, el método privado `guardarServicio` y el parámetro de origen, conservando esos cambios.
2. Copiar [CotizacionesDesdeInforme.php](sistema/app/Http/Controllers/Mcp/CotizacionesDesdeInforme.php) a la misma carpeta del controlador.
3. Copiar [cotizaciones-desde-informe.php](sistema/routes/cotizaciones-desde-informe.php) a `routes/` y añadir `require __DIR__.'/cotizaciones-desde-informe.php';` **dentro del grupo existente de rutas MCP**, con el prefijo `mcp` y su autenticación/restricción de token. No cargar en el nivel público de rutas ni dentro de otro prefijo de cotizaciones.
4. Ejecutar `php artisan optimize:clear` y comprobar `php artisan route:list --path=api/mcp/ventas/cotizaciones`.

No necesita migraciones ni nuevas claves de configuración. Se mantienen las tablas existentes `reportes`, `reportes_instrumentos`, `diagnosticos`, `cservicios` e `instru_cotserv_directa`.

## Gateway (Laravel 12)

Los archivos de `gateway/` contienen las tres herramientas nuevas y la descripción actualizada de la herramienta directa. Registrar las tres nuevas clases en `BalinsaServer` (imports y `$tools`), añadir la instrucción de usar este flujo para informes y subir la versión a 1.18.0. Estos cambios ya están aplicados en el workspace; el paquete no reemplaza el servidor completo para evitar sobrescribir otras herramientas en desarrollo.

## Validación

Desde el gateway: `php vendor/bin/phpunit tests/Feature/CotizacionDesdeInformeTest.php tests/Unit/CotizacionDesdeInformeToolsTest.php tests/Unit/VentasCotizacionContractTest.php`.

Las pruebas usan SQLite en memoria y el controlador entregado; el modelo de prueba sustituye únicamente la generación del número que en producción usa `SHOW TABLE STATUS` de MySQL. Comprobar en el entorno de integración autenticación real, numeración/concurrencia MySQL y generación del PDF con la herramienta existente antes de desplegar. No se crearon cotizaciones en la base de negocio.
