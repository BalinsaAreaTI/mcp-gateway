<?php

namespace App\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;

trait PresentaProductoCatalogo
{
    /** Alinea las lecturas con las columnas cod. e Item del catalogo web. */
    protected function presentarProductos(Response $respuesta): Response
    {
        if ($respuesta->isError()) {
            return $respuesta;
        }

        $datos = json_decode((string) $respuesta->content(), true);
        if (! is_array($datos)) {
            return $respuesta;
        }

        if (isset($datos['data']) && is_array($datos['data'])) {
            $datos['data'] = array_map($this->presentarProducto(...), $datos['data']);
        } else {
            $datos = $this->presentarProducto($datos);
        }

        return Response::text(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function presentarProducto(array $producto): array
    {
        if (array_key_exists('id', $producto) && array_key_exists('codigo', $producto)) {
            $producto['item'] = $producto['codigo'];
            $producto['codigo'] = $producto['id'];
        }

        return $producto;
    }
}
