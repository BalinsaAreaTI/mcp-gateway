<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SistemaClient
{
    public function __construct(private User $user) {}

    public function get(string $path, array $query = []): array
    {
        return $this->enviar('GET', $path, $query);
    }

    public function post(string $path, array $cuerpo = []): array
    {
        return $this->enviar('POST', $path, $cuerpo);
    }

    private function enviar(string $metodo, string $path, array $datos): array
    {
        $token = $this->user->remote_token;
        if (! $token) {
            $this->revocar();
            throw new RuntimeException('sin-token', 401);
        }

        $url = config('sistema.api_url') . '/mcp/' . ltrim($path, '/');
        $http = Http::withToken((string) $token)->acceptJson()->connectTimeout(5)->timeout(15);

        try {
            $res = $metodo === 'GET'
                ? $http->get($url, $datos)
                : $http->asJson()->post($url, $datos);   // sin reintentos automaticos
        } catch (ConnectionException $e) {
            // No propagar errores de transporte que puedan incluir URL o cabeceras.
            // En particular, NO reintentar un POST: el sistema podria haberlo procesado.
            throw new RuntimeException('conexion-sistema', 503);
        }

        $status = $res->status();

        if ($status === 401) {
            $this->revocar();
            throw new RuntimeException('sesion-vencida', 401);
        }
        if (in_array($status, [403, 404, 429], true)) {
            throw new RuntimeException((string) ($res->json('message') ?: 'error'), $status);
        }
        if ($status === 422) {
            $primero = collect($res->json('errors', []))->flatten()->first();
            throw new RuntimeException((string) ($primero ?: $res->json('message') ?: 'parametros-invalidos'), 422);
        }
        if (! $res->successful()) {
            throw new RuntimeException('error-sistema', $status);
        }

        $contenido = $res->json();
        if (! is_array($contenido)) {
            // Un HTTP 2xx con JSON vacio o escalar no satisface el contrato MCP.
            throw new RuntimeException('respuesta-invalida', 502);
        }

        return $contenido;
    }

    private function revocar(): void
    {
        $this->user->tokens()->get()->each->revoke();
        $this->user->forceFill(['remote_token' => null])->save();
    }
}


