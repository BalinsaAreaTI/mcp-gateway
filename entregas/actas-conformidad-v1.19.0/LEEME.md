# Actas de conformidad — v1.19.0

Integrado en el gateway local y en la copia local de Sistema_Balinsa_23_10. No desplegado en producción. No se modificaron las pantallas Vue ni sus controladores, ni se cargaron archivos en la base de negocio.

## Herramientas

| Herramienta | Uso |
|---|---|
| `operaciones_buscar_actas_conformidad` | Buscar por número de acta, tarea/hoja, cliente, estado y fechas; paginación de hasta 20. Sin fechas busca todo el historial. |
| `operaciones_ver_acta_conformidad` | Datos del acta, hoja de salida, instrumentos, disponibilidad del firmado y posibilidad de carga. |
| `operaciones_generar_pdf_acta_conformidad` | Formato para firma manual, modos `ver` y `descargar`. |
| `operaciones_preparar_carga_acta_firmada` | Enlace al formulario para elegir y guardar JPG, PNG o PDF de hasta 10 MB. |
| `operaciones_ver_archivo_acta_firmada` | Visualizar o descargar la imagen/PDF firmado ya subido. |

El número visible de acta es `id`; `idActa` es la referencia de orden/cotización. `tarea_id` identifica la hoja de salida, no el pendiente de Ventas. Algunas actas históricas pueden no tener tarea; se devuelve null. El detalle puede contener varios instrumentos vinculados; el PDF conserva el primero como hace la pantalla.

## Reglas

- Lectura: Gerencia, Administracion, Area de TI, Operaciones y Servicio Tecnico. Visibilidad de todas las actas como en el listado actual; Servicio Tecnico ya tiene acceso mediante SubirActa. Configuración en las dos claves del bloque entregado; acceso total usa la configuración existente.
- Carga: mismos cinco roles, compatibles con ActaFirmadaController. Los reportes con `estado_global = cerrado` bloquean la emisión del enlace, apertura y guardado. Lectura y PDF permanecen disponibles.
- Enlaces relativos firmados, duración 10 minutos, construidos con APP_URL. Requieren sesión del mismo usuario del token. Se revalidan permisos y registro al abrir y guardar. Solo se ignoran los parámetros de seguimiento enumerados en el controlador, nunca u, d o expires.
- El formulario usa CSRF y confirmación explícita. Si ya existe archivo, avisa que será reemplazado. El guardado llama al controlador existente: valida MIME/tamaño, asigna UUID, bloquea la fila, actualiza estado y fecha_firma y limpia el archivo anterior.
- No se reciben rutas ni identidades arbitrarias desde el agente. Para visualizar archivos se comprueban directorio, realpath y MIME; las respuestas no se almacenan en caché.
- Los archivos siguen en el directorio público `actas_firmadas`, por compatibilidad con la pantalla. Las URLs firmadas protegen esta nueva ruta, pero no convierten en privados los archivos públicos preexistentes. Cambiar su almacenamiento exige modificar también la pantalla.
- El PDF reproduce el contenido de Index.vue: cabecera/logo, datos generales, orden/cotización, proveedor/cliente, fechas, declaración y firmas en blanco. Características manuales según balanza/pesa/termómetro; no inserta firmas ni utiliza el escaneo. Cambia el motor jsPDF a Dompdf, por lo que no se garantiza equivalencia exacta de saltos de línea.

## Instalar en el sistema (Laravel 10)

1. Copiar los archivos PHP de [sistema/](sistema/) a sus rutas equivalentes. Se necesitan ActaConformidadController::obtenerListadeActaClientesPdf y ActaFirmadaController::guardar de la versión revisada; no sustituir esos controladores.
2. Añadir las dos claves de [mcp-actas-BLOQUE.txt](sistema/config/mcp-actas-BLOQUE.txt) dentro del array de config/mcp.php. No reemplazar el resto de configuración.
3. Dentro del grupo EXISTENTE api/mcp con `auth:sanctum`, `abilities:mcp` y `throttle:60,1`, agregar `require __DIR__.'/mcp-actas-api.php';`.
4. Desde routes/web.php, fuera de otros grupos y antes de fallbacks, agregar `require __DIR__.'/mcp-actas-web.php';`. Conserva sesión y CSRF; no añadir middleware signed: la firma se verifica dentro del controlador.
5. Mantener `barryvdh/laravel-dompdf` instalado y habilitar GD y mbstring. Debe existir el logo `public/img/LOGO BALINSA-SIN SOMBRA.png`. Datos fijos en [acta_pdf.php](sistema/config/acta_pdf.php).
6. APP_URL debe ser la URL real HTTPS sin barra final. El servidor debe permitir archivos de 10 MB: ajustar upload_max_filesize, post_max_size y límite del proxy con margen para multipart.
7. Ejecutar `php artisan optimize:clear`, `php artisan config:clear` y `php artisan route:list --path=actas`. Deben figurar 5 rutas api/mcp/operaciones/actas y 4 rutas web mcp/actas.

No requiere migraciones. Instalar primero sistema y luego gateway. Las claves/rutas ya están integradas en la copia local.

## Instalar en el gateway (Laravel 12)

Copiar [gateway/app/Mcp/Tools/](gateway/app/Mcp/Tools/) y aplicar [BalinsaServer-BLOQUE.txt](gateway/app/Mcp/Servers/BalinsaServer-BLOQUE.txt): imports, lista de herramientas, instrucciones y versión 1.19.0. El gateway local ya contiene estos cambios. El paquete no reemplaza el servidor completo para conservar otras herramientas.

Ejecutar `composer dump-autoload -o --no-scripts` y `php artisan optimize:clear`. En hosting usar `composer2` si corresponde, sin actualizar dependencias. Recrear el conector de Claude/ChatGPT para renovar su catálogo.

## Verificación realizada

- `php artisan test --compact --filter=Actas`: **13 pruebas, 75 aserciones, todas correctas**. SQLite en memoria, sin modificar datos reales.
- Cobertura: filtros/paginación, acta-tarea-instrumento, roles, enlaces vencidos/alterados/usuario distinto, tracking permitido, permisos revocados, reporte cerrado en emisión/apertura/guardado, confirmación, ruta arbitraria, descarga del firmado y cabeceras, esquemas y registro de cinco herramientas, características manuales y escape HTML del PDF.
- PHP lint correcto para archivos nuevos y servidor MCP. En la copia local del sistema: rutas API/web y config también sin errores; route:list muestra las nueve rutas nuevas.
- Dompdf real generó tres muestras de una página: balanza, pesa y termómetro. La CLI necesitó `php -d extension=gd`. La revisión visual del PDF quedó pendiente: el visor automatizado no completó el renderizado de canvas. No se afirma fidelidad visual exacta.
- Pruebas relacionadas ampliadas: 16 pasaron y falló una prueba existente de HojasSalidaToolsTest, que espera una consulta sin los filtros instrumento_id/numero_pdf/grupo_pdf que la herramienta ya envía como null. Ambos archivos estaban sin cambios; no forma parte de esta entrega.
- NO probado: carga completa multipart con sesión/CSRF reales y reemplazo de un archivo de negocio, concurrencia en MySQL, extremo a extremo desde Claude/ChatGPT, móvil y despliegue en hosting. El guardado reutiliza el controlador de pantalla existente; no se simuló una carga real a producción.

Los tests entregados se ejecutan desde el gateway conservando la ruta entregas/actas-conformidad-v1.19.0/sistema; copiar la carpeta tests a su raíz para repetirlos.

## Prueba de aceptación tras instalar

1. “Muéstrame las actas de la hoja de salida 123”: confirmar tarea_id y consultar el detalle.
2. “Ver el PDF del acta 4” / “Descargar el PDF del acta 4”: probar ambas disposiciones.
3. “Dame el enlace para subir el acta 4 firmada”: iniciar sesión como el mismo empleado, elegir JPG/PNG/PDF y confirmar. Consultar el detalle para comprobar firmada y fecha_firma.
4. “Ver la imagen firmada del acta 4”: debe mostrar el archivo guardado, no el formato en blanco.
5. Probar archivos inválidos y mayores de 10 MB, reemplazo, reporte cerrado, usuario distinto, rol sin acceso y enlace vencido. Confirmar que la carga fallida no reemplaza el archivo anterior.

## Hallazgos conservados

La pantalla PDF deja las características del instrumento en blanco incluso cuando hay datos, utiliza solo el primer instrumento vinculado y deja las firmas para completar a mano. La implementación conserva estas decisiones. No cambia los permisos ni la exposición pública del almacenamiento existente fuera de estas rutas nuevas.
