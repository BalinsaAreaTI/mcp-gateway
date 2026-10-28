<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasRegistrarCotizacionVentaTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_registrar_cotizacion_venta';

    protected string $title = 'Registrar cotización por venta';

    protected string $description = 'Crea UNA cotización por venta (estado Pendiente) a nombre del usuario conectado, con productos del catálogo; el sistema calcula subtotal, IGV y total. ANTES de llamarla: (1) obtén el id del cliente con ventas_buscar_clientes: el sistema rechaza clientes asignados a otro asesor (salvo clientes vencidos, con última factura de más de 2 meses) y clientes que ya tengan una cotización por venta Pendiente de los últimos 14 días; (2) busca cada producto con ventas_buscar_productos y usa su id; (3) consulta ventas_filtros_cotizaciones para escribir moneda, forma de pago, días de entrega y validez como ya se usan; (4) pregunta al usuario lo que falte (cantidades, importes distintos al precio mínimo, forma de pago, días de entrega, validez) sin inventarlo; (5) muestra un resumen completo (cliente, productos, cantidades, importes, moneda, condiciones) y pide confirmación explícita. Si hay varias capacidades o medidas disponibles, pide al usuario cuáles incluir; admite valores manuales. Las condiciones de cada producto son independientes de las condiciones generales. Si un producto está en otra moneda que la cotización, pide el tipo de cambio. Si un importe queda fuera del rango mínimo/máximo del producto, la respuesta trae "advertencias": menciónalas al usuario. Solo pueden registrar los roles Asesor de Ventas y Jefe de Ventas.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'cliente_id'   => $schema->integer()->description('Id del cliente (de ventas_buscar_clientes).')->required(),
            'moneda'       => $schema->string()->description('Moneda de la cotización: Soles o Dólares.')->required(),
            'forma_pago'   => $schema->string()->description('Forma de pago, p. ej. la que ya se usa (ver ventas_filtros_cotizaciones).')->required(),
            'dias_entrega' => $schema->string()->description('Tiempo de entrega, p. ej. "5 días hábiles".')->required(),
            'validez'      => $schema->string()->description('Validez de la oferta, p. ej. "15 días".')->required(),
            'productos'    => $this->listaDeObjetos($schema, [
                'producto_id'    => $schema->integer()->required(),
                'cantidad'       => $schema->number()->description('Cantidad mayor que cero; admite decimales.')->required(),
                'importe'        => $schema->number(),
                'capacidades'    => $schema->string()->description('Capacidades elegidas o manuales, separadas por saltos de línea. Si hay varias opciones, pide la selección al usuario.'),
                'medidas'        => $schema->string()->description('Medidas elegidas o manuales, separadas por saltos de línea; consulta medida en ventas_buscar_productos.'),
                'garantia'       => $schema->string()->description('Garantía específica del producto.'),
                'dias_entrega'   => $schema->string()->description('Entrega específica del producto.'),
                'forma_pago'     => $schema->string()->description('Forma de pago específica del producto.'),
                'requerimientos' => $schema->string(),
            ], 'Productos a cotizar; cada elemento: {"producto_id": id del catálogo, "cantidad": número positivo, "importe": precio unitario sin IGV en la moneda del producto (opcional: se usa precio_min), "capacidades": texto, "medidas": texto, "garantia": texto, "dias_entrega": texto, "forma_pago": texto, "requerimientos": nota}. Los textos son opcionales. Se permite repetir un producto con distintas capacidades, medidas o condiciones.'),
            'tipo_cambio'  => $schema->number()->description('Tipo de cambio. Obligatorio solo si algún producto está en otra moneda que la cotización.'),
            'fecha'        => $schema->string()->description('Fecha de la cotización AAAA-MM-DD: hoy o hasta 7 días atrás (por defecto, hoy).'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/cotizaciones/venta', [
            'cliente_id'   => $request->get('cliente_id'),
            'moneda'       => $request->get('moneda'),
            'forma_pago'   => $request->get('forma_pago'),
            'dias_entrega' => $request->get('dias_entrega'),
            'validez'      => $request->get('validez'),
            'tipo_cambio'  => $request->get('tipo_cambio'),
            'fecha'        => $request->get('fecha'),
            'productos'    => $this->listaFiltrada($request->get('productos'), ['producto_id', 'cantidad', 'importe', 'capacidades', 'medidas', 'garantia', 'dias_entrega', 'forma_pago', 'requerimientos']),
        ], 'No se pudo registrar la cotización: ');
    }
}
