<?php

namespace Tests\Unit;

use App\Mcp\Servers\BalinsaServer;
use App\Mcp\Tools\CertificacionesPrevalidarSolicitudTool;
use App\Mcp\Tools\CertificacionesRegistrarSolicitudTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

class CertificacionesToolsTest extends TestCase
{
    public function test_todas_las_herramientas_estan_registradas_y_tienen_contrato_valido(): void
    {
        $registered = (new \ReflectionClass(BalinsaServer::class))->getDefaultProperties()['tools'];
        $files = glob(dirname(__DIR__, 2).'/app/Mcp/Tools/Certificaciones*Tool.php');
        $this->assertCount(21, $files);
        $names = [];
        foreach ($files as $file) {
            $class = 'App\\Mcp\\Tools\\'.basename($file, '.php');
            $this->assertContains($class, $registered);
            $tool = new $class;
            $defaults = (new \ReflectionClass($tool))->getDefaultProperties();
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $defaults['name']);
            $this->assertNotContains($defaults['name'], $names);
            $names[] = $defaults['name'];
            foreach ($tool->schema(new JsonSchemaTypeFactory) as $field) {
                $this->assertIsArray($field->toArray());
            }
        }
    }

    public function test_prevalidar_filtra_identidad_y_no_envia_confirmacion_o_clave(): void
    {
        $tool = new class extends CertificacionesPrevalidarSolicitudTool
        {
            public array $sent = [];

            protected function enviar(Request $r, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            {
                $this->sent = [$ruta, $cuerpo];

                return Response::text('{}');
            }
        };
        $schema = $tool->schema(new JsonSchemaTypeFactory);
        $this->assertArrayNotHasKey('confirmado', $schema);
        $this->assertArrayNotHasKey('clave_operacion', $schema);
        $tool->handle(new Request(['cliente_id' => 1, 'user_id' => 999, 'confirmado' => true, 'clave_operacion' => 'no',
            'instrumentos' => [['instrumento' => 'Pesa', 'id_certificado' => 999, 'series' => [['num_serie' => '01', 'user_id' => 999]]]]]));
        $this->assertSame('certificaciones/solicitudes/prevalidar', $tool->sent[0]);
        $data = $tool->sent[1];
        foreach (['user_id', 'confirmado', 'clave_operacion'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $this->assertArrayNotHasKey('id_certificado', $data['instrumentos'][0]);
        $this->assertSame([['num_serie' => '01']], $data['instrumentos'][0]['series']);
    }

    public function test_registro_conserva_confirmacion_false_y_todos_los_instrumentos(): void
    {
        $tool = new class extends CertificacionesRegistrarSolicitudTool
        {
            public array $sent = [];

            protected function enviar(Request $r, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            {
                $this->sent = [$ruta, $cuerpo];

                return Response::text('{}');
            }
        };
        $tool->handle(new Request(['confirmado' => false, 'clave_operacion' => 'uuid', 'instrumentos' => [['instrumento' => 'Balanza'], ['instrumento' => 'Pesa']]]));
        $this->assertSame('certificaciones/solicitudes', $tool->sent[0]);
        $this->assertFalse($tool->sent[1]['confirmado']);
        $this->assertCount(2, $tool->sent[1]['instrumentos']);
    }

    public function test_nuevos_filtros_y_series_llegan_al_endpoint_correcto(): void
    {
        $cases = [
            ['CertificacionesBuscarSolicitudesTool', 'certificaciones/solicitudes', ['fecha_solicitud' => '2026-01-31', 'direccion' => 'Planta', 'documento' => '20123']],
            ['CertificacionesBuscarCertificadosTool', 'certificaciones/certificados', ['fecha_solicitud' => '2026-01-31', 'direccion' => 'Planta']],
            ['CertificacionesBuscarPorVencerTool', 'certificaciones/por-vencer', ['cliente_id' => 1, 'direccion' => 'Planta', 'modo' => 'caducados']],
            ['CertificacionesBuscarClientesTool', 'certificaciones/clientes', ['buscar' => 'Cliente', 'pagina' => 2]],
            ['CertificacionesVerSeriesInstrumentoTool', 'certificaciones/solicitudes/7/instrumentos/8/series', ['pagina' => 2, 'limite' => 20]],
        ];
        foreach ($cases as [$class, $ruta, $params]) {
            $fqcn = 'App\\Mcp\\Tools\\'.$class;
            $proxy = 'CertProxy'.$class;
            if (! class_exists(__NAMESPACE__.'\\'.$proxy)) {
                eval('namespace Tests\\Unit; class '.$proxy.' extends \\'.$fqcn.' { public array $sent = []; protected function consultar(\\Laravel\\Mcp\\Request $r, string $ruta, array $query = []): \\Laravel\\Mcp\\Response { $this->sent = [$ruta, $query]; return \\Laravel\\Mcp\\Response::text("{}"); } }');
            }
            $proxy = __NAMESPACE__.'\\'.$proxy;
            $tool = new $proxy;
            $tool->handle(new Request($params + ['id' => 7, 'instrumento_id' => 8, 'user_id' => 999]));
            $this->assertSame($ruta, $tool->sent[0]);
            $this->assertArrayNotHasKey('user_id', $tool->sent[1]);
            foreach ($params as $k => $v) {
                $this->assertSame($v, $tool->sent[1][$k]);
            }
        }
    }
}
