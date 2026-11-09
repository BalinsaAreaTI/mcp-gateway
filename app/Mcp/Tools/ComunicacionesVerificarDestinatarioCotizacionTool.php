<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class ComunicacionesVerificarDestinatarioCotizacionTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_verificar_destinatario_cotizacion';
    protected string $title = 'Verificar destinatario de una cotizacion';
    protected string $description = 'VERIFICACION SOLO LECTURA antes de preparar una cotizacion por venta o servicio dirigida a un correo EXTERNO. Envia el ID INTERNO de la cotizacion, tipo y direccion exacta; Laravel valida el acceso del empleado, el cliente real del documento, CRM y reglas actuales. Si puede_preparar=true, continua con comunicaciones_preparar_envio_documento y su aprobacion correspondiente. Si ofrecer_alta_contacto=true y puede_preparar=false, responde brevemente: "Este correo todavia no figura como contacto del cliente. ¿Deseas que prepare su registro?"; si acepta, usa cliente_id de esta respuesta y comunicaciones_preparar_alta_contacto (pide nombre si falta), revisa y solicita confirmacion explicita en turno posterior, registra y vuelve a verificar antes de preparar un correo NUEVO. Si ya existe en otro cliente, nunca ofrecer duplicarlo ni intentar el envio. No uses un 403 generico como prueba de que el contacto falta. Nunca registrar ni enviar automaticamente. No se usa para hojas de salida, ni para cambiar la clasificacion de solicitudes existentes. No muestres los codigos internos al usuario; usa mensaje natural.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tipo' => $schema->string()->description('cotizacion_venta o cotizacion_servicio, segun documento.')->required(),
            'referencia' => $schema->string()->description('ID INTERNO de la cotizacion como texto; nunca numero de presentacion.')->required(),
            'destinatario' => $schema->string()->description('Correo externo exacto que desea usar el empleado.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'comunicaciones/destinatarios/cotizaciones/verificar', [
            'tipo' => $request->get('tipo'),
            'referencia' => (string) $request->get('referencia'),
            'destinatario' => $request->get('destinatario'),
        ], 'No se pudo comprobar el destinatario: ');
    }
}
