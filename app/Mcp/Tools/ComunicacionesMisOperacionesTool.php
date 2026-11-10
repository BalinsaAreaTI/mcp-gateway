<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class ComunicacionesMisOperacionesTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_mis_operaciones';
    protected string $title = 'Consultar mis correos e impresiones';
    protected string $description = 'Consulta de SOLO LECTURA las operaciones del empleado autenticado: correos y trabajos de impresion. Utilizar cuando pregunte por ultimos envios, impresiones o su estado. No consultar inmediatamente despues de una confirmacion para declarar fracaso: encolado significa pendiente de worker. Comunicar resultados breves, agrupados por correo e impresion; no exponer detalles internos. smtp_aceptado significa que el servidor acepto el mensaje, NO entrega ni papel impreso. Resultado incierto requiere revision y nunca reintento automatico. No utilizar esta consulta para imprimir, enviar ni registrar contactos.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tipo' => $schema->string()->description('todos, correo o impresion. Por defecto todos.'),
            'limite' => $schema->integer()->description('Numero maximo de operaciones, entre 1 y 20; por defecto 10.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $tipo = (string) ($request->get('tipo') ?? 'todos');
        $limite = (int) ($request->get('limite') ?? 10);
        return $this->consultar($request, 'comunicaciones/operaciones?tipo=' . rawurlencode($tipo)
            . '&limite=' . $limite);
    }
}
