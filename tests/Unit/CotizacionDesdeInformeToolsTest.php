<?php

namespace Tests\Unit;

use App\Mcp\Tools\VentasRegistrarCotizacionServicioDesdeInformeTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

class CotizacionDesdeInformeToolsTest extends TestCase
{
    public function test_forwards_report_and_commercial_fields_but_not_identity_or_technical_overrides(): void
    {
        $tool = new class extends VentasRegistrarCotizacionServicioDesdeInformeTool {
            public array $payload;

            protected function enviar(Request $request, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            {
                $this->payload = [$ruta, $cuerpo];
                return Response::text('ok');
            }
        };
        $tool->handle(new Request([
            'reporte_id' => 9, 'cliente_id' => 999, 'id_asesor' => 99, 'total' => 1,
            'instrumentos' => [['id' => 21, 'precio' => 0, 'trabajos' => 'Revisión confirmada', 'marca' => 'Falsa']],
        ]));
        [$route, $body] = $tool->payload;
        $this->assertSame('ventas/cotizaciones/servicio/desde-informe', $route);
        $this->assertSame(9, $body['reporte_id']);
        $this->assertArrayNotHasKey('cliente_id', $body);
        $this->assertArrayNotHasKey('id_asesor', $body);
        $this->assertArrayNotHasKey('total', $body);
        $this->assertSame([['id' => 21, 'precio' => 0, 'trabajos' => 'Revisión confirmada']], $body['instrumentos']);
    }
}
