<?php

namespace Tests\Unit\Comunicaciones;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use PHPUnit\Framework\TestCase;

/** Ejecutar dentro del gateway Laravel 12: php artisan test --filter=LlamaAlSistemaAuditoriaTest */
class LlamaAlSistemaAuditoriaTest extends TestCase
{
    private function auditador(): object
    {
        return new class {
            use LlamaAlSistema;

            public function parametros(string $ruta, array $datos): array
            {
                return $this->parametrosAuditados($ruta, $datos);
            }

            public function ruta(string $ruta): string
            {
                return $this->rutaAuditada($ruta);
            }
        };
    }

    public function test_comunicaciones_no_persiste_datos_del_correo_ni_referencias_de_adjuntos(): void
    {
        $datos = [
            'destinatario' => 'compras@example.org',
            'asunto' => 'Prueba de ventas',
            'mensaje' => 'Contenido privado de la cotización',
            'clave_idempotencia' => 'MI_CLAVE_SECRETA',
            'adjuntos' => [['tipo' => 'cotizacion_venta', 'referencia' => '670']],
        ];

        $resultado = $this->auditador()->parametros('comunicaciones/envios/preparar', $datos);
        $this->assertSame(['registro' => 'solo_metadatos', 'cantidad_adjuntos' => 1], $resultado);
        $this->assertStringNotContainsString('compras@', json_encode($resultado));
        $this->assertStringNotContainsString('670', json_encode($resultado));
        $this->assertStringNotContainsString('MI_CLAVE_SECRETA', json_encode($resultado));
    }

    public function test_la_consulta_de_historial_solo_persiste_metadatos(): void
    {
        $this->assertSame(
            ['registro' => 'solo_metadatos'],
            $this->auditador()->parametros('/api/mcp/comunicaciones/envios', ['estado' => 'preparado', 'desde' => '2026-11-01'])
        );
    }

    public function test_la_ruta_de_comunicaciones_no_guarda_parametros_ni_ids(): void
    {
        $this->assertSame(
            'comunicaciones/[ruta reservada]',
            $this->auditador()->ruta('comunicaciones/envios/12345?signature=NO_LOGUEAR')
        );
    }

    public function test_otras_herramientas_mantienen_ids_y_ocultan_secretos_anidados(): void
    {
        $params = $this->auditador()->parametros('ventas/productos', [
            'id' => 15, 'estado' => 'activo',
            'detalles' => [['cantidad' => 2, 'token' => 'XYZ', 'cliente' => 'Cliente X']],
        ]);
        $this->assertSame(15, $params['id']);
        $this->assertSame('activo', $params['estado']);
        $this->assertSame(2, $params['detalles'][0]['cantidad']);
        $this->assertSame('[REDACTADO]', $params['detalles'][0]['token']);
        $this->assertSame('[REDACTADO]', $params['detalles'][0]['cliente']);
    }

    public function test_ruta_ajena_a_comunicaciones_elimina_query_y_fragmento(): void
    {
        $this->assertSame('ventas/productos', $this->auditador()->ruta('ventas/productos?token=XYZ#fragmento'));
    }
}
