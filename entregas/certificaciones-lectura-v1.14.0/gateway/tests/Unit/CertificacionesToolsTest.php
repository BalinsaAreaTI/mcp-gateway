<?php

namespace Tests\Unit;

use App\Mcp\Servers\BalinsaServer;
use App\Mcp\Tools\CertificacionesBuscarPorVencerTool;
use App\Mcp\Tools\CertificacionesVerArchivosCertificadoTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class CertificacionesToolsTest extends TestCase
{
    public function test_archivos_usa_id_solicitud_y_solo_parametros_permitidos(): void
    {
        $tool = new class extends CertificacionesVerArchivosCertificadoTool
        {
            public array $sent = [];

            protected function consultar(Request $r, string $ruta, array $query = []): Response
            {
                $this->sent = [$ruta, $query];

                return Response::text('{}');
            }
        };
        $tool->handle(new Request(['id' => 12, 'instrumento_id' => 5, 'pagina' => 2, 'limite' => 10, 'user_id' => 99, 'ruta' => 'secreto', 'confirmado' => true]));
        $this->assertSame('certificaciones/solicitudes/12/archivos', $tool->sent[0]);
        $this->assertSame(['instrumento_id' => 5, 'pagina' => 2, 'limite' => 10], $tool->sent[1]);
    }

    public function test_vencimiento_envia_modo_y_ventana_sin_mutacion(): void
    {
        $tool = new class extends CertificacionesBuscarPorVencerTool
        {
            public array $sent = [];

            protected function consultar(Request $r, string $ruta, array $query = []): Response
            {
                $this->sent = [$ruta, $query];

                return Response::text('{}');
            }
        };
        $tool->handle(new Request(['modo' => 'caducados', 'dias' => 7, 'estado' => 'Renovado']));
        $this->assertSame('certificaciones/por-vencer', $tool->sent[0]);
        $this->assertSame('caducados', $tool->sent[1]['modo']);
        $this->assertArrayNotHasKey('estado', $tool->sent[1]);
    }

    public function test_herramientas_registradas_con_esquemas_validos(): void
    {
        $server = new \ReflectionClass(BalinsaServer::class);
        $registered = $server->getDefaultProperties()['tools'];
        $count = 0;
        foreach (glob(app_path('Mcp/Tools/Certificaciones*Tool.php')) as $path) {
            $class = 'App\\Mcp\\Tools\\'.basename($path, '.php');
            $tool = new $class;
            $this->assertContains($class, $registered);
            $this->assertMatchesRegularExpression('/^certificaciones_[a-z_]{1,48}$/', $tool->name());
            $schema = $tool->schema(new JsonSchemaTypeFactory);
            foreach ($schema as $property) {
                $this->assertNotEmpty($property->toArray()['description']);
            }
            $count++;
        }
        $this->assertSame(8, $count);
    }
}
