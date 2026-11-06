<?php

namespace Tests\Unit;

use App\Mcp\Tools\ServicioTecnicoResumenHojasSalidaTool;
use App\Mcp\Tools\ServicioTecnicoVerInstrumentosHojaSalidaTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

class HojasSalidaToolsTest extends TestCase
{
    public function test_search_detail_and_pdf_schemas_match_supported_parameters(): void
    {
        $factory = new JsonSchemaTypeFactory;
        $search = (new \App\Mcp\Tools\ServicioTecnicoBuscarHojasSalidaTool)->schema($factory);
        $detail = (new \App\Mcp\Tools\ServicioTecnicoVerHojaSalidaTool)->schema($factory);
        $pdf = (new \App\Mcp\Tools\ServicioTecnicoGenerarPdfHojaSalidaTool)->schema($factory);
        $this->assertSame(20, $search['limite']->toArray()['maximum']);
        $this->assertSame(1, $detail['id']->toArray()['minimum']);
        $this->assertSame(1, $pdf['id']->toArray()['minimum']);
        $this->assertSame(['ver', 'descargar'], $pdf['modo']->toArray()['enum']);
    }

    public function test_summary_forwards_all_search_filters(): void
    {
        $tool = new class extends ServicioTecnicoResumenHojasSalidaTool {
            public array $sent = [];

            protected function consultar(Request $request, string $ruta, array $query = []): Response
            {
                $this->sent = [$ruta, $query];
                return Response::text('{}');
            }
        };
        $params = ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'tipo_fecha' => 'programado',
            'cliente' => 'Cliente', 'estado' => 'pendiente', 'pendiente_id' => 5, 'tecnico_id' => 7];
        $tool->handle(new Request($params));
        $this->assertSame('servicio-tecnico/hojas-salida/resumen', $tool->sent[0]);
        $this->assertEquals($params, $tool->sent[1]);
        $this->assertEqualsCanonicalizing(array_keys($params), array_keys($tool->schema(new JsonSchemaTypeFactory)));
    }

    public function test_instrument_schema_and_request_match_backend_pagination(): void
    {
        $tool = new class extends ServicioTecnicoVerInstrumentosHojaSalidaTool {
            public array $sent = [];

            protected function consultar(Request $request, string $ruta, array $query = []): Response
            {
                $this->sent = [$ruta, $query];
                return Response::text('{}');
            }
        };
        $schema = $tool->schema(new JsonSchemaTypeFactory);
        $this->assertSame(1, $schema['pagina']->toArray()['minimum']);
        $this->assertSame(1, $schema['id']->toArray()['minimum']);
        $this->assertSame(20, $schema['limite']->toArray()['maximum']);
        $tool->handle(new Request(['id' => 123, 'pagina' => 2, 'limite' => 20]));
        $this->assertSame(['servicio-tecnico/hojas-salida/123/instrumentos', ['pagina' => 2, 'limite' => 20]], $tool->sent);
    }
}
