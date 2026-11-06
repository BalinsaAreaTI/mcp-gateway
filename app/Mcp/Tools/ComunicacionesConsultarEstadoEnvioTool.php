<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ComunicacionesConsultarEstadoEnvioTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_consultar_estado_envio';
    protected string $title = 'Consultar estado de un correo';
    protected string $description = 'Consulta el estado real de un correo propio SIN modificarlo. Usa esta herramienta cuando el empleado lo solicite o al actualizar manualmente el estado, NO inmediatamente despues de encolar por sistema. Traduce a mensajes breves: preparado="Pendiente de autorizacion"; encolado/procesando="Correo autorizado y en proceso. Consulta de nuevo mas adelante"; smtp_aceptado="El servidor de correo acepto el mensaje" (NO garantiza entrega en bandeja ni lectura); fallido="No se pudo completar el envio"; resultado_incierto="No se pudo confirmar el resultado; requiere revision, no reenviar automaticamente"; vencido/documento_cambiado="Se requiere preparar un nuevo correo". No reveles estructura interna ni codigos de error salvo que el empleado solicite diagnostico tecnico. No prometas seguimiento en segundo plano.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'solicitud_id' => $schema->integer()->description('ID numerico interno de la solicitud de correo.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->consultar($request, 'comunicaciones/envios/' . (int) $request->get('solicitud_id'));
    }
}
