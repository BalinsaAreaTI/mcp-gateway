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

/** Abre la interfaz y hace la primera lectura; no autoriza ni encola. */
#[RendersApp(resource: ConfirmacionCorreoBalinsaApp::class, visibility: [Visibility::Model])]
final class ComunicacionesMostrarTarjetaConfirmacionTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'comunicaciones_mostrar_tarjeta_confirmacion';
    protected string $title = 'Mostrar tarjeta de confirmacion de correo';
    protected string $description = 'Muestra la tarjeta visual para revisar y autorizar el correo, sin aprobar ni enviar por si misma. Nivel 1: presenta revision y autorizacion segura; nivel 2: abre exclusivamente Balinsa Web para aprobar personalmente; nivel 3: bloqueado, sin boton. Antes de autorizar se deben mostrar de, para, asunto, mensaje completo y adjuntos. Despues de la aprobacion, si la solicitud figura encolado/procesando, explica al empleado que esta en proceso y debe consultar el estado mas adelante; no digas que fallo o no se envio. No abras enlaces ni confirmes por el usuario. No muestres detalles de la arquitectura al empleado.';

    public function schema(JsonSchema $schema): array
    {
        return ['solicitud_id' => $schema->integer()->required()
            ->description('ID de la solicitud preparada para el usuario autenticado.')];
    }

    public function handle(Request $request): Response
    {
        return $this->consultar($request, 'comunicaciones/envios/' . (int) $request->get('solicitud_id') . '/revisar');
    }
}
