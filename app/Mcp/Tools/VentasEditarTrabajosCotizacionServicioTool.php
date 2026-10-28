<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasEditarTrabajosCotizacionServicioTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_editar_trabajos_cotizacion_servicio';

    protected string $title = 'Editar trabajos a realizar';

    protected string $description = 'Reemplaza el texto de "trabajos a realizar" de uno o varios instrumentos de una cotización por servicio. El texto enviado sustituye al anterior (máximo 2000 caracteres): parte del texto actual que devuelve ventas_ver_cotizacion_servicio. No funciona si la cotización está Aceptada, Rechazada o Finalizada (son definitivas). ANTES de llamarla: consulta la cotización con la herramienta de ver, muestra al usuario exactamente qué vas a cambiar (valor actual y valor nuevo) y pide su confirmación explícita. Solo se pueden modificar cotizaciones propias, con rol Asesor de Ventas o Jefe de Ventas.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id de la cotización por servicio.')->required(),
            'instrumentos' => $this->listaDeObjetos($schema, ['id' => $schema->integer()->required(), 'trabajos' => $schema->string()->required()], 'Instrumentos a cambiar; cada elemento: {"id": id del instrumento, "trabajos": texto completo nuevo de los trabajos}.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/cotizaciones/servicio/' . (int) $request->get('id') . '/trabajos', [
            'instrumentos' => $this->listaFiltrada($request->get('instrumentos'), ['id', 'trabajos']),
        ], 'No se pudo modificar: ');
    }
}
