<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\EsquemaLista;
use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasAgregarCatalogoPendienteTool extends Tool
{
    use EsquemaLista;
    use LlamaAlSistema;

    protected string $name = 'ventas_agregar_catalogo_pendiente';

    protected string $title = 'Añadir al catálogo de pendientes';

    protected string $description = 'Añade UNA marca, modelo, material o clase de exactitud al catálogo de pendientes cuando no existe y el usuario quiere registrarlo. Úsala SOLO después de: (1) buscar el valor con ventas_catalogos_pendientes (parámetro buscar) para confirmar que no existe ni hay una variante parecida; (2) preguntar al usuario si desea añadirlo al catálogo y recibir su confirmación explícita, confirmando la ortografía exacta; el catálogo lo usa toda la empresa y desde el asistente no se puede borrar. La marca y el modelo se añaden SIEMPRE a un tipo de instrumento (Balanza, Termómetro, Pesas patrón u otro) y solo valen para ese tipo: si la misma marca existe para otro tipo y el usuario la necesita en este, hay que añadirla también aquí. El material es siempre de pesas patrón y no depende del tipo; la clase de exactitud no lleva tipo. Si ya existía, lo indica y no duplica. Después de añadirlo, vuelve a registrar o editar el pendiente. Pueden hacerlo Asesor de Ventas, Jefe de Ventas y Gerencia (acceso total).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'catalogo' => $schema->string()->description('Catálogo: marca, modelo, material o clase_exactitud.')->required(),
            'nombre' => $schema->string()->description('Nombre exacto a añadir (hasta 100 caracteres; clase de exactitud hasta 50), tal como lo confirmó el usuario.')->required(),
            'tipo_instrumento' => $schema->string()->description('Tipo de instrumento al que pertenece (Balanza, Termómetro, Pesas patrón u otro). Obligatorio para marca y modelo; opcional para material (pesas patrón); no aplica a clase_exactitud.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/pendientes/catalogo', [
            'catalogo' => $request->get('catalogo'),
            'nombre' => $request->get('nombre'),
            'tipo_instrumento' => $request->get('tipo_instrumento'),
        ], 'No se pudo añadir al catálogo: ');
    }
}
