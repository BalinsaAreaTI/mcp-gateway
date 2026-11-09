<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class ComunicacionesImprimirDocumentoTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_imprimir_documento';
    protected string $title = 'Imprimir documento en Balinsa';
    protected string $description = 'ACCION REAL SIN APROBACION ADICIONAL: usar SOLO cuando el empleado pide expresamente IMPRIMIR una cotizacion u hoja de salida en una impresora de Balinsa. NO usar herramientas de contactos CRM ni comunicaciones_preparar_envio_documento; imprimir NO equivale a enviar un correo al cliente. Usar tipo y REFERENCIA INTERNA del documento que el empleado puede consultar, y codigo de impresora permitido (por defecto principal). Nunca aceptar direcciones de correo, archivos subidos, URL, copia multiple ni destino inventado. Laravel valida rol, documento, permiso y correo de la impresora en lista cerrada. Llamar UNA sola vez; no repetir por falta de estado inmediato. Responder: "Documento enviado a la cola de impresion". Si se pide comprobacion, usar comunicaciones_consultar_estado_impresion. smtp_aceptado no garantiza papel impreso. Un fallo 403 de documento no se soluciona registrando un contacto. No exponer detalles internos ni el correo de la impresora.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tipo' => $schema->string()->description('cotizacion_venta, cotizacion_servicio o hoja_salida.')->required(),
            'referencia' => $schema->string()->description('ID INTERNO del documento como texto.')->required(),
            'impresora' => $schema->string()->description('Codigo habilitado, por ejemplo principal. Si no se indica, se usa principal.'),
            'clave_idempotencia' => $schema->string()->description('Clave unica opcional 24-80 caracteres. Reutilizar la misma solo para repetir exactamente la misma accion.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'comunicaciones/impresiones', [
            'tipo' => $request->get('tipo'),
            'referencia' => (string) $request->get('referencia'),
            'impresora' => $request->get('impresora'),
            'clave_idempotencia' => $request->get('clave_idempotencia'),
        ], 'No se pudo solicitar la impresion: ');
    }
}
