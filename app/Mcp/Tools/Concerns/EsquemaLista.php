<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;

trait EsquemaLista
{
    /**
     * Lista de objetos. Si la versión instalada de laravel/mcp no admite la forma anidada,
     * cae a una lista simple (la descripción siempre explica la forma de cada elemento).
     */
    protected function listaDeObjetos(JsonSchema $schema, array $propiedades, string $descripcion)
    {
        try {
            return $schema->array()->items($schema->object($propiedades))->description($descripcion)->required();
        } catch (\Throwable $e) {
            return $schema->array()->description($descripcion)->required();
        }
    }
}
