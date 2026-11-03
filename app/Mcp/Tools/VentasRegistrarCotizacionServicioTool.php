<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasRegistrarCotizacionServicioTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_registrar_cotizacion_servicio';

    protected string $title = 'Registrar cotización por servicio';

    protected string $description = 'Crea UNA cotización por servicio DIRECTA (sin informe técnico; estado Pendiente) a nombre del usuario conectado, con uno o más instrumentos; el sistema calcula subtotal, IGV (18%) y total. Las cotizaciones que nacen de un informe técnico no se pueden crear desde aquí. ANTES de llamarla: (1) obtén el id del cliente con ventas_buscar_clientes: el sistema rechaza clientes asignados a otro asesor (salvo clientes vencidos, con última factura de más de 2 meses) y clientes que ya tengan una cotización por servicio Pendiente de los últimos 14 días; (2) elige con el usuario la plantilla en ventas_plantillas_servicio; (3) consulta ventas_filtros_cotizaciones para escribir moneda, forma de pago, garantía, plazo y validez como ya se usan; (4) pregunta lo que falte (tipo de instrumento, trabajos, precio de cada uno, forma de pago) sin inventarlo; los datos técnicos (marca, modelo, capacidad) pueden completarse después, pero son obligatorios para pasar a Aceptado; (5) muestra un resumen completo y pide confirmación explícita. Solo pueden registrar los roles Asesor de Ventas y Jefe de Ventas.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'cliente_id'    => $schema->integer()->description('Id del cliente (de ventas_buscar_clientes).')->required(),
            'plantilla_id'  => $schema->integer()->description('Id de la plantilla (de ventas_plantillas_servicio).')->required(),
            'moneda'        => $schema->string()->description('Moneda: Soles o Dólares.')->required(),
            'forma_pago'    => $schema->string()->description('Forma de pago.')->required(),
            'instrumentos'  => $this->listaDeObjetos($schema, [
                'tipo'                   => $schema->string()->required(),
                'precio'                 => $schema->number()->required(),
                'titulo_servicio'        => $schema->string(),
                'trabajos'               => $schema->string(),
                'marca'                  => $schema->string(),
                'modelo'                 => $schema->string(),
                'numero_serie'           => $schema->string(),
                'capacidad_rango'        => $schema->string(),
                'resolucion_division'    => $schema->string(),
                'clase_tipo_metrologico' => $schema->string(),
                'material'               => $schema->string(),
                'plataforma'             => $schema->string(),
                'clase_exactitud'        => $schema->string(),
                'bastago'                => $schema->string(),
            ], 'Instrumentos a cotizar; cada elemento: {"tipo": "Balanza", "Termómetro" o "Pesa", "precio": precio del servicio para ese instrumento, "titulo_servicio": título del servicio (opcional, por defecto el de la plantilla), "trabajos": trabajos a realizar (opcional), y opcionalmente marca, modelo, numero_serie, capacidad_rango, resolucion_division, clase_tipo_metrologico, material, plataforma, clase_exactitud, bastago}.'),
            'descripcion'   => $schema->string()->description('Título de la cotización (por defecto, el nombre de la plantilla).'),
            'garantia'      => $schema->string()->description('Garantía del servicio. Opcional.'),
            'plazo_entrega' => $schema->string()->description('Plazo de entrega (por defecto "3 días hábiles").'),
            'validez'       => $schema->string()->description('Validez de la oferta, p. ej. "15 días". Opcional.'),
            'condiciones'   => $schema->array()->items($schema->string())->description('Condiciones del servicio. Opcional: si no se envían se usan las condiciones generales de la empresa.'),
            'fecha'         => $schema->string()->description('Fecha AAAA-MM-DD: hoy o hasta 7 días atrás (por defecto, hoy).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $condiciones = $request->get('condiciones');

        return $this->enviar($request, 'ventas/cotizaciones/servicio', [
            'cliente_id'    => $request->get('cliente_id'),
            'plantilla_id'  => $request->get('plantilla_id'),
            'moneda'        => $request->get('moneda'),
            'forma_pago'    => $request->get('forma_pago'),
            'descripcion'   => $request->get('descripcion'),
            'garantia'      => $request->get('garantia'),
            'plazo_entrega' => $request->get('plazo_entrega'),
            'validez'       => $request->get('validez'),
            'fecha'         => $request->get('fecha'),
            'condiciones'   => is_array($condiciones) ? array_values(array_filter($condiciones, 'is_string')) : null,
            'instrumentos'  => $this->listaFiltrada($request->get('instrumentos'), [
                'tipo', 'precio', 'titulo_servicio', 'trabajos', 'marca', 'modelo', 'numero_serie', 'capacidad_rango',
                'resolucion_division', 'clase_tipo_metrologico', 'material', 'plataforma', 'clase_exactitud', 'bastago',
            ]),
        ], 'No se pudo registrar la cotización: ');
    }
}
