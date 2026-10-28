<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasResumenSeguimientosTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'ventas_resumen_seguimientos';

    protected string $title = 'Resumen de seguimientos';

    protected string $description = 'Devuelve conteos de seguimientos comerciales en un rango de fechas: total, por vendedor (con clientes distintos y días con actividad), por medio de comunicación, por motivo y por día. No incluye texto libre. Úsala para preguntas como "¿cuántas llamadas hizo cada asesor esta semana?" o "¿qué motivos predominan?". Jefe de Ventas, Gerencia, Administracion y Area de TI ven a todos los vendedores y pueden filtrar por vendedor; un Asesor de Ventas ve solo lo suyo. Si no se indica rango, usa los últimos 7 días. Para ver el detalle de los registros usa ventas_listar_seguimientos.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'desde'    => $schema->string()->description('Fecha inicial AAAA-MM-DD. Opcional (por defecto, 7 días atrás).'),
            'hasta'    => $schema->string()->description('Fecha final AAAA-MM-DD. Opcional (por defecto, hoy). Máximo 366 días de rango.'),
            'vendedor' => $schema->string()->description('Nombre (o parte) de un vendedor para acotar el resumen. Solo tiene efecto para quienes ven a todos.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->consultar($request, 'ventas/resumen', [
            'desde'    => $request->get('desde'),
            'hasta'    => $request->get('hasta'),
            'vendedor' => $request->get('vendedor'),
        ]);
    }
}
