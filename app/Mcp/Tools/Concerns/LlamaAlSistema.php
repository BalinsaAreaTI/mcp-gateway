<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\User;
use App\Services\SistemaClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use RuntimeException;

/**
 * Invocacion al Sistema Central y auditoria sin contenido de correos.
 *
 * Parche 0.8.1: NO cambia los parametros enviados al Sistema Central ni
 * las respuestas de las herramientas. Solo sanea la copia de auditoria.
 */
trait LlamaAlSistema
{
    protected function consultar(Request $request, string $ruta, array $query = []): Response
    {
        return $this->llamar($request, 'GET', $ruta, $query, 'Parámetros no válidos: ');
    }

    protected function enviar(Request $request, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
    {
        return $this->llamar($request, 'POST', $ruta, $cuerpo, $prefijo422);
    }

    private function llamar(Request $request, string $metodo, string $ruta, array $datos, string $prefijo422): Response
    {
        $datos = array_filter($datos, fn ($v) => $v !== null && $v !== '');
        $inicio = microtime(true);

        try {
            $usuario = $request->user();
            if (! $usuario instanceof User) {
                throw new RuntimeException('Se requiere un usuario autenticado.', 401);
            }

            $cliente = new SistemaClient($usuario);
            $respuesta = $metodo === 'GET' ? $cliente->get($ruta, $datos) : $cliente->post($ruta, $datos);
        } catch (RuntimeException $e) {
            $status = (int) $e->getCode() ?: 500;
            // Nunca persistir el texto de una excepcion remota: puede contener PII,
            // destinatarios, cuerpos de correo, cabeceras o tokens.
            $this->registrarAccion($request, $metodo, $ruta, $datos, false, $status, 'error_http_' . $status, $inicio);

            // En comunicaciones se evita propagar el texto de errores remotos
            // al agente: podria contener tokens, correos, documentos o secretos.
            // Las otras herramientas conservan sus mensajes previos.
            if ($this->esComunicaciones($ruta)) {
                return Response::error(match ($status) {
                    401     => 'Tu conexion con el sistema vencio. Vuelve a conectar el conector.',
                    403     => 'No tienes permiso para realizar esta operacion de comunicaciones.',
                    404     => 'No se encontro el recurso solicitado o no tienes acceso.',
                    422     => 'No se pudo validar la solicitud. Revisa destinatario, documentos, contenido y permisos.',
                    429     => 'Demasiadas solicitudes seguidas. Intentalo mas tarde.',
                    default => 'No se pudo completar la operacion de comunicaciones.',
                });
            }

            return Response::error(match ($e->getCode()) {
                401     => 'Tu conexión con el sistema venció. Vuelve a conectar el conector desde tu cliente de IA.',
                403     => 'No tienes permiso para esta acción: ' . $e->getMessage(),
                404     => 'No se encontró lo que pediste: ' . $e->getMessage(),
                422     => $prefijo422 . $e->getMessage(),
                429     => 'Demasiadas consultas seguidas. Espera un momento e inténtalo de nuevo.',
                default => 'El sistema no respondió correctamente. Inténtalo más tarde.',
            });
        }

        $detalle = isset($respuesta['id']) && (is_int($respuesta['id']) || (is_string($respuesta['id']) && ctype_digit($respuesta['id'])))
            ? mb_substr('id=' . (string) $respuesta['id'], 0, 255)
            : null;
        $this->registrarAccion($request, $metodo, $ruta, $datos, true, 200, $detalle, $inicio);

        return Response::text(json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Auditoria: cualquier fallo NO debe romper la operacion principal. */
    private function registrarAccion(Request $request, string $metodo, string $ruta, array $datos, bool $ok, int $status, ?string $detalle, float $inicio): void
    {
        try {
            $usuario = $request->user();

            DB::table('agent_actions')->insert([
                'user_id'        => $usuario?->id,
                'remote_user_id' => $usuario?->remote_user_id,
                'tool'           => $this->name ?: class_basename($this),
                'metodo'         => $metodo,
                'ruta'           => $this->rutaAuditada($ruta),
                'params'         => json_encode($this->parametrosAuditados($ruta, $datos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'estado'         => $ok ? 'ok' : 'error',
                'http_status'    => $status,
                'detalle'        => $detalle,
                'duracion_ms'    => (int) round((microtime(true) - $inicio) * 1000),
                'user_agent'     => mb_substr((string) request()->userAgent(), 0, 255),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // No escribir e->getMessage(): errores SQL pueden repetir bindings
            // con datos personales o secretos que causaron el error.
            Log::warning('agent_actions: error de auditoria', ['tipo' => get_class($e)]);
        }
    }

    /**
     * En comunicaciones NO se guardan datos de la solicitud, ni siquiera
     * versiones recortadas. El nombre de la tool, metodo, usuario, estado,
     * duracion y hora ya quedan en las columnas de agent_actions.
     */
    private function parametrosAuditados(string $ruta, array $datos): array
    {
        if ($this->esComunicaciones($ruta)) {
            $resultado = ['registro' => 'solo_metadatos'];
            if (isset($datos['adjuntos']) && is_array($datos['adjuntos'])) {
                $resultado['cantidad_adjuntos'] = min(count($datos['adjuntos']), 1000);
            }
            return $resultado;
        }

        // Las otras tools conservan su auditoria de parametros, pero ahora
        // se ocultan campos de secretos y PII conocidos de forma recursiva.
        return $this->recortarParametros($datos);
    }

    private function esComunicaciones(string $ruta): bool
    {
        $sinQuery = explode('?', explode('#', $ruta, 2)[0], 2)[0];
        $camino = ltrim($sinQuery, '/');
        if (str_starts_with($camino, 'api/mcp/')) {
            $camino = substr($camino, strlen('api/mcp/'));
        }

        return $camino === 'comunicaciones' || str_starts_with($camino, 'comunicaciones/');
    }

    private function rutaAuditada(string $ruta): string
    {
        if ($this->esComunicaciones($ruta)) {
            return 'comunicaciones/[ruta reservada]';
        }

        // Nunca guardar query strings (?signature, ?token, etc.) ni fragmentos.
        $sinQuery = explode('?', explode('#', $ruta, 2)[0], 2)[0];
        $sinControl = preg_replace('/[\x00-\x1F\x7F]/', '', $sinQuery) ?? '';
        $sinCorreo = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[CORREO]', $sinControl) ?? '';

        return mb_substr($sinCorreo, 0, 255);
    }

    /** Redaccion defensiva para herramientas anteriores del gateway. */
    private function recortarParametros(array $datos, int $nivel = 0): array
    {
        if ($nivel >= 5) {
            return ['__limite__' => 'profundidad'];
        }

        $resultado = [];
        $numero = 0;
        foreach ($datos as $clave => $valor) {
            if (++$numero > 30) {
                $resultado['__limite__'] = 'elementos';
                break;
            }

            if (is_string($clave) && $this->claveSensible($clave)) {
                $resultado[$clave] = '[REDACTADO]';
            } elseif (is_array($valor)) {
                $resultado[$clave] = $this->recortarParametros($valor, $nivel + 1);
            } elseif (is_string($valor)) {
                // Un correo o bearer puede aparecer bajo un campo no estandar.
                $texto = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[CORREO]', $valor) ?? '';
                $texto = preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTADO]', $texto) ?? '';
                $resultado[$clave] = mb_strlen($texto) > 300 ? mb_substr($texto, 0, 300) . '…' : $texto;
            } elseif (is_int($valor) || is_float($valor) || is_bool($valor) || $valor === null) {
                $resultado[$clave] = $valor;
            } else {
                // No serializar objetos como excepciones, archivos o tokens.
                $resultado[$clave] = '[OBJETO OMITIDO]';
            }
        }

        return $resultado;
    }

    private function claveSensible(string $clave): bool
    {
        $clave = mb_strtolower($clave);
        $clave = strtr($clave, ['ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        return preg_match('/password|passwd|contrasen|passphrase|secret|secreto|token|apikey|api_key|authorization|cookie|sesion|session|smtp|credencial|clave|mensaje|cuerpo|asunto|destinat|correo|email|e-mail|telefono|celular|dni|ruc|direccion|razonsocial|razon_social|cliente|apellido|nombre|foto|archivo|adjunto|contenido|payload|base64|documento_personal|url/i', $clave) === 1;
    }

    /** Solo deja pasar las claves esperadas de cada elemento de una lista. */
    protected function listaFiltrada(mixed $lista, array $claves): array
    {
        if (! is_array($lista)) {
            return [];
        }

        return array_values(array_map(
            fn ($item) => is_array($item) ? array_intersect_key($item, array_flip($claves)) : [],
            array_filter($lista, 'is_array')
        ));
    }
}
