<?php

namespace Tests\Feature;

use App\Http\Controllers\Mcp\McpCertificacionesArchivosController;
use App\Http\Controllers\Mcp\McpCertificacionesEscrituraController;
use App\Services\CertificacionSolicitudPdf;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CertificacionesEscrituraTest extends CertificacionesLecturaTest
{
    private McpCertificacionesEscrituraController $writer;

    private McpCertificacionesArchivosController $files;

    protected function setUp(): void
    {
        parent::setUp();
        $base = base_path('entregas/certificaciones-completo-v1.15.0/sistema');
        require_once $base.'/app/Http/Controllers/Mcp/McpCertificacionesEscrituraController.php';
        require_once $base.'/app/Http/Controllers/Mcp/McpCertificacionesArchivosController.php';
        require_once $base.'/app/Http/Controllers/Mcp/McpCertificacionesPdfController.php';
        require_once $base.'/app/Services/CertificacionSolicitudPdf.php';
        $this->app['view']->addLocation($base.'/resources/views');
        $this->app['view']->share('errors', new ViewErrorBag);
        config(['app.url' => 'http://localhost', 'cache.default' => 'array']);
        $cfg = eval('return ['.file_get_contents($base.'/config/mcp-certificaciones-BLOQUE.txt').'];');
        foreach ($cfg as $k => $v) {
            config(['mcp.'.$k => $v]);
        }
        (require $base.'/database/migrations/2026_11_03_000001_create_mcp_certificaciones_support.php')->up();
        Schema::table('tb_certificado', function (Blueprint $t) {
            $t->integer('dato_contacto_id')->nullable();
            $t->string('estadoRenovacion')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::table('tb_certificado_archivo', function (Blueprint $t) {
            $t->string('mime')->nullable();
            $t->integer('size')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('datos', function (Blueprint $t) {
            $t->id();
            $t->integer('cliente_id');
            foreach (['nombre', 'telefono', 'correo', 'cargo'] as $c) {
                $t->string($c)->nullable();
            }
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        foreach (['tb_pesa_valor_nominal', 'tb_pesa_clase_exactitud', 'tb_procedencia_balanza', 'tb_procedencia_termometro', 'tb_termometro_elemento_sensor'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('nombre');
            });
        }
        Schema::create('tbmodelo_pendientes', function (Blueprint $t) {
            $t->id();
            $t->string('nombre_modelo');
            $t->integer('tipo_instrumento');
        });
        Schema::create('tb_capacidad_escala', function (Blueprint $t) {
            $t->id();
            $t->string('capacidad');
            $t->string('division');
        });
        Schema::create('tb_escala', function (Blueprint $t) {
            $t->increments('idEscala');
            $t->string('nombreDiv');
        });
        DB::table('tb_pesa_valor_nominal')->insert(['nombre' => '10 kg']);
        DB::table('tb_pesa_clase_exactitud')->insert(['nombre' => 'M1']);
        DB::table('tbmodelo_pendientes')->insert(['nombre_modelo' => 'T1', 'tipo_instrumento' => 2]);
        DB::table('tb_termometro_elemento_sensor')->insert(['nombre' => 'PT100']);
        DB::table('datos')->insert(['id' => 1, 'cliente_id' => 1, 'nombre' => 'Ana', 'telefono' => '123']);
        DB::table('datos')->insert(['id' => 2, 'cliente_id' => 99, 'nombre' => 'Ajeno']);
        $this->writer = new McpCertificacionesEscrituraController;
        $this->files = new McpCertificacionesArchivosController;
        Route::get('mcp/certificaciones/{id}/subir', fn () => null)->name('mcp.certificaciones.subir');
        Route::get('mcp/certificaciones/{id}/archivos/{archivoId}', fn () => null)->name('mcp.certificaciones.archivo');
        Route::get('mcp/certificaciones/{id}/solicitud.pdf', fn () => null)->name('mcp.certificaciones.solicitud_pdf');
        Route::getRoutes()->refreshNameLookups();
        Storage::fake('certificados');
    }

    private function version(int $id = 1): string
    {
        return $this->controller->ver($this->req(), $id)->getData(true)['version_actual'];
    }

    private function confirmed(int $id = 1): array
    {
        return ['confirmado' => true, 'version_actual' => $this->version($id)];
    }

    private function payload(): array
    {
        return ['confirmado' => true, 'clave_operacion' => (string) Str::uuid(), 'cliente_id' => 1, 'direccion' => 'Planta A',
            'fecha_emision' => '2026-01-31', 'meses_vigencia' => 1, 'contacto_id' => 1,
            'instrumentos' => [['instrumento' => 'Pesa', 'cantidad' => 1, 'marca' => 'NO INDICA', 'procedencia' => 'NO INDICA', 'valor_nominal' => '10 kg',
                'forma' => 'CILINDRICA SOLA', 'material' => 'BRONCE', 'clase_exactitud' => 'M1', 'series' => [['num_serie' => 'P1', 'identificacion' => 'I1']]]]];
    }

    private function validation(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Debió fallar validación');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function assertStatusError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Debió denegar');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_creacion_atomica_idempotencia_y_contacto_del_cliente(): void
    {
        $p = $this->payload();
        $result = $this->writer->registrar($this->req($p))->getData(true);
        $id = $result['id'];
        $this->assertTrue($result['registrado']);
        $row = DB::table('tb_certificado')->where('idCertificado', $id)->first();
        $this->assertSame('03/03/2026', $row->fechaCaducidad);
        $this->assertSame('Ana', $row->contactoCertificado);
        $this->assertSame($id, $this->writer->registrar($this->req($p))->getData(true)['id']);
        $this->assertSame(10, DB::table('tb_certificado')->count());
        $p['direccion'] = 'Otra';
        $this->validation(fn () => $this->writer->registrar($this->req($p)));
        $p = $this->payload();
        $p['contacto_id'] = 2;
        $this->assertStatusError(404, fn () => $this->writer->registrar($this->req($p)));
        $p = $this->payload();
        $p['instrumentos'][0]['cantidad'] = 2;
        $this->validation(fn () => $this->writer->registrar($this->req($p)));
        $this->assertSame(10, DB::table('tb_certificado')->count());
        $this->assertSame(1, DB::table('mcp_certificacion_operaciones')->count());
    }

    public function test_reglas_tipos_catalogos_y_confirmacion(): void
    {
        $p = $this->payload();
        $p['confirmado'] = false;
        $this->validation(fn () => $this->writer->registrar($this->req($p)));
        $p = $this->payload();
        $p['instrumentos'][0]['material'] = 'INVENTADO';
        $this->validation(fn () => $this->writer->registrar($this->req($p)));
        $p = $this->payload();
        $p['instrumentos'][0]['clase_exactitud'] = 'NO EXISTE';
        $this->validation(fn () => $this->writer->registrar($this->req($p)));
        $p = $this->payload();
        $p['instrumentos'][0] = ['instrumento' => 'Termometro', 'cantidad' => 1, 'marca' => 'NO INDICA', 'procedencia' => 'NO INDICA', 'modelo' => 'T1',
            'desde_medicion' => '0', 'hasta_medicion' => '100', 'resolucion' => '1', 'elemento_sensor' => 'PT100', 'puntos_calibrar' => ['0', '50', '100'],
            'series' => [['num_serie' => 'T1', 'identificacion' => 'I1']]];
        $id = $this->writer->registrar($this->req($p))->getData(true)['id'];
        $this->assertSame('["0","50","100"]', DB::table('tb_instrumento')->where('id_certificado', $id)->value('puntosCalibracion'));
        $p = $this->payload();
        $p['instrumentos'][0]['ruta'] = 'NO';
        $this->validation(fn () => $this->writer->registrar($this->req($p)));
    }

    public function test_roles_escritura_y_baja_solo_ti(): void
    {
        foreach (['Jefe de Ventas', 'Operaciones', 'Servicio Tecnico', 'Area de Almacen'] as $rol) {
            $this->assertStatusError(403, fn () => $this->writer->registrar($this->req($this->payload(), [$rol])));
        }
        foreach (['Gerencia', 'Asesor de Ventas', 'Administracion'] as $rol) {
            $this->assertStatusError(403, fn () => $this->writer->eliminar($this->req($this->confirmed(), [$rol]), 1));
        }
        $this->writer->eliminar($this->req($this->confirmed(), ['Area de TI']), 1);
        $this->assertSame(0, DB::table('tb_certificado')->where('idCertificado', 1)->value('estado_soli'));
        $this->assertSame(1, DB::table('tb_certificado_archivo')->where('id_certificado', 1)->count());
        $this->assertStatusError(404, fn () => $this->controller->ver($this->req(), 1));
    }

    public function test_fechas_version_obsoleta_y_renovacion_unica(): void
    {
        $v = $this->confirmed();
        $data = $v + ['fecha_emision' => '2026-11-01', 'fecha_caducidad' => '2027-11-01'];
        $this->writer->editarFechas($this->req($data), 1);
        $this->validation(fn () => $this->writer->editarFechas($this->req($data), 1));
        DB::table('tb_instrumento')->where('id_Instrumento', 10)->update(['material' => 'BRONCE', 'clase_exactitud' => 'M1']);
        $r = $this->writer->renovar($this->req($this->confirmed()), 1)->getData(true);
        $this->assertSame('BRONCE', DB::table('tb_instrumento')->where('id_certificado', $r['id'])->value('material'));
        $this->assertSame('M1', DB::table('tb_instrumento')->where('id_certificado', $r['id'])->value('clase_exactitud'));
        $this->assertSame(0, DB::table('tb_certificado_archivo')->where('id_certificado', $r['id'])->count());
        $this->validation(fn () => $this->writer->renovar($this->req($this->confirmed()), 1));
        $this->validation(fn () => $this->writer->renovar($this->req($this->confirmed(8)), 8));
    }

    public function test_altas_catalogos_y_contactos_idempotentes(): void
    {
        $p = ['confirmado' => true, 'clave_operacion' => (string) Str::uuid(), 'catalogo' => 'marcas', 'tipo' => 2, 'nombre' => 'Marca Nueva'];
        $a = $this->writer->agregarCatalogo($this->req($p))->getData(true);
        $this->assertSame($a['id'], $this->writer->agregarCatalogo($this->req($p))->getData(true)['id']);
        $p['clave_operacion'] = (string) Str::uuid();
        $this->assertFalse($this->writer->agregarCatalogo($this->req($p))->getData(true)['registrado']);
        $p = ['confirmado' => true, 'clave_operacion' => (string) Str::uuid(), 'nombre' => 'Nuevo', 'correo' => 'n@example.test'];
        $a = $this->writer->registrarContacto($this->req($p), 1)->getData(true);
        $this->assertSame($a['id'], $this->writer->registrarContacto($this->req($p), 1)->getData(true)['id']);
        $this->assertSame(2, $this->writer->contactos($this->req(), 1)->getData(true)['total']);
    }

    private function preparar(): string
    {
        return $this->files->preparar($this->req($this->confirmed() + ['instrumento_id' => 10, 'serie' => 'A']), 1)->getData(true)['url_subida'];
    }

    private function web(string $url, string $method = 'GET', array $post = [], array $files = [], int $uid = 10, array $roles = ['Asesor de Ventas']): Request
    {
        $r = Request::create($url, $method, $post, [], $files);
        $r->setUserResolver(fn () => (object) ['id' => $uid, 'roles' => collect(array_map(fn ($s) => (object) ['name' => $s], $roles))]);
        $r->setLaravelSession($this->app['session']->driver());

        return $r;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('certificado.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
    }

    public function test_enlace_firma_usuario_caducidad_y_serie(): void
    {
        $url = $this->preparar();
        $this->assertSame(200, $this->files->formulario($this->web($url.'&utm_source=chatgpt.com'), 1)->getStatusCode());
        $this->assertStatusError(403, fn () => $this->files->formulario($this->web($url.'&otra=1'), 1));
        $this->assertStatusError(403, fn () => $this->files->formulario($this->web($url, uid: 99), 1));
        $this->assertStatusError(403, fn () => $this->files->formulario($this->web($url, roles: ['Servicio Tecnico']), 1));
        DB::table('mcp_certificacion_subidas')->update(['expires_at' => now()->subMinute()]);
        $this->assertStatusError(410, fn () => $this->files->formulario($this->web($url), 1));
        $this->validation(fn () => $this->files->preparar($this->req($this->confirmed() + ['instrumento_id' => 10, 'serie' => 'FALSA']), 1));
        $this->assertStatusError(404, fn () => $this->files->preparar($this->req($this->confirmed() + ['instrumento_id' => 11, 'serie' => 'A']), 1));
    }

    public function test_subida_real_una_vez_y_rechazo_de_no_pdf(): void
    {
        $url = $this->preparar();
        $this->validation(fn () => $this->files->guardar($this->web($url, 'POST', ['confirmado' => true], ['files' => [UploadedFile::fake()->createWithContent('mal.pdf', 'not pdf')]]), 1));
        $this->assertNull(DB::table('mcp_certificacion_subidas')->value('used_at'));
        $r = $this->files->guardar($this->web($url, 'POST', ['confirmado' => true], ['files' => [$this->pdf()]]), 1);
        $this->assertSame(200, $r->getStatusCode());
        $f = DB::table('tb_certificado_archivo')->where('id_instrumento', 10)->first();
        Storage::disk('certificados')->assertExists($f->ruta);
        $this->assertSame('A', $f->serie);
        $this->assertSame('Recibido', DB::table('tb_certificado')->where('idCertificado', 1)->value('estadoRenovacion'));
        $this->assertStatusError(410, fn () => $this->files->guardar($this->web($url, 'POST', ['confirmado' => true], ['files' => [$this->pdf()]]), 1));
        $this->validation(fn () => $this->preparar());
    }

    public function test_cambio_de_version_invalida_ticket_y_archivo_no_cruza_solicitudes(): void
    {
        $url = $this->preparar();
        DB::table('tb_certificado')->where('idCertificado', 1)->update(['direccionCertificado' => 'Nueva']);
        $this->validation(fn () => $this->files->guardar($this->web($url, 'POST', ['confirmado' => true], ['files' => [$this->pdf()]]), 1));
        $this->assertSame([], Storage::disk('certificados')->allFiles());
        $this->assertStatusError(404, fn () => $this->files->enlaceArchivo($this->req(), 2, 1));
        $link = $this->files->enlaceArchivo($this->req(['modo' => 'descargar']), 1, 1)->getData(true)['url'];
        $this->assertStatusError(404, fn () => $this->files->abrirArchivo($this->web($link), 1, 1));
        Storage::disk('certificados')->put('seguro.pdf', "%PDF-1.4\n%%EOF");
        DB::table('tb_certificado_archivo')->where('id_archivo', 1)->update(['ruta' => 'seguro.pdf']);
        $this->assertSame('application/pdf', $this->files->abrirArchivo($this->web($link), 1, 1)->headers->get('Content-Type'));
        DB::table('tb_certificado_archivo')->where('id_archivo', 1)->update(['ruta' => '../../../../.env']);
        $this->assertStatusError(404, fn () => $this->files->abrirArchivo($this->web($link), 1, 1));
    }

    public function test_rollback_de_archivos_y_ticket_si_falla_la_segunda_insercion(): void
    {
        $url = $this->preparar();
        DB::unprepared("CREATE TRIGGER falla_segundo BEFORE INSERT ON tb_certificado_archivo WHEN NEW.nombre_original = 'segundo.pdf' BEGIN SELECT RAISE(FAIL, 'fallo simulado'); END");
        try {
            $this->files->guardar($this->web($url, 'POST', ['confirmado' => true], ['files' => [$this->pdf(), UploadedFile::fake()->createWithContent('segundo.pdf', "%PDF-1.4\n%%EOF")]]), 1);
            $this->fail('Debio fallar');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fallo simulado', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('certificados')->allFiles());
        $this->assertNull(DB::table('mcp_certificacion_subidas')->value('used_at'));
        $this->assertSame(0, DB::table('tb_certificado_archivo')->where('id_instrumento', 10)->count());
    }

    public function test_expiracion_real_de_firma_y_solicitud_inactiva(): void
    {
        $expired = URL::temporarySignedRoute('mcp.certificaciones.subir', now()->subMinute(), ['id' => 1, 'u' => 10, 'token' => str_repeat('a', 64)], false);
        $this->assertStatusError(403, fn () => $this->files->formulario($this->web('http://localhost'.$expired), 1));
        $url = $this->preparar();
        DB::table('tb_certificado')->where('idCertificado', 1)->update(['estado_soli' => 0]);
        $this->assertStatusError(404, fn () => $this->files->guardar($this->web($url, 'POST', ['confirmado' => true], ['files' => [$this->pdf()]]), 1));
        $this->assertSame([], Storage::disk('certificados')->allFiles());
    }

    public function test_pdf_solicitud_desglosa_series_y_conserva_datos_de_pantalla(): void
    {
        $datos = ['general' => ['fechaSolicitud' => '03/11/2026', 'createdAt' => '2026-11-03'], 'instrumentos' => [
            ['instrumento' => 'Balanza', 'marca' => 'M', 'cap_maxima' => '10 kg', 'escalas' => '1 g', 'series' => [['num_serie' => 'A', 'identificacion' => 'IA'], ['num_serie' => 'B', 'identificacion' => 'IB']]],
            ['instrumento' => 'Termometro', 'modelo' => 'T1', 'desde_medicion' => '0 C', 'hasta_medicion' => '100', 'resolucion' => '1', 'puntos_calibrar' => ['0', '100'], 'series' => []],
            ['instrumento' => 'Pesa', 'cantidad' => 20, 'material' => 'BRONCE', 'series' => []]]];
        $s = new CertificacionSolicitudPdf;
        $v = $s->preparar($datos);
        $this->assertCount(4, $v['instrumentos']);
        $this->assertSame('02 Balanzas, 01 Termómetro, 01 Pesa', $v['cantidad']);
        $this->assertSame('Martes 03 de noviembre de 2026', $v['fecha']);
        $this->assertSame('0 C°', $v['instrumentos'][2]['campos']['Desde (°C)']);
        $this->assertSame('', $v['instrumentos'][2]['campos']['Punto a calibrar 1']);
        $this->assertSame('BRONCE', $v['instrumentos'][3]['campos']['Material']);
    }

    public function test_bloques_de_rutas_entregados_tienen_protecciones(): void
    {
        $base = base_path('entregas/certificaciones-completo-v1.15.0/sistema');
        Route::prefix('api/mcp')->middleware(['auth:sanctum', 'abilities:mcp', 'throttle:60,1'])->group(function () use ($base) {
            eval(file_get_contents($base.'/routes/api-certificaciones-BLOQUE.txt'));
        });
        Route::middleware('web')->group(function () use ($base) {
            eval(file_get_contents($base.'/routes/web-certificaciones-BLOQUE.txt'));
        });
        $routes = Route::getRoutes();
        $route = $routes->match(Request::create('/api/mcp/certificaciones/solicitudes/1/subida', 'POST'));
        $this->assertSame('preparar',$route->getActionMethod());
        $this->assertContains('auth:sanctum',$route->gatherMiddleware());
        $this->assertContains('abilities:mcp',$route->gatherMiddleware());
        $this->assertContains('throttle:10,1',$route->gatherMiddleware());
        $web = $routes->match(Request::create('/mcp/certificaciones/1/subir','POST'));
        $this->assertContains('web',$web->gatherMiddleware());
        $this->assertContains('auth',$web->gatherMiddleware());
        $this->assertSame('guardar',$web->getActionMethod());
        $this->assertStatusError(404,fn () => $routes->match(Request::create('/api/mcp/certificaciones/solicitudes/no-numerico','GET')));
    }
}
