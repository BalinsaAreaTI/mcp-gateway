<?php

namespace Tests\Unit;

use App\Mcp\Tools\AlmacenRegistrarSubcategoriaTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

class AlmacenRegistrarSubcategoriaToolTest extends TestCase
{
    public function test_forwards_category_using_the_central_system_field(): void
    {
        $tool = new class extends AlmacenRegistrarSubcategoriaTool {
            public array $sent = [];

            protected function enviar(Request $request, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            {
                $this->sent = [$ruta, $cuerpo];
                return Response::text('{}');
            }
        };

        $this->assertArrayHasKey('categoria_id', $tool->schema(new JsonSchemaTypeFactory));
        $tool->handle(new Request(['categoria_id' => 7, 'nombre' => 'Balanzas']));
        $this->assertSame(['almacen/catalogo/subcategorias', [
            'tbcategoria_id' => 7, 'nombre' => 'Balanzas',
        ]], $tool->sent);
    }
}