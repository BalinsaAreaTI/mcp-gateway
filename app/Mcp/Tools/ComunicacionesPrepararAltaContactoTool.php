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
    protected string $description = 'Prepara UN nuevo contacto externo de un cliente existente sin crear aun el contacto y SIN enviar correo. Antes: obtiene el cliente_id con ventas_buscar_clientes, consulta comunicaciones_contactos_cliente, comprueba nombre, correo, cargo y telefono, y NO inventes datos. Presenta al usuario cliente, nombre, correo, cargo y telefono para revision. Despues pregunta confirmacion explicita en UN TURNO POSTERIOR. Nunca confirmes en el mismo turno de preparacion. Reutiliza la misma clave_operacion en reintentos identicos. No muestres hash, rutas ni datos tecnicos.';

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
