<?php

namespace Tests\Unit;

use App\Mcp\Tools\VentasEditarCondicionesComercialesCotizacionServicioTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

class CondicionesComercialesServicioToolTest extends TestCase
{
    public function test_only_the_four_commercial_fields_are_forwarded(): void
    {
        $tool = new class extends VentasEditarCondicionesComercialesCotizacionServicioTool {
            public array $payload;
            protected function enviar(Request $request, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            {
                $this->payload = [$ruta, $cuerpo];
                return Response::text('ok');
            }
        };
        $data = ['validez' => '7 Día(s)', 'forma_pago' => '50% anticipo', 'plazo_entrega' => '5 días hábiles', 'garantia' => '6 Mes(es)'];
        $tool->handle(new Request($data + ['id' => 15, 'total' => 0, 'moneda' => 'USD', 'condiciones' => [], 'id_asesor' => 99]));
        $this->assertSame(['ventas/cotizaciones/servicio/15/condiciones-comerciales', $data], $tool->payload);
        $tool->handle(new Request(['id' => 15]));
        $this->assertSame(array_fill_keys(array_keys($data), null), $tool->payload[1]);
    }
}
