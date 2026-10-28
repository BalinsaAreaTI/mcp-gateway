<?php

namespace App\Services;

use App\Models\User;
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
        $http = Http::withToken((string)$token)->acceptJson()->timeout(15);

        $res = $metodo === 'GET'
            ? $http->get($url, $datos)
            : $http->asJson()->post($url, $datos);   // sin reintentos automáticos

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

        return $res->json() ?? [];
    }

    private function revocar(): void
    {
        $this->user->tokens()->get()->each->revoke();
        $this->user->forceFill(['remote_token' => null])->save();
    }
}


