<?php

namespace Tests\Unit;

use App\Mcp\Tools\VentasRegistrarCotizacionVentaTool;
use App\Mcp\Tools\VentasGenerarPdfCotizacionVentaTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

class VentasCotizacionContractTest extends TestCase
{
    public function test_registration_preserves_variants_and_decimal_quantities_but_filters_untrusted_fields(): void
    {
        $tool = new class extends VentasRegistrarCotizacionVentaTool {
            public array $payload;
            protected function enviar(Request $request, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            {
                $this->payload = [$ruta, $cuerpo];
                return Response::text('ok');
            }
        };
        $product = ['producto_id' => 1, 'cantidad' => 1.5, 'importe' => 0, 'capacidades' => "30 kg\n60 kg", 'medidas' => '20 x 30 cm', 'garantia' => '1 año', 'dias_entrega' => '5 dias', 'forma_pago' => 'Al contado', 'requerimientos' => '', 'idCotizacion' => 99];
        $tool->handle(new Request(['cliente_id' => 5, 'productos' => [$product, array_replace($product, ['medidas' => '50 x 60 cm'])]]));
        [$route, $body] = $tool->payload;
        $this->assertSame('ventas/cotizaciones/venta', $route);
        $this->assertCount(2, $body['productos']);
        unset($product['idCotizacion']);
        $this->assertSame($product, $body['productos'][0]);
        $this->assertSame('50 x 60 cm', $body['productos'][1]['medidas']);
    }

    public function test_pdf_orientation_and_download_are_forwarded(): void
    {
        $tool = new class extends VentasGenerarPdfCotizacionVentaTool {
            public array $payload;
            protected function consultar(Request $request, string $ruta, array $query = []): Response
            {
                $this->payload = [$ruta, $query];
                return Response::text('ok');
            }
        };
        $tool->handle(new Request(['id' => 7, 'modo' => 'descargar', 'horizontal' => true]));
        $this->assertSame(['ventas/cotizaciones/venta/7/pdf', ['modo' => 'descargar', 'horizontal' => 1]], $tool->payload);
        $tool->handle(new Request(['id' => 7]));
        $this->assertNull($tool->payload[1]['horizontal']);
    }
}
