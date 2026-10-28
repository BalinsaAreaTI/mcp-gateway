<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasFiltrosCotizacionesTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_filtros_cotizaciones';

    protected string $title = 'Valores para filtrar cotizaciones';

    protected string $description = 'Devuelve los estados de cotización (y cuáles son definitivos), las categorías con sus subcategorías, los instrumentos, las monedas y los valores de forma de pago, días de entrega, validez, garantía y plazo que más se usan en cotizaciones. Consúltala antes de filtrar cotizaciones por categoría, subcategoría o instrumento, y antes de registrar una cotización para escribir esos datos igual que el resto; usa los nombres tal cual aparecen.';

    public function handle(Request $request): Response
    {
        return $this->consultar($request, 'ventas/cotizaciones/filtros');
    }
}
