<?php

namespace Tests\Unit;

use App\Mcp\Tools\AlmacenBuscarProductosTool;
use App\Mcp\Tools\AlmacenVerProductoTool;
use App\Mcp\Tools\VentasBuscarProductosTool;
use App\Mcp\Tools\VentasRegistrarCotizacionVentaTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

trait RespuestaCatalogoSimulada
{
    public array $consulta;
    public Response $respuesta;

    protected function consultar(Request $request, string $ruta, array $query = []): Response
    {
        $this->consulta = [$ruta, $query];
        return $this->respuesta;
    }
}

class ProductoCatalogoContractTest extends TestCase
{
    public function test_detail_uses_id_as_code_and_preserves_item_and_other_fields(): void
    {
        $tool = new class extends AlmacenVerProductoTool { use RespuestaCatalogoSimulada; };
        foreach (['00123', 'BAL-50', null, ''] as $item) {
            $tool->respuesta = Response::text(json_encode(['id' => 42, 'codigo' => $item, 'precio_min' => 100, 'marca' => ['id' => 8]]));
            $result = $tool->handle(new Request(['id' => 42]));
            $this->assertSame(['almacen/catalogo/productos/42', []], $tool->consulta);
            $this->assertSame(['id' => 42, 'codigo' => 42, 'precio_min' => 100, 'marca' => ['id' => 8], 'item' => $item], json_decode((string) $result->content(), true));
        }
    }

    public function test_searches_preserve_filters_pagination_and_empty_results(): void
    {
        $tools = [
            new class extends AlmacenBuscarProductosTool { use RespuestaCatalogoSimulada; },
            new class extends VentasBuscarProductosTool { use RespuestaCatalogoSimulada; },
        ];
        foreach ($tools as $tool) {
            foreach ([[], [['id' => 42, 'codigo' => '0007']]] as $rows) {
                $payload = ['pagina' => 2, 'total' => 30, 'hay_mas' => true, 'data' => $rows];
                $tool->respuesta = Response::text(json_encode($payload));
                $result = $tool->handle(new Request(['codigo' => '0007', 'q' => '0007', 'pagina' => 2]));
                $this->assertSame(2, $tool->consulta[1]['pagina']);
                $filter = $tool instanceof AlmacenBuscarProductosTool ? 'codigo' : 'q';
                $this->assertSame('0007', $tool->consulta[1][$filter]);
                $payload['data'] = $rows ? [['id' => 42, 'codigo' => 42, 'item' => '0007']] : [];
                $this->assertSame($payload, json_decode((string) $result->content(), true));
            }
            $tool->respuesta = Response::error('No tienes permiso');
            $this->assertSame($tool->respuesta, $tool->handle(new Request([])));
        }
    }

    public function test_quote_uses_catalog_id_even_when_item_is_numeric(): void
    {
        $search = new class extends VentasBuscarProductosTool { use RespuestaCatalogoSimulada; };
        $search->respuesta = Response::text('{"data":[{"id":42,"codigo":"0007"}]}');
        $product = json_decode((string) $search->handle(new Request(['q' => '0007']))->content(), true)['data'][0];
        $quote = new class extends VentasRegistrarCotizacionVentaTool {
            public array $payload;
            protected function enviar(Request $request, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            {
                $this->payload = $cuerpo;
                return Response::text('{}');
            }
        };
        $quote->handle(new Request(['productos' => [['producto_id' => $product['codigo'], 'item' => $product['item'], 'codigo' => $product['codigo'], 'cantidad' => 1.5]] ]));
        $this->assertSame([['producto_id' => 42, 'cantidad' => 1.5]], $quote->payload['productos']);
    }
}
