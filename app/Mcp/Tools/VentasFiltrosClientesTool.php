<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasFiltrosClientesTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'ventas_filtros_clientes';

    protected string $title = 'Valores para filtrar clientes';

    protected string $description = 'Devuelve los valores que existen de estado, sector comercial (pesquera, agroindustrias, molinos, etc.) y departamento de los clientes (y los meses que definen a un cliente inactivo). Consúltala antes de filtrar con ventas_buscar_clientes por estado, sector comercial o departamento, y usa los valores tal cual aparecen.';

    public function handle(Request $request): Response
    {
        return $this->consultar($request, 'ventas/clientes/filtros');
    }
}
