<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasContactosClienteTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'ventas_contactos_cliente';

    protected string $title = 'Contactos de un cliente';

    protected string $description = 'Devuelve los contactos registrados de un cliente (nombre, cargo, teléfono, correo). Necesita el id del cliente, que se obtiene con ventas_buscar_clientes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'cliente_id' => $schema->integer()
                ->description('Id numérico del cliente, obtenido con ventas_buscar_clientes.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = (int) $request->get('cliente_id');

        if ($id < 1) {
            return Response::error('Indica un cliente_id válido (usa ventas_buscar_clientes para obtenerlo).');
        }

        return $this->consultar($request, "ventas/clientes/{$id}/contactos");
    }
}




