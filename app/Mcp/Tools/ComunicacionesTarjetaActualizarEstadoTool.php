<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Resources\ConfirmacionCorreoBalinsaApp;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Ui\Enums\Visibility;

/** App-only, solo lectura. Actualiza estado tras la autorizacion web. */
#[RendersApp(resource: ConfirmacionCorreoBalinsaApp::class, visibility: [Visibility::App])]
final class ComunicacionesTarjetaActualizarEstadoTool extends Tool
{
    use LlamaAlSistema;
    protected string $name = 'comunicaciones_tarjeta_actualizar_estado';
    protected string $title = 'Actualizar el estado de la solicitud en la tarjeta';
    protected string $description = 'Uso privado de la tarjeta, consulta el estado de autorizacion y el resumen de la solicitud. No envia ni aprueba.';

    public function schema(JsonSchema $schema): array
    {
        return ['solicitud_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        return $this->consultar($request,
            'comunicaciones/envios/' . (int) $request->get('solicitud_id') . '/revisar');
    }
}
