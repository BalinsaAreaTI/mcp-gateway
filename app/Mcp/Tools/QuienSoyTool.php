<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

use App\Models\User;
use App\Services\SistemaClient;
use RuntimeException;


class QuienSoyTool extends Tool
{
    /**
     * Handle the tool request.
     */
    protected string $name = 'quien_soy';

    protected string $title = 'Quién soy';

    protected string $description = 'Devuelve el nombre, correo y roles del usuario conectado en el sistema de la empresa. Úsala para confirmar con qué cuenta está operando el agente.';

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return Response::error('No se pudo identificar al usuario conectado.');
        }

        try {
            $me = (new SistemaClient($user))->get('me');
        } catch (RuntimeException $e) {
            return Response::error($e->getCode() === 401
                ? 'Tu conexión con el sistema venció. Vuelve a conectar el conector desde tu cliente de IA.'
                : 'El sistema no respondió correctamente. Inténtalo más tarde.');
        }

        return Response::text(json_encode([
            'nombre' => $me['name'] ?? null,
            'email'  => $me['email'] ?? null,
            'roles'  => $me['roles'] ?? [],
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            //
        ];
    }
}
