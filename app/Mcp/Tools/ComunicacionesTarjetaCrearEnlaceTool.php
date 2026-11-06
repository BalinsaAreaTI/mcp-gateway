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

/** App-only: solo genera enlace; NO confirma, NO realiza SMTP. */
#[RendersApp(resource: ConfirmacionCorreoBalinsaApp::class, visibility: [Visibility::App])]
final class ComunicacionesTarjetaCrearEnlaceTool extends Tool
{
    use LlamaAlSistema;
    protected string $name = 'comunicaciones_tarjeta_crear_enlace';
    protected string $title = 'Generar enlace web firmado para autorizacion';
    protected string $description = 'Uso privado de la tarjeta. Genera URL HTTPS firmada y de corta vigencia para autorizacion en sesion web de Balinsa. No encola ni confirma el correo.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'solicitud_id' => $schema->integer()->required(),
            'resumen_sha256' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = (int) $request->get('solicitud_id');
        return $this->enviar($request,
            'comunicaciones/envios/' . $id . '/enlace-tarjeta',
            ['resumen_sha256' => (string) $request->get('resumen_sha256')],
            'No se pudo preparar el enlace de autorizacion: ');
    }
}
