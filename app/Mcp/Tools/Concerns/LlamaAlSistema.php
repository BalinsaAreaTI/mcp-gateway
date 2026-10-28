<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\User;
use App\Services\SistemaClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use RuntimeException;

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
            $this->registrarAccion($request, $metodo, $ruta, $datos, false, $status, mb_substr($e->getMessage(), 0, 255), $inicio);

            return Response::error(match ($e->getCode()) {
                401     => 'Tu conexión con el sistema venció. Vuelve a conectar el conector desde tu cliente de IA.',
                403     => 'No tienes permiso para esta acción: ' . $e->getMessage(),
                404     => 'No se encontró lo que pediste: ' . $e->getMessage(),
                422     => $prefijo422 . $e->getMessage(),
                429     => 'Demasiadas consultas seguidas. Espera un momento e inténtalo de nuevo.',
                default => 'El sistema no respondió correctamente. Inténtalo más tarde.',
            });
        }

        $detalle = isset($respuesta['id']) ? 'id=' . $respuesta['id'] : null;
        $this->registrarAccion($request, $metodo, $ruta, $datos, true, 200, $detalle, $inicio);

        return Response::text(json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Auditoría: nunca debe romper la herramienta si falla. */
    private function registrarAccion(Request $request, string $metodo, string $ruta, array $datos, bool $ok, int $status, ?string $detalle, float $inicio): void
    {
        try {
            $usuario = $request->user();

            DB::table('agent_actions')->insert([
                'user_id'        => $usuario?->id,
                'remote_user_id' => $usuario?->remote_user_id,
                'tool'           => $this->name ?: class_basename($this),
                'metodo'         => $metodo,
                'ruta'           => mb_substr($ruta, 0, 255),
                'params'         => json_encode($this->recortarParametros($datos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'estado'         => $ok ? 'ok' : 'error',
                'http_status'    => $status,
                'detalle'        => $detalle,
                'duracion_ms'    => (int) round((microtime(true) - $inicio) * 1000),
                'user_agent'     => mb_substr((string) request()->userAgent(), 0, 255),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('agent_actions: no se pudo registrar la auditoría: ' . $e->getMessage());
        }
    }

    /** Recorta textos largos; recorre listas (productos, instrumentos, condiciones). */
    private function recortarParametros(array $datos): array
    {
        return array_map(function ($v) {
            if (is_array($v)) {
                return $this->recortarParametros($v);
            }

            return is_string($v) && mb_strlen($v) > 300 ? mb_substr($v, 0, 300) . '…' : $v;
        }, $datos);
    }

    /** Solo deja pasar las claves esperadas de cada elemento de una lista enviada por el agente. */
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
