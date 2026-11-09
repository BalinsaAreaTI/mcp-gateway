<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class ComunicacionesImpresorasDisponiblesTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_impresoras_disponibles';
    protected string $title = 'Impresoras disponibles de Balinsa';
    protected string $description = 'Lista solo los nombres y codigos de impresoras habilitadas para el empleado. No revela sus correos internos ni prepara trabajos. Usar si el empleado pide ver impresoras o el codigo es ambiguo; si solo existe la principal, usarla directamente al imprimir.';

    public function schema(JsonSchema $schema): array { return []; }

    public function handle(Request $request): Response
    {
        return $this->consultar($request, 'comunicaciones/impresoras');
    }
}
