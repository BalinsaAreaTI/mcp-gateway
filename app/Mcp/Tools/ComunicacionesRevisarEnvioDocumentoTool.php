<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ComunicacionesRevisarEnvioDocumentoTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_revisar_envio_documento';
    protected string $title = 'Revisar el correo antes de confirmar';
    protected string $description = 'Lee la solicitud preparada para revisarla, sin aprobarla ni enviarla. Muestra De, Para, Asunto, MENSAJE COMPLETO y adjuntos. Nivel 1: ofrece tarjeta o confirmacion expresa posterior con huella exacta. Nivel 2: la aprobacion siempre ocurre en Balinsa Web. Nivel 3: no se puede aprobar. No alteres la solicitud ni reveles estructura interna, hashes o tokens. Si el empleado solicita crear un contacto mientras esta solicitud ya existe, explica que el alta NO reduce retroactivamente su nivel: se necesita preparar un NUEVO correo despues del registro confirmado. No deduzcas que un contacto falta solo por un 403. Nunca confirmes contactos sin consentimiento expreso en turno posterior.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'solicitud_id' => $schema->integer()->description('ID de la solicitud preparada, propia del empleado autenticado.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->consultar($request,
            'comunicaciones/envios/' . (int) $request->get('solicitud_id') . '/revisar');
    }
}
