<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasEditarCaracteristicasCotizacionServicioTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_editar_caracteristicas_cotizacion_servicio';

    protected string $title = 'Editar características de instrumentos';

    protected string $description = 'Edita marca, material, modelo y capacidad de uno o varios instrumentos de una cotización por servicio. El modelo es obligatorio; en balanzas y termómetros son obligatorias marca y modelo; en pesas, material, modelo y capacidad válidos (con número). No se aceptan valores como "N/A" o "sin modelo". No funciona si la cotización está Aceptada, Rechazada o Finalizada (son definitivas). No inventes datos: si el usuario no los dio, pregúntalos. ANTES de llamarla: consulta la cotización con la herramienta de ver, muestra al usuario exactamente qué vas a cambiar (valor actual y valor nuevo) y pide su confirmación explícita. Solo se pueden modificar cotizaciones propias, con rol Asesor de Ventas o Jefe de Ventas.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id de la cotización por servicio.')->required(),
            'instrumentos' => $this->listaDeObjetos($schema, ['id' => $schema->integer()->required(), 'modelo' => $schema->string()->required(), 'marca' => $schema->string(), 'material' => $schema->string(), 'capacidad_rango' => $schema->string()], 'Instrumentos a cambiar; cada elemento: {"id": id del instrumento, "modelo": obligatorio, "marca": para balanzas y termómetros, "material": para pesas, "capacidad_rango": capacidad con unidad, p. ej. "30 kg"}.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/cotizaciones/servicio/' . (int) $request->get('id') . '/caracteristicas', [
            'instrumentos' => $this->listaFiltrada($request->get('instrumentos'), ['id', 'modelo', 'marca', 'material', 'capacidad_rango']),
        ], 'No se pudo modificar: ');
    }
}
