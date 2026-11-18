<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasEditarCondicionesCotizacionServicioTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_editar_condiciones_cotizacion_servicio';

    protected string $title = 'Editar condiciones del servicio';

    protected string $description = 'Para validez, forma de pago, plazo de entrega y garantía usa ventas_editar_condiciones_comerciales_cotizacion_servicio. Esta herramienta REEMPLAZA la lista completa de condiciones del servicio de una cotización por servicio (máximo 30, hasta 1000 caracteres cada una). Envía [] para restablecer el uso de las condiciones generales consultadas en ventas_filtros_cotizaciones. Los PDF de vista previa, descarga y correo usarán las condiciones efectivas actualizadas. Si había un correo preparado, prepara uno nuevo y solicita nueva aprobación. Primero obtén la lista actual con ventas_ver_cotizacion_servicio y envía la lista final completa, con las condiciones que se mantienen más los cambios; lo que no envíes se elimina. No funciona si la cotización está Aceptada, Rechazada o Finalizada (son definitivas). ANTES de llamarla: consulta la cotización con la herramienta de ver, muestra al usuario exactamente qué vas a cambiar (valor actual y valor nuevo) y pide su confirmación explícita. Solo se pueden modificar cotizaciones propias, con rol Asesor de Ventas o Jefe de Ventas.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id de la cotización por servicio.')->required(),
            'condiciones' => $schema->array()->items($schema->string())->description('Lista completa y final de condiciones del servicio; [] restablece el uso de las generales. Requiere confirmación.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/cotizaciones/servicio/' . (int) $request->get('id') . '/condiciones', [
            'condiciones' => $request->get('condiciones'),
        ], 'No se pudo modificar: ');
    }
}
