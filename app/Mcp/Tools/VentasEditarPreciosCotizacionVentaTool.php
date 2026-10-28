<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasEditarPreciosCotizacionVentaTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_editar_precios_cotizacion_venta';

    protected string $title = 'Editar precios de cotización por venta';

    protected string $description = 'Cambia el importe unitario de uno o varios productos de una cotización por venta y recalcula subtotal, IGV y total. No funciona si la cotización está Aceptada, Rechazada o Finalizada (son definitivas). Si un importe queda fuera del rango precio mínimo/máximo del producto, la respuesta incluye "advertencias": menciónalas al usuario. ANTES de llamarla: consulta la cotización con la herramienta de ver, muestra al usuario exactamente qué vas a cambiar (valor actual y valor nuevo) y pide su confirmación explícita. Solo se pueden modificar cotizaciones propias, con rol Asesor de Ventas o Jefe de Ventas.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id de la cotización por venta.')->required(),
            'productos' => $this->listaDeObjetos($schema, ['id' => $schema->integer()->required(), 'importe' => $schema->number()->required()], 'Lista de productos a cambiar; cada elemento: {"id": id del producto dentro de la cotización (de ventas_ver_cotizacion_venta), "importe": nuevo importe unitario}. Solo incluye los que cambian.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/cotizaciones/venta/' . (int) $request->get('id') . '/precios', [
            'productos' => $this->listaFiltrada($request->get('productos'), ['id', 'importe']),
        ], 'No se pudo modificar: ');
    }
}
