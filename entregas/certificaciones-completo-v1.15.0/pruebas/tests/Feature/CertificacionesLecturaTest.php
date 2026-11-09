<?php

namespace Tests\Feature;

use App\Http\Controllers\Mcp\McpCertificacionesController;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Monolog\Handler\NullHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CertificacionesLecturaTest extends TestCase
{
    protected McpCertificacionesController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('entregas/certificaciones-completo-v1.15.0/sistema/app/Http/Controllers/Mcp/McpCertificacionesController.php');
        config(['database.default' => 'cert_test', 'database.connections.cert_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'logging.default' => 'cert_test', 'logging.channels.cert_test' => ['driver' => 'monolog', 'handler' => NullHandler::class],
            'mcp.roles_acceso_total' => ['Gerencia'],
            'mcp.certificaciones_roles' => ['Asesor de Ventas', 'Administracion', 'Jefe de Ventas', 'Area de TI', 'Operaciones']]);
        DB::purge('cert_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        // Emula ?nicamente funciones de fecha MySQL; requiere prueba adicional en MySQL real.
        DB::connection()->getPdo()->sqliteCreateFunction('STR_TO_DATE', function ($s, $format) {
            try {
                return CarbonImmutable::createFromFormat('!d/m/Y', $s)->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        }, 2);
        DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT', fn ($s, $f) => $s ? substr($s, 0, 10) : null, 2);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 12:00:00', 'America/Lima'));
        Schema::create('clientes', function (Blueprint $t) {
            $t->id();
            $t->string('razonSocial');
            $t->string('numeroDocumento');
        });
        Schema::create('tb_certificado', function (Blueprint $t) {
            $t->increments('idCertificado');
            $t->integer('idCliente');
            $t->integer('estado_soli')->default(1);
            $t->integer('parent_solicitud_id')->nullable();
            foreach (['direccionCertificado', 'fechaSolicitud', 'fechaCaducidad', 'tiempoCaducidad', 'contactoCertificado', 'telefonoContactoCertificado', 'created_at'] as $c) {
                $t->string($c)->nullable();
            }
        });
        Schema::create('tb_instrumento', function (Blueprint $t) {
            $t->increments('id_Instrumento');
            $t->integer('id_certificado');
            $t->integer('cantidad')->default(1);
            foreach (['tipoInstrumento', 'marca', 'modelo', 'valorNominal', 'procedencia', 'capacidadMax', 'forma', 'interbaloDesde', 'interbaloHasta', 'resolucion', 'elementoSensor', 'puntosCalibracion', 'serie', 'material', 'clase_exactitud'] as $c) {
                $t->text($c)->nullable();
            }
        });
        Schema::create('tb_certificado_archivo', function (Blueprint $t) {
            $t->increments('id_archivo');
            $t->integer('id_certificado');
            $t->integer('id_instrumento')->nullable();
            $t->string('nombre_original')->default('certificado.pdf');
            $t->string('ruta')->default('SECRETO');
            $t->string('serie')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tb_marcas_certificacion', function (Blueprint $t) {
            $t->increments('idMarcaCert');
            $t->string('nombreMarcaCert');
            $t->integer('tipoInstrumento');
        });
        DB::table('clientes')->insert(['id' => 1, 'razonSocial' => 'Cliente A', 'numeroDocumento' => '123']);
        foreach ([1 => '2026-11-03', 2 => '03/12/2026', 3 => '2026-12-04', 4 => '2026-10-27', 5 => '2026-10-26', 6 => null, 7 => '2026-11-04', 8 => '2026-11-05'] as $id => $fecha) {
            DB::table('tb_certificado')->insert(['idCertificado' => $id, 'idCliente' => 1, 'fechaCaducidad' => $fecha, 'created_at' => '2026-10-01 10:00:00']);
        }
        DB::table('tb_certificado')->insert(['idCertificado' => 9, 'idCliente' => 1, 'parent_solicitud_id' => 7, 'created_at' => '2026-01-01', 'estado_soli' => 0]);
        foreach ([1, 2, 3, 4, 5, 7, 9] as $id) {
            DB::table('tb_certificado_archivo')->insert(['id_certificado' => $id]);
        }
        DB::table('tb_instrumento')->insert(['id_Instrumento' => 10, 'id_certificado' => 1, 'cantidad' => 5, 'serie' => '["A","B"]']);
        DB::table('tb_instrumento')->insert(['id_Instrumento' => 11, 'id_certificado' => 2, 'cantidad' => 1]);
        $this->controller = new McpCertificacionesController;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    protected function req(array $data = [], array $roles = ['Asesor de Ventas']): Request
    {
        $u = (object) ['id' => 10, 'roles' => collect(array_map(fn ($r) => (object) ['name' => $r], $roles))];
        $r = Request::create('/api/mcp/certificaciones', 'GET', $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_roles_en_todos_los_endpoints_y_bloqueo_multirrol(): void
    {
        foreach ([['Servicio Tecnico'], ['Area de Almacen'], ['Desconocido'], ['Gerencia', 'Servicio Tecnico']] as $roles) {
            foreach (['buscar', 'certificados', 'porVencer', 'filtros', 'catalogos', 'ver', 'instrumentos', 'archivos'] as $metodo) {
                try {
                    $this->controller->$metodo($this->req([], $roles), 1);
                    $this->fail('Debi? denegar');
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode());
                }
            }
        }
        foreach (['Gerencia', 'Area de TI', 'Administracion', 'Jefe de Ventas', 'Operaciones', 'Asesor de Ventas'] as $rol) {
            $this->assertSame(8, $this->controller->buscar($this->req([], [$rol]))->getData(true)['total']);
        }
    }

    public function test_paginacion_filtros_y_detalle(): void
    {
        $r = $this->controller->buscar($this->req(['limite' => 2, 'pagina' => 2]))->getData(true);
        $this->assertFalse($r['textos_recortados']);
        $this->assertSame([6, 5], array_column($r['data'], 'id'));
        $this->assertTrue($r['hay_mas']);
        $this->assertSame(6, $this->controller->buscar($this->req(['estado_archivos' => 'Recibido']))->getData(true)['total']);
        $this->assertSame(0, $this->controller->buscar($this->req(['cliente_id' => 999]))->getData(true)['total']);
        $d = $this->controller->ver($this->req(), 1)->getData(true);
        $this->assertSame(2, $d['archivos_esperados']);
        $this->assertSame(1, $d['archivos_pendientes']);
        $this->assertSame('2026-12-03', $this->controller->ver($this->req(), 2)->getData(true)['fecha_caducidad']);
    }

    public function test_vencimientos_limites_y_renovacion_atendida(): void
    {
        $r = $this->controller->porVencer($this->req())->getData(true);
        $this->assertSame([1, 2], array_column($r['data'], 'id'));
        $r = $this->controller->porVencer($this->req(['modo' => 'caducados']))->getData(true);
        $this->assertSame([4], array_column($r['data'], 'id'));
        $this->assertSame(1, $this->controller->porVencer($this->req(['dias' => 7]))->getData(true)['total']);
    }

    public function test_activos_y_pertenencia_y_metadatos_sin_rutas(): void
    {
        foreach (['ver', 'instrumentos', 'archivos'] as $metodo) {
            try {
                $this->controller->$metodo($this->req(), 9);
                $this->fail();
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        foreach (['instrumentos', 'archivos'] as $metodo) {
            try {
                $this->controller->$metodo($this->req(['instrumento_id' => 11]), 1);
                $this->fail();
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        $r = $this->controller->archivos($this->req(), 1)->getData(true);
        $this->assertArrayNotHasKey('ruta', $r['data'][0]);
        $this->assertStringNotContainsString('SECRETO', json_encode($r));
    }

    public function test_validacion_y_fechas_opcionales(): void
    {
        foreach ([['limite' => 21], ['pagina' => 0], ['desde' => '2026-11-04', 'hasta' => '2026-11-03'], ['desde' => 'ayer'], ['estado_archivos' => 'otro']] as $data) {
            try {
                $this->controller->buscar($this->req($data));
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(8, $this->controller->buscar($this->req(['hasta' => '2026-10-01']))->getData(true)['total']);
        $this->assertSame(8, $this->controller->certificados($this->req())->getData(true)['total']);
        DB::table('tb_certificado')->where('idCertificado', 1)->update(['created_at' => '2026-08-01']);
        $this->assertSame(7, $this->controller->certificados($this->req())->getData(true)['total']);
    }

    public function test_catalogos_y_recorte(): void
    {
        DB::table('tb_marcas_certificacion')->insert([
            ['nombreMarcaCert' => 'Marca A', 'tipoInstrumento' => 1], ['nombreMarcaCert' => 'Marca B', 'tipoInstrumento' => 2]]);
        $r = $this->controller->catalogos($this->req(['catalogo' => 'marcas', 'tipo' => 2]))->getData(true);
        $this->assertSame(1, $r['total']);
        $this->assertSame('Marca B', $r['data'][0]['nombre']);
        DB::table('tb_instrumento')->where('id_Instrumento', 10)->update(['modelo' => str_repeat('a', 1200)]);
        $r = $this->controller->instrumentos($this->req(), 1)->getData(true);
        $this->assertTrue($r['textos_recortados']);
        $this->assertLessThanOrEqual(1003, strlen($r['data'][0]['modelo']));
    }
}
