<?php

namespace Tests\Unit;

use App\Mcp\Tools\VentasRegistrarCotizacionServicioTool;
use App\Mcp\Tools\VentasRegistrarCotizacionServicioDesdeInformeTool;
use App\Mcp\Tools\VentasEditarCondicionesCotizacionServicioTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

trait CapturaCondiciones
{
    public array $payload;

    protected function enviar(Request $request, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
    {
        $this->payload = $cuerpo;
        return Response::text('ok');
    }
}

class CondicionesServicioToolsTest extends TestCase
{
    public function test_preserves_custom_empty_and_invalid_values_for_server_validation(): void
    {
        $tools = [
            new class extends VentasRegistrarCotizacionServicioTool { use CapturaCondiciones; },
            new class extends VentasRegistrarCotizacionServicioDesdeInformeTool { use CapturaCondiciones; },
            new class extends VentasEditarCondicionesCotizacionServicioTool { use CapturaCondiciones; },
        ];
        foreach ($tools as $tool) {
            foreach ([[], ['Entrega, previa coordinación.'], [123], 'inválido'] as $value) {
                $tool->handle(new Request(['id' => 1, 'condiciones' => $value]));
                $this->assertSame($value, $tool->payload['condiciones']);
            }
            $tool->handle(new Request(['id' => 1]));
            $this->assertNull($tool->payload['condiciones'], 'Omitting conditions must not silently reset an existing quote.');
        }
    }
}
