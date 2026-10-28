<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasEliminarPendienteTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_eliminar_pendiente';

    protected string $title = 'Eliminar pendiente';

    protected string $description = 'ELIMINA un pendiente con todos sus instrumentos y servicios, y borra los archivos de la orden. NO se puede deshacer. Solo se puede eliminar un pendiente PROPIO, en estado Pendiente, aún no asignado a un técnico y que no sea derivado (sin pendiente padre ni sub pendientes); si no, el sistema responde con el motivo. ANTES de llamarla: consulta el pendiente con ventas_ver_pendiente, muestra al usuario cuál es (N°, cliente, instrumentos, fecha) y avisa que es irreversible; llama a esta herramienta solo si el usuario confirma de forma explícita que quiere eliminar ESE pendiente. Si solo quiere corregir algo, usa las herramientas de edición. Solo pueden eliminar los roles Asesor de Ventas y Jefe de Ventas.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('N° del pendiente a eliminar.')->required(),
            'confirmar' => $schema->boolean()->description('true solo si el usuario confirmó explícitamente la eliminación de este pendiente.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/pendientes/' . (int) $request->get('id') . '/eliminar', [
            'confirmar' => $request->get('confirmar') === true ? true : null,
        ], 'No se pudo eliminar: ');
    }
}
