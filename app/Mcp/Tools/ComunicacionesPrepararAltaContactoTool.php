<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class ComunicacionesPrepararAltaContactoTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_preparar_alta_contacto';
    protected string $title = 'Preparar nuevo contacto externo en CRM';
    protected string $description = 'Prepara UN contacto externo, no lo registra ni envia correos. Antes, obtiene cliente_id de comunicaciones_verificar_destinatario_cotizacion cuando provenga de una cotizacion, o de una consulta autorizada del cliente; comprueba el correo en CRM y solicita nombre, cargo y telefono (opcionales). Si el correo pertenece a otro cliente, no lo dupliques. Presenta cliente, nombre, correo, cargo y telefono completos y solicita confirmacion EXPLICITA en turno posterior; no confirmes en este turno. Clave_operacion estable para reintento identico. El registro no aprueba ni cambia solicitudes de correo anteriores; se prepara NUEVO correo despues de registrar. No muestres hashes, rutas ni detalles tecnicos.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'cliente_id' => $schema->integer()->description('Cliente interno ya identificado y autorizado.')->required(),
            'nombre' => $schema->string()->description('Nombre real del contacto; obligatorio.')->required(),
            'correo' => $schema->string()->description('Direccion externa exacta; obligatoria.')->required(),
            'cargo' => $schema->string()->description('Cargo, opcional.'),
            'telefono' => $schema->string()->description('Telefono, opcional.'),
            'clave_operacion' => $schema->string()->description('Clave unica de 24 a 80 caracteres alfanumericos, guion o guion bajo; generala una vez y reutiliza identica en reintentos. No pidas esta clave al usuario.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'comunicaciones/contactos/preparar', [
            'cliente_id' => $request->get('cliente_id'),
            'nombre' => $request->get('nombre'),
            'correo' => $request->get('correo'),
            'cargo' => $request->get('cargo'),
            'telefono' => $request->get('telefono'),
            'clave_operacion' => $request->get('clave_operacion'),
        ], 'No se pudo preparar el contacto: ');
    }
}
