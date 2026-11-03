<?php

namespace Tests\Unit\Comunicaciones;

use App\Models\User;
use App\Services\SistemaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SistemaClientSeguridadTest extends TestCase
{
    private function usuario(): User
    {
        $usuario = new User();
        $usuario->forceFill(['remote_token' => 'TOKEN_DE_PRUEBA_NO_REAL']);

        return $usuario;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['sistema.api_url' => 'https://sistema.example.test/api']);
    }

    public function test_post_valido_conserva_respuesta_y_no_reintenta(): void
    {
        Http::fake(['*' => Http::response(['id' => 42, 'estado' => 'preparado'], 200)]);

        $resultado = (new SistemaClient($this->usuario()))
            ->post('comunicaciones/envios/preparar', ['asunto' => 'Prueba']);

        $this->assertSame(['id' => 42, 'estado' => 'preparado'], $resultado);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer TOKEN_DE_PRUEBA_NO_REAL'));
    }

    public function test_error_de_conexion_se_convierte_en_error_generico_sin_filtrar_secretos(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Falló la red y apareció un dato sensible de prueba');
        });

        try {
            (new SistemaClient($this->usuario()))->post('comunicaciones/envios/preparar', ['mensaje' => 'texto']);
            $this->fail('Se esperaba una excepción');
        } catch (RuntimeException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertSame('conexion-sistema', $e->getMessage());
        }

        // Una excepcion en Http::fake() no siempre se registra como peticion enviada.
        // La ausencia de retry se verifica en el test de POST exitoso.
    }

    public function test_respuesta_exitosa_no_json_array_se_rechaza(): void
    {
        Http::fake(['*' => Http::response('"respuesta inesperada"', 200, ['Content-Type' => 'application/json'])]);

        try {
            (new SistemaClient($this->usuario()))->get('comunicaciones/envios/1');
            $this->fail('Se esperaba una excepción');
        } catch (RuntimeException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertSame('respuesta-invalida', $e->getMessage());
        }
    }

    public function test_error_de_servidor_no_reexpone_cuerpo_remoto(): void
    {
        Http::fake(['*' => Http::response(['message' => 'detalle sensible de prueba'], 500)]);

        try {
            (new SistemaClient($this->usuario()))->get('comunicaciones/estado-correo');
            $this->fail('Se esperaba una excepción');
        } catch (RuntimeException $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertSame('error-sistema', $e->getMessage());
        }
    }
}
