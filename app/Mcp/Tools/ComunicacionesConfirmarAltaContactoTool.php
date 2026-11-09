<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class ComunicacionesConfirmarAltaContactoTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_confirmar_alta_contacto';
    protected string $title = 'Confirmar registro de contacto externo';
    protected string $description = 'REGISTRA un contacto CRM solo tras revisar y recibir confirmacion explicita de ese contacto y ese cliente en un turno posterior. Usa el resumen_sha256 exacto y confirmado=true. Nunca genera un correo ni modifica una solicitud existente. Responde brevemente "Contacto registrado correctamente. ¿Deseas preparar ahora la cotizacion para este destinatario?". Si el empleado acepta, vuelve a verificar el documento/destinatario con comunicaciones_verificar_destinatario_cotizacion y prepara NUEVO envio con su aprobacion separada. No asumas autorizacion para enviar porque autorizo registrar. No revelar huellas, ni identificadores de control al usuario.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'alta_id' => $schema->integer()->description('ID de alta previamente revisada.')->required(),
            'resumen_sha256' => $schema->string()->description('Huella SHA-256 exacta devuelta en la revision; no es una credencial.')->required(),
            'confirmado' => $schema->boolean()->description('Solo true despues de confirmacion explicita del empleado en un turno posterior.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'comunicaciones/contactos/altas/' .
            (int) $request->get('alta_id') . '/confirmar', [
            'resumen_sha256' => $request->get('resumen_sha256'),
            'confirmado' => $request->get('confirmado') === true,
        ], 'No se pudo registrar el contacto: ');
    }
}
