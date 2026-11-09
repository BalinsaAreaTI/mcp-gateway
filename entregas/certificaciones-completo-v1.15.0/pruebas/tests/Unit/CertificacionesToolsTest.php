<?php

namespace Tests\Unit;

use App\Mcp\Servers\BalinsaServer;
use App\Mcp\Tools\CertificacionesAbrirArchivoCertificadoTool;
use App\Mcp\Tools\CertificacionesBuscarPorVencerTool;
use App\Mcp\Tools\CertificacionesPrepararSubidaCertificadoTool;
use App\Mcp\Tools\CertificacionesRegistrarSolicitudTool;
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
        $this->assertSame(18, $count);
    }

    public function test_registro_filtra_claves_anidadas_y_reutiliza_clave(): void
    {
        $tool = new class extends CertificacionesRegistrarSolicitudTool
        {
            public array $sent = [];

            protected function enviar(Request $r, string $ruta, array $datos, string $prefijo422 = 'No se pudo registrar: '): Response
            {
                $this->sent = [$ruta, $datos];

                return Response::text('{}');
            }
        };
        $tool->handle(new Request(['cliente_id' => 1, 'clave_operacion' => 'clave', 'confirmado' => true, 'user_id' => 99, 'estado_soli' => 0,
            'instrumentos' => [['instrumento' => 'Pesa', 'ruta' => 'secreto', 'id_certificado' => 99, 'series' => [['num_serie' => 'A', 'identificacion' => 'I', 'admin' => true]]]]]));
        $this->assertSame('certificaciones/solicitudes', $tool->sent[0]);
        $this->assertSame('clave', $tool->sent[1]['clave_operacion']);
        $this->assertArrayNotHasKey('user_id', $tool->sent[1]);
        $i = $tool->sent[1]['instrumentos'][0];
        $this->assertArrayNotHasKey('ruta', $i);
        $this->assertArrayNotHasKey('id_certificado', $i);
        $this->assertSame(['num_serie' => 'A', 'identificacion' => 'I'], $i['series'][0]);
    }

    public function test_subida_y_enlace_usan_solicitud_instrumento_archivo_correctos(): void
    {
        $tool = new class extends CertificacionesPrepararSubidaCertificadoTool
        {
            public array $sent = [];

            protected function enviar(Request $r, string $ruta, array $datos, string $prefijo422 = 'No se pudo registrar: '): Response
            {
                $this->sent = [$ruta, $datos];

                return Response::text('{}');
            }
        };
        $tool->handle(new Request(['id' => 8, 'instrumento_id' => 11, 'serie' => 'S1', 'version_actual' => 'v', 'confirmado' => true, 'token' => 'no']));
        $this->assertSame('certificaciones/solicitudes/8/subida', $tool->sent[0]);
        $this->assertSame(['instrumento_id' => 11, 'serie' => 'S1', 'version_actual' => 'v', 'confirmado' => true], $tool->sent[1]);
        $link = new class extends CertificacionesAbrirArchivoCertificadoTool
        {
            public array $sent = [];

            protected function consultar(Request $r, string $ruta, array $query = []): Response
            {
                $this->sent = [$ruta, $query];

                return Response::text('{}');
            }
        };
        $link->handle(new Request(['id' => 8, 'archivo_id' => 12, 'modo' => 'descargar', 'ruta' => 'no']));
        $this->assertSame(['certificaciones/solicitudes/8/archivos/12/enlace', ['modo' => 'descargar']], $link->sent);
    }
}
