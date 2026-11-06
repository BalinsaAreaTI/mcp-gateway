<x-mcp::app :title="$title">
    <x-slot:head>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; background: var(--color-background-primary, #fff); color: var(--color-text-primary, #192638); }
            main { --accent: #1769c2; --accent-soft: #eef6ff; --accent-border: #b9d6f5;
                width: 100%; max-width: 720px; margin: 0 auto; padding: 17px 14px 22px;
                font: 14px/1.55 var(--font-sans, system-ui, sans-serif); }
            main[data-level="2"] { --accent: #986112; --accent-soft: #fff8e9; --accent-border: #eed6a0; }
            main[data-level="3"] { --accent: #a22e30; --accent-soft: #fff3f1; --accent-border: #efc5c2; }
            .brand { display: flex; align-items: center; gap: 9px; margin-bottom: 15px; }
            .brand-mark { font-weight: 850; border-radius: 9px; width: 32px; height: 32px;
                display: grid; place-items: center; background: #162f51; color: #fff; font-size: 19px; }
            .brand small { display: block; color: var(--color-text-secondary, #66758b); font-size: 11px; letter-spacing: .07em; }
            .brand b { display: block; font-size: 13px; letter-spacing: .035em; }
            .hero { padding: 16px 16px 14px; border: 1px solid var(--accent-border); border-radius: 12px;
                background: var(--accent-soft); border-left: 4px solid var(--accent); }
            .eyebrow { font-size: 11px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; color: var(--accent); }
            h1 { font-size: 20px; line-height: 1.25; margin: 6px 0 7px; letter-spacing: -.015em; }
            h2 { font-size: 13px; letter-spacing: .025em; margin: 0 0 11px; }
            p { margin: 0; }
            .hero-desc { color: var(--color-text-secondary, #58677d); font-size: 13px; }
            .info-strip { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 12px; align-items: center; }
            .chip { border: 1px solid var(--accent-border); color: var(--color-text-primary, #192638);
                border-radius: 7px; padding: 4px 9px; background: var(--color-background-primary, #fff);
                font-size: 11px; font-weight: 650; }
            .panel { border: 1px solid var(--color-border-primary, #dce3eb); border-radius: 11px;
                padding: 15px 16px; margin-top: 11px; background: var(--color-background-primary, #fff); }
            .field-grid { display: grid; grid-template-columns: 82px minmax(0,1fr); gap: 9px 10px; }
            dt { margin: 0; color: var(--color-text-secondary, #647187); font-size: 12px; }
            dd { margin: 0; overflow-wrap: anywhere; font-weight: 550; }
            .divider { height: 1px; background: var(--color-border-secondary, #e9edf2); margin: 12px 0; }
            pre { white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; font: inherit;
                font-weight: 400; line-height: 1.6; max-height: 230px; overflow: auto; }
            .file { display: flex; align-items: flex-start; gap: 8px; padding: 10px 0;
                border-top: 1px solid var(--color-border-secondary, #edf0f4); }
            .file:first-child { border-top: 0; padding-top: 0; }
            .file:last-child { padding-bottom: 0; }
            .file-icon { color: var(--accent); background: var(--accent-soft); border-radius: 6px;
                flex: 0 0 27px; height: 29px; display: grid; place-items: center; font-size: 10px; font-weight: 750; }
            .file-title { font-weight: 650; font-size: 12px; overflow-wrap: anywhere; }
            .file-meta { color: var(--color-text-secondary, #68758b); font-size: 11px; overflow-wrap: anywhere; }
            .notice { margin-top: 12px; padding: 11px 12px; background: var(--accent-soft);
                border-radius: 8px; border: 1px solid var(--accent-border); font-size: 12px;
                white-space: pre-wrap; overflow-wrap: anywhere; }
            .consent { display: flex; align-items: flex-start; gap: 10px; margin: 14px 1px 11px; font-size: 12px; }
            .consent input { width: 17px; height: 17px; flex: 0 0 auto; margin: 0; accent-color: var(--accent); }
            .actions { display: flex; flex-wrap: wrap; gap: 9px; }
            button { border: 1px solid var(--color-border-primary, #bbc6d3); border-radius: 8px;
                padding: 10px 13px; background: var(--color-background-secondary, #f6f8fb);
                color: var(--color-text-primary, #192638); font: inherit; font-size: 12px;
                font-weight: 750; cursor: pointer; }
            button.primary { border-color: var(--accent); background: var(--accent); color: #fff; }
            button:disabled { opacity: .48; cursor: not-allowed; }
            button:focus-visible, input:focus-visible { outline: 3px solid #e2a12b; outline-offset: 3px; }
            .foot { font-size: 11px; color: var(--color-text-secondary, #68758b); margin: 10px 1px 0; }
            #notice { font-size: 12px; margin-top: 10px; white-space: pre-wrap; overflow-wrap: anywhere; }
            @media (max-width: 420px) { h1 { font-size: 18px; } main { padding: 12px 9px 19px; }
                .panel { padding: 13px 12px; } .field-grid { grid-template-columns: 70px minmax(0,1fr); } }
        </style>
        <script type="module">
            createMcpApp(async (app) => {
                const $ = (name) => document.getElementById(name);
                let id = null;
                let registro = null;
                let resumen = null;
                let trabajando = false;
                const escribir = (name, value) => { $(name).textContent = String(value ?? ''); };
                const avisar = (message) => escribir('notice', message);

                function extraer(result) {
                    if (!result || result.isError) throw new Error('No se pudo consultar la solicitud. Comprueba los permisos y el estado.');
                    if (result.structuredContent && typeof result.structuredContent === 'object') return result.structuredContent;
                    const item = (result.content || []).find(x => x.type === 'text' && typeof x.text === 'string');
                    if (!item) throw new Error('La respuesta del sistema esta vacia.');
                    const data = JSON.parse(item.text);
                    if (!data || typeof data !== 'object') throw new Error('Respuesta de servidor no valida.');
                    return data;
                }
                function urlSegura(valor) {
                    try {
                        const u = new URL(String(valor || ''));
                        return u.protocol === 'https:' && u.username === '' && u.password === ''
                            && u.hash === '' ? u.href : null;
                    } catch { return null; }
                }
                function vigente(data) {
                    if (!data?.vence_at) return false;
                    const ms = Date.parse(String(data.vence_at));
                    return Number.isFinite(ms) && ms > Date.now();
                }
                function tipo(data) {
                    const nivel = Number(data?.nivel);
                    if (nivel === 1) return 'rapida';
                    if (nivel === 2) return 'web';
                    if (nivel === 3) return 'bloqueado';
                    return 'desconocido';
                }
                function estadoHumano(estado) {
                    const nombres = {
                        preparado: 'Pendiente de autorizacion',
                        encolado: 'Autorizado y en proceso',
                        procesando: 'Procesando el envio',
                        smtp_aceptado: 'Aceptado por el servidor de correo',
                        fallido: 'No se pudo completar el envio',
                        resultado_incierto: 'Resultado pendiente de verificar',
                        vencido: 'Solicitud vencida',
                        documento_cambiado: 'Se requiere nueva preparacion',
                    };
                    return nombres[String(estado || '')] || 'Estado no disponible';
                }
                function detalleEstado(estado) {
                    switch (String(estado || '')) {
                        case 'encolado':
                        case 'procesando':
                            return 'El correo ya fue autorizado y esta en proceso. Puedes actualizar su estado mas adelante; aun no tenemos confirmacion de aceptacion por el servidor.';
                        case 'smtp_aceptado':
                            return 'El servidor de correo acepto el mensaje. Esto no confirma que haya llegado a la bandeja del destinatario ni que haya sido leido.';
                        case 'fallido':
                            return 'No se pudo completar el envio. Consulta el detalle con soporte antes de intentar una solicitud nueva.';
                        case 'resultado_incierto':
                            return 'No se pudo confirmar el resultado del servidor. No vuelvas a enviar automaticamente; solicita una revision.';
                        case 'vencido':
                        case 'documento_cambiado':
                            return 'Esta solicitud ya no puede autorizarse. Prepara un correo nuevo.';
                        default:
                            return 'Consulta el estado de la solicitud.';
                    }
                }
                function permitida(data) {
                    if (!data || data.estado !== 'preparado' || !vigente(data)) return false;
                    if (tipo(data) === 'rapida') {
                        return data.metodo === 'agente' && data.confirmacion_rapida_disponible === true
                            && /^[a-f0-9]{64}$/.test(String(data.resumen_sha256 || ''));
                    }
                    if (tipo(data) === 'web') {
                        return data.metodo === 'web' && !!urlSegura(data.url_aprobacion_web);
                    }
                    return false;
                }
                function bloquear(si) {
                    trabajando = si;
                    $('refresh').disabled = si || !id;
                    $('open').disabled = si || !permitida(registro) || !$('accept').checked;
                }
                function dibujar(data) {
                    const nuevoId = Number(data?.solicitud_id);
                    if (!Number.isSafeInteger(nuevoId) || nuevoId <= 0) throw new Error('ID de solicitud invalido.');
                    if (id !== null && id !== nuevoId) throw new Error('La respuesta pertenece a otra solicitud.');
                    id = nuevoId;
                    registro = data;
                    resumen = /^[a-f0-9]{64}$/.test(String(data.resumen_sha256 || ''))
                        ? data.resumen_sha256 : null;
                    const nivel = Number(data.nivel);
                    const variante = tipo(data);
                    $('appRoot').dataset.level = [1, 2, 3].includes(nivel) ? String(nivel) : '0';
                    escribir('reference', 'Solicitud N.\u00ba ' + id);
                    escribir('state', 'Estado: ' + estadoHumano(data.estado));
                    escribir('levelLabel', variante === 'rapida' ? 'NIVEL 1 \u00b7 CONFIRMACIÓN RÁPIDA'
                        : variante === 'web' ? 'NIVEL 2 \u00b7 APROBACIÓN WEB'
                        : variante === 'bloqueado' ? 'NIVEL 3 \u00b7 BLOQUEADO' : 'CLASIFICACIÓN PENDIENTE');
                    escribir('heading', variante === 'rapida' ? 'Confirmar envío de correo'
                        : variante === 'web' ? 'Revisar para aprobación web'
                        : variante === 'bloqueado' ? 'Distribución no autorizada' : 'Revisar solicitud');
                    escribir('heroDesc', variante === 'web'
                        ? 'Esta solicitud requiere la aprobación convencional desde tu sesión autenticada en Balinsa.'
                        : variante === 'rapida' ? 'Revisa todos los datos antes de continuar a la autorización segura.'
                        : 'Esta solicitud no admite una autorización desde la tarjeta.');
                    if (data.estado !== 'preparado') {
                        escribir('levelLabel', 'SEGUIMIENTO DEL ENVIO');
                        escribir('heading', 'Estado del correo');
                        escribir('heroDesc', detalleEstado(data.estado));
                    }
                    escribir('from', data.de);
                    escribir('to', data.para);
                    escribir('subject', data.asunto);
                    escribir('message', data.mensaje);
                    escribir('expiry', data.vence_at ? 'Vigencia: ' + data.vence_at : 'Sin vigencia informada');
                    const files = $('attachments');
                    files.replaceChildren();
                    const adjuntos = Array.isArray(data.adjuntos) ? data.adjuntos : [];
                    if (!adjuntos.length) {
                        const item = document.createElement('p');
                        item.textContent = 'No se recibio el listado de adjuntos.';
                        files.appendChild(item);
                    }
                    adjuntos.forEach(archivo => {
                        const row = document.createElement('div'); row.className = 'file';
                        const ico = document.createElement('span'); ico.className = 'file-icon'; ico.textContent = 'PDF';
                        const content = document.createElement('div');
                        const titulo = document.createElement('div'); titulo.className = 'file-title';
                        titulo.textContent = String(archivo.titulo || archivo.archivo || 'Documento');
                        const sub = document.createElement('div'); sub.className = 'file-meta';
                        sub.textContent = [archivo.archivo, archivo.tipo, archivo.referencia].filter(x => x !== null && x !== undefined && String(x) !== '').join(' \u00b7 ');
                        content.append(titulo, sub); row.append(ico, content); files.appendChild(row);
                    });
                    const enabled = permitida(data);
                    $('accept').disabled = !enabled;
                    $('accept').checked = false;
                    $('open').textContent = variante === 'web' ? 'Abrir aprobación en Balinsa Web'
                        : 'Continuar a autorización segura';
                    escribir('rule', variante === 'web'
                        ? 'Abrir Balinsa Web no aprueba ni encola el correo. La autorización exige tu sesión, revisión del documento y confirmación web.'
                        : 'La autorización requiere identidad web y consentimiento. Una solicitud encolada aún no ha sido entregada.');
                    avisar(enabled
                        ? (variante === 'web'
                            ? 'Nivel 2: abre la aprobación web existente. La tarjeta no puede autorizar directamente.'
                            : 'Nivel 1: confirma los datos para continuar.')
                        : variante === 'bloqueado' ? 'Nivel 3: envío bloqueado por política.'
                        : data.estado !== 'preparado' ? 'La solicitud ya no está preparada. Consulta su estado.'
                        : !vigente(data) ? 'La solicitud ha vencido. Prepara una nueva.'
                        : 'El enlace de autorización no está disponible. Utiliza el flujo web convencional.');
                    if (data.estado !== 'preparado') {
                        escribir('rule', detalleEstado(data.estado));
                        avisar(detalleEstado(data.estado));
                    }
                    bloquear(false);
                }
                app.ontoolinput = (params) => {
                    const n = Number(params?.arguments?.solicitud_id);
                    if (Number.isSafeInteger(n) && n > 0 && id === null) {
                        id = n;
                        bloquear(false);
                    }
                };
                app.ontoolresult = (result) => {
                    try {
                        const data = extraer(result);
                        if (data && 'de' in data && 'adjuntos' in data) dibujar(data);
                    } catch (error) { avisar('No se pudo mostrar la revision: ' + error.message); bloquear(false); }
                };
                $('accept').addEventListener('change', () => bloquear(false));
                $('refresh').addEventListener('click', async () => {
                    if (!id || trabajando) return;
                    bloquear(true); avisar('Consultando estado...');
                    try {
                        const data = extraer(await app.callServerTool({
                            name: 'comunicaciones_tarjeta_actualizar_estado', arguments: { solicitud_id: id },
                        }));
                        dibujar(data);
                    } catch (error) { avisar('No se pudo actualizar: ' + error.message); }
                    finally { bloquear(false); }
                });
                $('open').addEventListener('click', async () => {
                    if (trabajando || !$('accept').checked || !permitida(registro) || !id) return;
                    bloquear(true);
                    try {
                        let destino = null;
                        if (tipo(registro) === 'web') {
                            // NIVEL 2: NO usar crear_enlace ni confirmar via MCP.
                            // Abrir SOLO la ruta de aprobacion web existente, devuelta por Laravel 10.
                            destino = urlSegura(registro.url_aprobacion_web);
                        } else if (tipo(registro) === 'rapida') {
                            // NIVEL 1: conserva exactamente el flujo seguro v1.2.0.
                            if (!resumen) throw new Error('Falta la huella del contenido.');
                            const respuesta = extraer(await app.callServerTool({
                                name: 'comunicaciones_tarjeta_crear_enlace',
                                arguments: { solicitud_id: id, resumen_sha256: resumen },
                            }));
                            if (Number(respuesta.solicitud_id) !== id || respuesta.requiere_sesion_web !== true)
                                throw new Error('El enlace no pertenece a esta solicitud.');
                            destino = urlSegura(respuesta.url_confirmacion);
                        }
                        if (!destino) throw new Error('No existe un enlace HTTPS valido para esta solicitud.');
                        avisar('Abriendo Balinsa Web. La autorización final ocurre solo en el formulario web autenticado.');
                        const result = await app.openLink({ url: destino });
                        if (result?.isError) {
                            avisar('El cliente no permitió abrir la página. No se autorizó ningún correo.');
                        }
                    } catch (error) { avisar('No se pudo abrir la autorización: ' + error.message); }
                    finally { bloquear(false); }
                });
                bloquear(false);
            });
        </script>
    </x-slot:head>
    <main id="appRoot" data-level="0">
        <header class="brand"><div class="brand-mark" aria-hidden="true">B</div>
            <div><b>BALINSA</b><small>COMUNICACIONES MCP</small></div></header>
        <section class="hero" aria-labelledby="heading">
            <span id="levelLabel" class="eyebrow">CARGANDO SOLICITUD</span>
            <h1 id="heading">Revisar una solicitud de correo</h1>
            <p id="heroDesc" class="hero-desc">Consulta los datos preparados antes de autorizar.</p>
            <div class="info-strip"><span id="reference" class="chip">Solicitud pendiente</span>
                <span id="state" class="chip">Estado por consultar</span></div>
        </section>
        <section class="panel" aria-label="Datos del correo">
            <dl class="field-grid"><dt>De</dt><dd id="from">...</dd>
                <dt>Para</dt><dd id="to">...</dd><dt>Asunto</dt><dd id="subject">...</dd></dl>
        </section>
        <section class="panel"><h2>Mensaje completo</h2><pre id="message">...</pre></section>
        <section class="panel"><h2>Documentos adjuntos</h2><div id="attachments" aria-live="polite"></div></section>
        <p class="foot" id="expiry"></p>
        <div id="rule" class="notice">La autorización final se realiza desde Balinsa Web.</div>
        <label class="consent"><input type="checkbox" id="accept" disabled>
            <span>He revisado el remitente, destinatario, asunto, mensaje completo y adjuntos de esta solicitud.</span></label>
        <div class="actions"><button id="open" class="primary" disabled>Continuar a autorización segura</button>
            <button id="refresh" disabled>Actualizar estado</button></div>
        <p id="notice" role="status" aria-live="polite">Esperando la información de la solicitud...</p>
        <p class="foot">Abrir el enlace no equivale a aprobar. La aprobación debe realizarla el empleado en Balinsa Web. La aceptacion por el servidor no garantiza la entrega final.</p>
    </main>
</x-mcp::app>
