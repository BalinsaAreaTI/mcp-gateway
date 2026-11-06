<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\AppMeta;
use Laravel\Mcp\Server\Attributes\Description;

/** Requiere version laravel/mcp con AppResource, AppMeta y RendersApp. */
#[Description('Tarjeta interactiva de revision y autorizacion segura de correos Balinsa.')]
#[AppMeta]
final class ConfirmacionCorreoBalinsaApp extends AppResource
{
    public function handle(Request $request): Response
    {
        return Response::view('mcp.confirmacion-correo-balinsa', [
            'title' => 'Confirmacion segura de correo - Balinsa',
        ]);
    }
}
