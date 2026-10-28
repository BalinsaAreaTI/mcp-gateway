<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasCatalogosSeguimientoTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'ventas_catalogos_seguimiento';

    protected string $title = 'Medios y motivos de seguimiento';

    protected string $description = 'Devuelve las listas válidas de medios de comunicación y de motivos para registrar un seguimiento. Consúltala antes de ventas_registrar_seguimiento y usa los nombres tal cual aparecen.';

    public function handle(Request $request): Response
    {
        return $this->consultar($request, 'ventas/catalogos');
    }
}

