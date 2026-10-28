<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasCambiarEstadoCotizacionVentaTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_cambiar_estado_cotizacion_venta';

    protected string $title = 'Cambiar estado de cotización por venta';

    protected string $description = 'Cambia el estado de UNA cotización por venta a: Por Enviar, Pendiente, Aceptado, Rechazado, Finalizado o Sin Respuesta. Solo se puede cambiar mientras la cotización esté Por Enviar, Pendiente o Sin Respuesta (desde esos estados se puede pasar a cualquier otro); Aceptada, Rechazada y Finalizada son definitivas y ya no se pueden cambiar. ANTES de llamarla: consulta la cotización con la herramienta de ver, muestra al usuario exactamente qué vas a cambiar (valor actual y valor nuevo) y pide su confirmación explícita. Solo se pueden modificar cotizaciones propias, con rol Asesor de Ventas o Jefe de Ventas.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id de la cotización por venta.')->required(),
            'estado' => $schema->string()->description('Nuevo estado: Por Enviar, Pendiente, Aceptado, Rechazado, Finalizado o Sin Respuesta.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/cotizaciones/venta/' . (int) $request->get('id') . '/estado', [
            'estado' => $request->get('estado'),
        ], 'No se pudo modificar: ');
    }
}
