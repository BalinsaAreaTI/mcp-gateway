<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class ComunicacionesConsultarEstadoImpresionTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_consultar_estado_impresion';
    protected string $title = 'Consultar una impresion de Balinsa';
    protected string $description = 'Consulta solamente una impresion solicitada por el empleado. encolado: "Pendiente de procesamiento"; procesando: "Procesando impresion"; smtp_aceptado: "El servidor de correo acepto el PDF; la impresion fisica aun no esta confirmada"; fallido: no se completo; resultado_incierto: NO volver a imprimir automaticamente. Consultar solo cuando el usuario lo pida o se requiera una comprobacion, NO inmediatamente despues de encolar. No mostrar direccion de impresora ni tecnicismos.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'impresion_id' => $schema->integer()->description('ID del trabajo devuelto por la herramienta de imprimir.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->consultar($request, 'comunicaciones/impresiones/' . (int) $request->get('impresion_id'));
    }
}
