<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasEditarPreciosCotizacionServicioTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_editar_precios_cotizacion_servicio';

    protected string $title = 'Editar precios de cotización por servicio';

    protected string $description = 'Cambia el precio de uno o varios instrumentos de una cotización por servicio y recalcula subtotal, IGV y total. No funciona si la cotización está Aceptada, Rechazada o Finalizada (son definitivas). ANTES de llamarla: consulta la cotización con la herramienta de ver, muestra al usuario exactamente qué vas a cambiar (valor actual y valor nuevo) y pide su confirmación explícita. Solo se pueden modificar cotizaciones propias, con rol Asesor de Ventas o Jefe de Ventas.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id de la cotización por servicio.')->required(),
            'instrumentos' => $this->listaDeObjetos($schema, ['id' => $schema->integer()->required(), 'precio' => $schema->number()->required()], 'Instrumentos a cambiar; cada elemento: {"id": id del instrumento (de ventas_ver_cotizacion_servicio), "precio": nuevo precio}. Solo incluye los que cambian.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/cotizaciones/servicio/' . (int) $request->get('id') . '/precios', [
            'instrumentos' => $this->listaFiltrada($request->get('instrumentos'), ['id', 'precio']),
        ], 'No se pudo modificar: ');
    }
}
