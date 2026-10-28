<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

class RegistrarNotaPruebaTool extends Tool
{
    protected string $name = 'registrar_nota_prueba';

    protected string $title = 'Registrar nota de prueba';

    protected string $description = 'Guarda una nota de texto corta de prueba a nombre del usuario conectado. Solo sirve para probar que el cliente puede ejecutar herramientas de escritura.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'texto' => $schema->string()
                ->description('Texto de la nota, máximo 200 caracteres.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $texto = trim((string) $request->get('texto'));

        if ($texto === '' || mb_strlen($texto) > 200) {
            return Response::error('El texto es obligatorio y debe tener máximo 200 caracteres.');
        }

        $id = DB::table('pruebas_escritura')->insertGetId([
            'user_id' => $request->user()->id,
            'texto' => $texto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Response::text(json_encode(['id' => $id, 'guardado' => true]));
    }
}
