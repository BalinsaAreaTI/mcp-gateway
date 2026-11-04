<?php

namespace Tests\Feature;

use App\Http\Controllers\Mcp\McpCertificacionesController;
use App\Http\Controllers\Mcp\McpCertificacionesEscrituraController;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Monolog\Handler\NullHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CertificacionesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $base = base_path('entregas/actualizacion-certificaciones-v1.15.0/sistema');
        if (! is_dir($base)) {
            $base = base_path();
        }
        require_once $base.'/app/Http/Controllers/Mcp/McpCertificacionesController.php';
        require_once $base.'/app/Http/Controllers/Mcp/McpCertificacionesEscrituraController.php';
        config(['database.default' => 'cert_test', 'database.connections.cert_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'logging.default' => 'cert_test', 'logging.channels.cert_test' => ['driver' => 'monolog', 'handler' => NullHandler::class],
            'mcp.certificaciones_roles' => ['Administracion', 'Area de TI', 'Operaciones'],
            'mcp.roles_acceso_total' => ['Gerencia'], 'mcp.certificaciones_roles_bloqueados' => ['Servicio Tecnico', 'Area de Almacen'],
            'mcp.certificaciones_roles_registrar' => ['Administracion'], 'mcp.certificaciones_roles_subir' => ['Administracion'],
            'mcp.certificaciones_roles_eliminar' => ['Area de TI'],
            'mcp.certificaciones_formas' => ['CILINDRICA SOLA'], 'mcp.certificaciones_materiales' => ['ACERO INOXIDABLE']]);
        DB::purge('cert_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        // Adapt only MySQL date functions for SQLite; keep controller query predicates intact.
        DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT', fn ($d, $f) => $d ? substr($d, 0, 10) : null, 2);
        DB::connection()->getPdo()->sqliteCreateFunction('STR_TO_DATE', fn ($d, $f) => \DateTimeImmutable::createFromFormat('!d/m/Y', $d)?->format('Y-m-d'), 2);
        Schema::create('tbprovincias', fn (Blueprint $t) => $t->id());
        Schema::create('clientes', function (Blueprint $t) {
            $t->id();
            $t->string('razonSocial');
            $t->string('numeroDocumento');
            $t->string('direccion');
            $t->string('cli_direccion2')->nullable();
            $t->integer('tbprovincia_id');
        });
        Schema::create('tb_certificado', function (Blueprint $t) {
            $t->increments('idCertificado');
            $t->integer('idCliente');
            $t->integer('estado_soli')->default(1);
            $t->integer('parent_solicitud_id')->nullable();
            $t->integer('dato_contacto_id')->nullable();
            foreach (['direccionCertificado', 'fechaSolicitud', 'fechaCaducidad', 'tiempoCaducidad', 'contactoCertificado', 'telefonoContactoCertificado', 'estadoRenovacion'] as $c) {
                $t->string($c)->nullable();
            } $t->timestamps();
        });
        Schema::create('tb_instrumento', function (Blueprint $t) {
            $t->increments('id_Instrumento');
            $t->integer('id_certificado');
            $t->integer('cantidad');
            foreach (['tipoInstrumento', 'marca', 'modelo', 'procedencia', 'valorNominal', 'capacidadMax', 'forma', 'material', 'clase_exactitud', 'interbaloDesde', 'interbaloHasta', 'resolucion', 'elementoSensor', 'serie', 'puntosCalibracion'] as $c) {
                $t->text($c)->nullable();
            }
        });
        Schema::create('tb_certificado_archivo', function (Blueprint $t) {
            $t->increments('id_archivo');
            $t->integer('id_certificado');
            $t->integer('id_instrumento');
            $t->string('serie')->nullable();
            $t->string('nombre_original')->nullable();
            $t->timestamps();
        });
        Schema::create('datos', function (Blueprint $t) {
            $t->id();
            $t->integer('cliente_id');
            foreach (['nombre', 'telefono', 'correo', 'cargo'] as $c) {
                $t->string($c)->nullable();
            } $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('tb_pesa_clase_exactitud', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
        });
        Schema::create('mcp_certificacion_catalogo_lock', fn (Blueprint $t) => $t->id());
        Schema::create('mcp_certificacion_operaciones', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');
            $t->string('clave');
            $t->string('huella');
            $t->text('respuesta')->nullable();
            $t->unique(['user_id', 'clave']);
            $t->timestamps();
        });
        DB::table('tbprovincias')->insert(['id' => 1]);
        DB::table('clientes')->insert(['id' => 1, 'razonSocial' => 'Cliente A', 'numeroDocumento' => '20123456789', 'direccion' => 'Principal', 'cli_direccion2' => 'Planta', 'tbprovincia_id' => 1]);
        DB::table('tb_pesa_clase_exactitud')->insert(['nombre' => 'M1']);
        DB::table('mcp_certificacion_catalogo_lock')->insert(['id' => 1]);
        DB::table('tb_certificado')->insert(['idCertificado' => 1, 'idCliente' => 1, 'fechaSolicitud' => '31/01/2026', 'fechaCaducidad' => '2026-11-10', 'created_at' => '2026-10-01 10:00:00', 'direccionCertificado' => 'Planta']);
    }

    private function req(array $data = [], array $roles = ['Administracion']): Request
    {
        $r = Request::create('/', 'POST', $data);
        $r->setUserResolver(fn () => (object) ['id' => 10, 'roles' => collect(array_map(fn ($name) => (object) ['name' => $name], $roles))]);

        return $r;
    }

    private function payload(): array
    {
        return ['cliente_id' => 1, 'direccion' => 'Planta', 'fecha_emision' => '2026-01-31', 'meses_vigencia' => 1,
            'instrumentos' => [['instrumento' => 'Pesa', 'cantidad' => 1, 'marca' => 'NO INDICA', 'procedencia' => 'NO INDICA',
                'valor_nominal' => 'NO INDICA', 'forma' => 'CILINDRICA SOLA', 'material' => 'ACERO INOXIDABLE', 'clase_exactitud' => 'M1',
                'series' => [['num_serie' => 'P1', 'identificacion' => 'INT-1']]]]];
    }

    public function test_prevalidar_no_escribe_y_registro_calcula_la_misma_caducidad(): void
    {
        $ctl = new McpCertificacionesEscrituraController;
        $p = $this->payload();
        $r = $ctl->prevalidar($this->req($p))->getData(true);
        $this->assertFalse($r['guardado']);
        $this->assertSame('2026-03-03', $r['fecha_caducidad']);
        $this->assertSame(1, DB::table('tb_certificado')->count());
        $this->assertSame(0, DB::table('mcp_certificacion_operaciones')->count());
        $p += ['confirmado' => true, 'clave_operacion' => '12345678-1234-4234-8234-123456789abc'];
        $saved = $ctl->registrar($this->req($p))->getData(true);
        $this->assertSame('03/03/2026', DB::table('tb_certificado')->where('idCertificado', $saved['id'])->value('fechaCaducidad'));
        $this->assertTrue($ctl->registrar($this->req($p))->getData(true)['repetida']);
        $this->assertSame(2, DB::table('tb_certificado')->count());
    }

    public function test_prevalidar_rechaza_series_duplicadas(): void
    {
        $p = $this->payload();
        $p['instrumentos'][0]['cantidad'] = 2;
        $p['instrumentos'][0]['series'][] = $p['instrumentos'][0]['series'][0];
        $this->expectException(ValidationException::class);
        (new McpCertificacionesEscrituraController)->prevalidar($this->req($p));
    }

    public function test_contacto_de_otro_cliente_no_pasa_prevalidacion(): void
    {
        DB::table('datos')->insert(['id' => 7, 'cliente_id' => 2, 'nombre' => 'Otro']);
        $this->expectException(HttpException::class);
        (new McpCertificacionesEscrituraController)->prevalidar($this->req($this->payload() + ['contacto_id' => 7]));
    }

    public function test_series_largas_paginadas_y_varios_pdf_no_ocultan_pendientes(): void
    {
        $series = array_map(fn ($n) => ['num_serie' => 'S'.$n, 'identificacion' => str_repeat('X', 80)], range(1, 25));
        DB::table('tb_instrumento')->insert(['id_Instrumento' => 1, 'id_certificado' => 1, 'cantidad' => 25, 'serie' => json_encode($series)]);
        for ($n = 0; $n < 25; $n++) {
            DB::table('tb_certificado_archivo')->insert(['id_certificado' => 1, 'id_instrumento' => 1, 'serie' => 'S1']);
        }
        $ctl = new McpCertificacionesController;
        $r = $ctl->series($this->req(['pagina' => 2, 'limite' => 20]), 1, 1)->getData(true);
        $this->assertSame(24, $r['series_pendientes']);
        $this->assertSame(5, count($r['data']));
        $this->assertSame('S21', $r['data'][0]['serie']);
        $this->assertFalse($r['hay_mas']);
        $this->assertTrue($r['data'][0]['puede_subir']);
        $this->expectException(HttpException::class);
        $ctl->series($this->req(), 1, 999);
    }

    public function test_series_ambiguas_no_permiten_carga(): void
    {
        DB::table('tb_instrumento')->insert(['id_Instrumento' => 1, 'id_certificado' => 1, 'cantidad' => 2, 'serie' => '["DUP","DUP"]']);
        $r = (new McpCertificacionesController)->series($this->req(), 1, 1)->getData(true);
        $this->assertSame(2, $r['series_ambiguas']);
        $this->assertFalse($r['data'][0]['puede_subir']);
    }

    public function test_fecha_emision_independiente_de_creacion(): void
    {
        $ctl = new McpCertificacionesController;
        $r = $ctl->buscar($this->req(['fecha_solicitud' => '2026-01-31', 'desde' => '2026-10-01', 'documento' => '201234', 'direccion' => 'Planta']))->getData(true);
        $this->assertSame(1, $r['total']);
        $this->assertSame(0, $ctl->buscar($this->req(['fecha_solicitud' => '2026-10-01']))->getData(true)['total']);
    }

    public function test_administracion_busca_clientes_sin_permisos_de_ventas(): void
    {
        $r = (new McpCertificacionesController)->clientes($this->req(['buscar' => '201234']))->getData(true);
        $this->assertSame('Planta', $r['data'][0]['direccion_alternativa']);
        $this->assertSame(1, $r['total']);
    }

    public function test_rol_bloqueado_prevalece_sobre_gerencia(): void
    {
        try {
            (new McpCertificacionesController)->clientes($this->req([], ['Gerencia', 'Servicio Tecnico']));
            $this->fail('Debe denegar');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_operaciones_no_crea_borradores(): void
    {
        $this->expectException(HttpException::class);
        (new McpCertificacionesEscrituraController)->prevalidar($this->req($this->payload(), ['Operaciones']));
    }

    public function test_por_vencer_respeta_limites_y_excluye_renovaciones_atendidas(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 12:00:00', 'America/Lima'));
        try {
            foreach ([1 => '2026-11-10', 2 => '2026-11-11', 3 => '2026-10-27', 4 => '2026-10-26', 5 => '2026-11-03'] as $id => $fecha) {
                DB::table('tb_certificado')->updateOrInsert(['idCertificado' => $id], ['idCliente' => 1, 'estado_soli' => 1, 'fechaCaducidad' => $fecha]);
                DB::table('tb_certificado_archivo')->insert(['id_certificado' => $id, 'id_instrumento' => $id, 'serie' => 'S']);
            }
            $ctl = new McpCertificacionesController;
            $ids = fn ($d) => array_column($ctl->porVencer($this->req($d))->getData(true)['data'], 'id');
            $this->assertSame([5, 1], $ids(['dias' => 7]));
            $this->assertSame([3], $ids(['modo' => 'caducados']));
            DB::table('tb_certificado')->insert(['idCertificado' => 6, 'idCliente' => 1, 'parent_solicitud_id' => 1, 'estado_soli' => 1]);
            DB::table('tb_certificado_archivo')->insert(['id_certificado' => 6, 'id_instrumento' => 6, 'serie' => 'S']);
            $this->assertSame([5], $ids(['dias' => 7]));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_eliminar_solo_ti_y_rechaza_version_obsoleta(): void
    {
        $ctl = new McpCertificacionesEscrituraController;
        $version = $ctl->ver($this->req(), 1)->getData(true)['version_actual'];
        try {
            $ctl->eliminar($this->req(['confirmado' => true, 'version_actual' => $version], ['Gerencia']), 1);
            $this->fail('Solo TI');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        DB::table('tb_certificado')->where('idCertificado', 1)->update(['direccionCertificado' => 'Otra']);
        try {
            $ctl->eliminar($this->req(['confirmado' => true, 'version_actual' => $version], ['Area de TI']), 1);
            $this->fail('Versión obsoleta');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version_actual', $e->errors());
        }
        $this->assertSame(1, DB::table('tb_certificado')->where('idCertificado', 1)->value('estado_soli'));
    }

    public function test_catalogo_disponible_inmediatamente_tras_alta(): void
    {
        $ctl = new McpCertificacionesEscrituraController;
        $this->assertSame(1, $ctl->catalogos($this->req(['catalogo' => 'clases_exactitud']))->getData(true)['total']);
        $ctl->agregarCatalogo($this->req(['catalogo' => 'clases_exactitud', 'nombre' => 'M2', 'confirmado' => true, 'clave_operacion' => '12345678-1234-4234-8234-123456789abc']));
        $this->assertSame(2, $ctl->catalogos($this->req(['catalogo' => 'clases_exactitud']))->getData(true)['total']);
    }
}
