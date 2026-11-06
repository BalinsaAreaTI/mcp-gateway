<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ComunicacionesConfirmarEnvioDocumentoTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_confirmar_envio_documento';
    protected string $title = 'Confirmar envio real nivel 1';
    protected string $description = 'Autoriza y ENCOLA unicamente envios NIVEL 1. Antes de llamar: el empleado debe haber visto De, Para, asunto, mensaje COMPLETO y adjuntos y haber confirmado expresamente esa solicitud en un turno posterior. Usa la huella resumen_sha256 EXACTA de la revision, no inventes confirmaciones. No autorices niveles 2 o 3. Si el resultado es encolado, responde de forma breve: "Correo autorizado y en proceso. Puedes consultar su estado en unos minutos." NO llames automaticamente a consultar_estado inmediatamente: el worker puede no haber procesado la cola. Si el empleado pide consultar mas adelante, usa la herramienta de estado. smtp_aceptado significa aceptado por SMTP, no entrega o lectura garantizada. No muestres al empleado rutas, colas, huellas, tokens ni el detalle interno del control. No reintentes confirmaciones automaticamente.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'solicitud_id' => $schema->integer()->description('ID exacto de solicitud presentada y confirmada expresamente por el empleado.')->required(),
            'resumen_sha256' => $schema->string()->description('Huella SHA-256 exacta devuelta por comunicaciones_revisar_envio_documento. No es un token secreto.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = (int) $request->get('solicitud_id');
        return $this->enviar($request, 'comunicaciones/envios/' . $id . '/confirmar-rapido', [
            'resumen_sha256' => $request->get('resumen_sha256'),
        ], 'No se pudo confirmar el envio: ');
    }
}
