<?php

namespace Tests\Feature;

use App\Http\Controllers\Mcp\McpCotizacionesController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CotizacionDesdeInformeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $central = getenv('MCP_CENTRAL_PATH') ?: dirname(base_path()).'/Sistema_Balinsa_23_10';
        foreach (['Http/Controllers/Mcp/CotizacionesDesdeInforme', 'Http/Controllers/Mcp/McpCotizacionesController',
            'Services/InstrumentosServicioService', 'Models/InstrumentoTarea'] as $file) {
            require_once $central.'/app/'.$file.'.php';
        }
        if (! class_exists(\App\Models\C_Servicio::class)) {
            class_alias(\Tests\Fixtures\CotizacionServicioModel::class, \App\Models\C_Servicio::class);
        }
        config([
            'database.default' => 'cotizacion_test',
            'database.connections.cotizacion_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'mcp.cotizaciones_roles' => ['Asesor de Ventas', 'Administracion', 'Jefe de Ventas'],
            'mcp.cotizaciones_roles_ve_todo' => ['Administracion', 'Jefe de Ventas'],
            'mcp.cotizaciones_roles_edicion' => ['Asesor de Ventas', 'Jefe de Ventas'],
            'mcp.roles_acceso_total' => ['Gerencia'],
            'mcp.cotizaciones_permiso_ver_todo' => 'ver todo',
            'mcp.cotizacion_condiciones_servicio_generales' => ['Condición general'],
        ]);
        DB::purge('cotizacion_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('razonSocial'); $t->integer('asesor')->nullable();
            foreach (['numeroDocumento', 'direccion', 'fechafactura', 'estado_eliminado'] as $c) { $t->string($c)->nullable(); }
        });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('reportes', function (Blueprint $t) { $t->id(); $t->integer('tarea_id')->nullable(); $t->integer('cliente_id'); $t->string('n_servicio'); $t->string('fecha'); });
        Schema::create('tb_tareas_tecnicos', function (Blueprint $t) { $t->id(); });
        Schema::create('tb_instrumento_tarea', function (Blueprint $t) {
            $t->id(); $t->integer('tarea_id'); $t->boolean('agregado_en_campo')->default(false);
            foreach (\App\Services\InstrumentosServicioService::CAMPOS as $campo) { $t->string($campo)->nullable(); }
            $t->timestamps();
        });
        Schema::create('reportes_instrumentos', function (Blueprint $t) {
            $t->id(); $t->integer('reporte_id'); $t->integer('instrumento_tarea_id')->nullable();
            foreach (['tipo_instrumento', 'marca', 'modelo', 'serie', 'capacidad_rango', 'resolucion_division', 'clase_tipo_metrologico', 'material'] as $c) { $t->string($c)->nullable(); }
        });
        Schema::create('diagnosticos', function (Blueprint $t) { $t->id(); $t->integer('reporte_instrumento_id'); $t->text('fallas_detectadas'); $t->text('trabajos_sugeridos')->nullable(); });
        Schema::create('plantillas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('cservicios', function (Blueprint $t) {
            $t->id(); $t->timestamps();
            foreach (['descripcion', 'estado_servicio', 'estado_cotserv', 'fecha', 'n_informe', 'razonSocial', 'cliente_id', 'moneda', 'fpago', 'garantia', 'plazo_entrega', 'validez', 'condiciones_servicio', 'subtotal', 'igv', 'total', 'id_plantilla', 'id_asesor', 'asesor', 'precio_unitario', 'n_cotizacion'] as $c) { $t->text($c)->nullable(); }
        });
        Schema::create('instru_cotserv_directa', function (Blueprint $t) {
            $t->id(); $t->timestamps();
            $t->integer('reporte_instrumento_id')->nullable(); $t->integer('instrumento_tarea_id')->nullable();
            $t->integer('diagnostico_id')->nullable(); $t->boolean('agregado_en_campo')->default(false); $t->string('codigo_int')->nullable();
            foreach (['id_cservicios', 'instrumento_id', 'instrumento_nombre', 'trabajos', 'precio_unitario', 'marca', 'modelo', 'numero_serie', 'capacidad_rango', 'resolucion_division', 'clase_tipo_metrologico', 'material', 'plataforma', 'clase_exactitud', 'bastago'] as $c) { $t->text($c)->nullable(); }
        });
        DB::table('clientes')->insert(['id' => 1, 'razonSocial' => 'Cliente A', 'asesor' => 7]);
        DB::table('plantillas')->insert(['id' => 1, 'nombre' => 'Mantenimiento']);
        DB::table('reportes')->insert(['id' => 9, 'cliente_id' => 1, 'n_servicio' => 'RPT-9', 'fecha' => now()->toDateString()]);
        DB::table('reportes_instrumentos')->insert(['id' => 21, 'reporte_id' => 9, 'tipo_instrumento' => 'Balanza', 'marca' => 'Marca real', 'modelo' => 'M1', 'serie' => 'S123']);
        DB::table('diagnosticos')->insert(['id' => 30, 'reporte_instrumento_id' => 21, 'fallas_detectadas' => 'Desajuste', 'trabajos_sugeridos' => 'Ajustar']);
    }

    private function request(array $extra = [], string $role = 'Asesor de Ventas'): Request
    {
        $request = Request::create('/api/mcp/ventas/cotizaciones/servicio/desde-informe', 'POST', array_replace([
            'reporte_id' => 9, 'plantilla_id' => 1, 'moneda' => 'Soles', 'forma_pago' => 'Contado',
            'instrumentos' => [['id' => 21, 'precio' => 100, 'trabajos'=>'Ajustar']],
        ], $extra));
        $user = new class {
            public $id = 7;
            public $name = 'Ana';
            public $apellidopat = 'Perez';
            public $apellidomat = '';
            public $roles;
            public function can($permission) { return false; }
        };
        $user->roles = collect([(object) ['name' => $role]]);
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    public function test_creates_linked_quote_with_server_owned_data_and_calculated_totals(): void
    {
        $result = (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request([
            'cliente_id' => 999, 'id_asesor' => 99, 'total' => 1,
            'instrumentos' => [['id' => 21, 'precio' => 123.456, 'marca' => 'Falsa', 'trabajos'=>'Ajustar']],
        ]));
        $this->assertSame(201, $result->getStatusCode());
        $body = $result->getData(true);
        $this->assertSame('desde_informe', $body['origen']);
        $this->assertSame(9, $body['reporte_id']);
        $this->assertEquals(145.68, $body['total']);
        $quote = DB::table('cservicios')->first();
        $this->assertSame('9', $quote->n_informe);
        $this->assertSame('1', $quote->cliente_id);
        $this->assertSame('7', $quote->id_asesor);
        $line = DB::table('instru_cotserv_directa')->first();
        $this->assertSame('Marca real', $line->marca);
        $this->assertSame('S123', $line->numero_serie);
        $this->assertSame('Ajustar', $line->trabajos);
        $this->assertSame('Desajuste', DB::table('diagnosticos')->value('fallas_detectadas'));
    }

    public function test_direct_registration_remains_direct(): void
    {
        $result = (new McpCotizacionesController)->registrarServicio($this->request([
            'cliente_id' => 1, 'instrumentos' => [['tipo' => 'Pesa', 'precio' => 10]],
        ]));
        $this->assertSame('directa', $result->getData(true)['origen']);
        $this->assertSame('DIRECTA', DB::table('cservicios')->value('n_informe'));
    }

    public function test_save_requires_work_for_every_selected_instrument_even_if_source_has_suggestions(): void
    {
        foreach ([['id'=>21,'precio'=>10], ['id'=>21,'precio'=>10,'trabajos'=>null],
            ['id'=>21,'precio'=>10,'trabajos'=>''], ['id'=>21,'precio'=>10,'trabajos'=>" \t\n "]] as $line) {
            try {
                (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request(['instrumentos'=>[$line]]));
                $this->fail('Debe exigir trabajos explícitos al guardar.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('instrumentos.0.trabajos',$e->errors());
            }
        }
        $this->assertSame(0,DB::table('cservicios')->count());
        $this->assertSame(0,DB::table('instru_cotserv_directa')->count());
    }

    public function test_only_selected_instruments_are_quoted_and_omitted_extras_are_untouched(): void
    {
        DB::table('tb_tareas_tecnicos')->insert(['id'=>5]);
        DB::table('reportes')->where('id',9)->update(['tarea_id'=>5]);
        DB::table('tb_instrumento_tarea')->insert(['id'=>102,'tarea_id'=>5,'tipo_instrumento'=>'Balanza','agregado_en_campo'=>1]);
        DB::table('reportes_instrumentos')->insert(['id'=>22,'reporte_id'=>9,'instrumento_tarea_id'=>102,'tipo_instrumento'=>'Balanza']);
        DB::table('diagnosticos')->where('id',30)->update(['trabajos_sugeridos'=>null]);
        $ctl=new McpCotizacionesController;
        $view=$ctl->verInformeCotizable($this->request(),9)->getData(true);
        $this->assertSame(2,$view['instrumentos']['total']);
        $this->assertEmpty($view['instrumentos']['data'][0]['trabajos_sugeridos']);
        $ctl->registrarServicioDesdeInforme($this->request(['instrumentos'=>[
            ['id'=>21,'precio'=>100,'trabajos'=>'  Ajuste confirmado  '],
        ]]));
        $this->assertSame(1,DB::table('instru_cotserv_directa')->count());
        $line=DB::table('instru_cotserv_directa')->first();
        $this->assertSame(21,$line->reporte_instrumento_id);
        $this->assertSame('Ajuste confirmado',$line->trabajos);
        $this->assertEquals(118,DB::table('cservicios')->value('total'));
        $this->assertSame(2,DB::table('reportes_instrumentos')->count());
        $this->assertNull(DB::table('diagnosticos')->where('id',30)->value('trabajos_sugeridos'));
    }

    public function test_current_task_characteristics_and_extras_with_empty_source_work(): void
    {
        DB::table('tb_tareas_tecnicos')->insert(['id'=>5]);
        DB::table('reportes')->where('id',9)->update(['tarea_id'=>5]);
        DB::table('tb_instrumento_tarea')->insert([
            ['id'=>101,'tarea_id'=>5,'tipo_instrumento'=>'Pesas patrón','marca'=>'Actual','modelo'=>'P1','serie'=>'P-101','material'=>'Acero','agregado_en_campo'=>0],
            ['id'=>102,'tarea_id'=>5,'tipo_instrumento'=>'Termómetro','marca'=>'Extra actual','modelo'=>'T1','serie'=>'EX-102','material'=>null,'agregado_en_campo'=>1],
        ]);
        DB::table('reportes_instrumentos')->where('id',21)->update(['instrumento_tarea_id'=>101]);
        DB::table('reportes_instrumentos')->insert(['id'=>22,'reporte_id'=>9,'instrumento_tarea_id'=>102,'tipo_instrumento'=>'Balanza','marca'=>'Copia antigua']);
        DB::table('diagnosticos')->where('id',30)->update(['trabajos_sugeridos'=>null]);
        DB::table('diagnosticos')->insert(['id'=>31,'reporte_instrumento_id'=>22,'fallas_detectadas'=>'Informe extra','trabajos_sugeridos'=>'']);
        $ctl=new McpCotizacionesController;
        $view=$ctl->verInformeCotizable($this->request(),9)->getData(true)['instrumentos']['data'];
        $this->assertSame(21,$view[0]['id']);$this->assertSame(101,$view[0]['instrumento_tarea_id']);
        $this->assertSame('Pesas patrón',$view[0]['tipo_instrumento']);
        $this->assertFalse($view[0]['agregado_en_campo']);
        $this->assertSame(22,$view[1]['reporte_instrumento_id']);
        $this->assertTrue($view[1]['agregado_en_campo']);$this->assertTrue($view[1]['tiene_diagnostico']);
        $this->assertSame('EX-102',$view[1]['serie']);
        $result=$ctl->registrarServicioDesdeInforme($this->request(['instrumentos'=>[
            ['id'=>21,'precio'=>10,'trabajos'=>'Ajuste de pesas'], ['id'=>22,'precio'=>20,'trabajos'=>'Revisión del extra'],
        ]]));
        $this->assertSame(201,$result->getStatusCode());
        $lines=DB::table('instru_cotserv_directa')->orderBy('id')->get();
        $this->assertSame('Acero',$lines[0]->material);
        $this->assertSame('P-101',$lines[0]->numero_serie);
        $this->assertSame('Extra actual',$lines[1]->marca);
        $this->assertSame('EX-102',$lines[1]->numero_serie);
        $this->assertSame('Ajuste de pesas',$lines[0]->trabajos);$this->assertSame('Revisión del extra',$lines[1]->trabajos);
        $this->assertSame('Copia antigua',DB::table('reportes_instrumentos')->where('id',22)->value('marca'));
        $this->assertSame(2,DB::table('tb_instrumento_tarea')->count());
    }

    public function test_extra_without_diagnosis_and_task_id_used_as_report_id_are_rejected(): void
    {
        DB::table('tb_tareas_tecnicos')->insert(['id'=>5]);
        DB::table('reportes')->where('id',9)->update(['tarea_id'=>5]);
        DB::table('tb_instrumento_tarea')->insert(['id'=>102,'tarea_id'=>5,'tipo_instrumento'=>'Balanza','agregado_en_campo'=>1]);
        DB::table('reportes_instrumentos')->insert(['id'=>22,'reporte_id'=>9,'instrumento_tarea_id'=>102,'tipo_instrumento'=>'Balanza']);
        foreach ([22,102] as $id) {
            try { (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request(['instrumentos'=>[['id'=>$id,'precio'=>10, 'trabajos'=>'Ajustar']]])); $this->fail(); }
            catch (ValidationException $e) { $this->assertArrayHasKey('instrumentos',$e->errors()); }
        }
        $this->assertSame(0,DB::table('cservicios')->count());
    }

    public function test_task_link_from_another_task_never_supplies_characteristics(): void
    {
        DB::table('tb_tareas_tecnicos')->insert(['id'=>5]);
        DB::table('reportes')->where('id',9)->update(['tarea_id'=>5]);
        DB::table('tb_instrumento_tarea')->insert(['id'=>102,'tarea_id'=>99,'tipo_instrumento'=>'Pesa','marca'=>'Ajena','agregado_en_campo'=>1]);
        DB::table('reportes_instrumentos')->where('id',21)->update(['instrumento_tarea_id'=>102]);
        $ctl=new McpCotizacionesController;
        $view=$ctl->verInformeCotizable($this->request(),9)->getData(true)['instrumentos']['data'][0];
        $this->assertSame('Marca real',$view['marca']);
        $ctl->registrarServicioDesdeInforme($this->request());
        $this->assertSame('Marca real',DB::table('instru_cotserv_directa')->value('marca'));
    }

    public function test_extra_on_second_page_keeps_report_id_for_selection(): void
    {
        DB::table('tb_tareas_tecnicos')->insert(['id'=>5]);
        DB::table('reportes')->where('id',9)->update(['tarea_id'=>5]);
        for ($id=22;$id<=41;$id++) {
            DB::table('tb_instrumento_tarea')->insert(['id'=>$id+100,'tarea_id'=>5,'tipo_instrumento'=>'Balanza','serie'=>'EX-'.$id,'agregado_en_campo'=>1]);
            DB::table('reportes_instrumentos')->insert(['id'=>$id,'reporte_id'=>9,'instrumento_tarea_id'=>$id+100,'tipo_instrumento'=>'Balanza']);
        }
        DB::table('diagnosticos')->insert(['id'=>31,'reporte_instrumento_id'=>41,'fallas_detectadas'=>'Extra','trabajos_sugeridos'=>null]);
        $ctl=new McpCotizacionesController;
        $view=$ctl->verInformeCotizable($this->request(['pagina'=>2]),9)->getData(true)['instrumentos'];
        $this->assertSame(21,$view['total']);$this->assertCount(1,$view['data']);
        $this->assertSame(41,$view['data'][0]['id']);$this->assertTrue($view['data'][0]['agregado_en_campo']);
        $ctl->registrarServicioDesdeInforme($this->request(['instrumentos'=>[['id'=>41,'precio'=>10, 'trabajos'=>'Ajustar']]]));
        $this->assertSame('EX-41',DB::table('instru_cotserv_directa')->value('numero_serie'));
        $this->assertSame(1,DB::table('instru_cotserv_directa')->count());
        $this->assertSame(41,DB::table('instru_cotserv_directa')->value('reporte_instrumento_id'));
    }

    public function test_rejects_invalid_selection_and_rolls_back(): void
    {
        DB::table('reportes_instrumentos')->insert(['id' => 22, 'reporte_id' => 99, 'tipo_instrumento' => 'Pesa']);
        foreach ([ [['id' => 999, 'precio' => 10, 'trabajos'=>'Ajustar']], [['id' => 22, 'precio' => 10, 'trabajos'=>'Ajustar']], [['id' => 21, 'precio' => -1, 'trabajos'=>'Ajustar']], [['id' => 21, 'precio' => 10, 'trabajos'=>'Ajustar'], ['id' => 21, 'precio' => 10, 'trabajos'=>'Ajustar']] ] as $lines) {
            try {
                (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request(['instrumentos' => $lines]));
                $this->fail('Expected validation error');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        DB::table('diagnosticos')->delete();
        try {
            (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request());
            $this->fail('Expected missing diagnosis error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('instrumentos', $e->errors());
        }
        $this->assertSame(0, DB::table('cservicios')->count());
        $this->assertSame(0, DB::table('instru_cotserv_directa')->count());
    }

    public function test_global_role_can_quote_other_advisors_client_and_override_commercial_work(): void
    {
        DB::table('clientes')->update(['asesor' => 88]);
        $result = (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request([
            'instrumentos' => [['id' => 21, 'precio' => 0, 'titulo_servicio' => 'Servicio especial', 'trabajos' => 'Trabajo confirmado']],
        ], 'Gerencia'));
        $this->assertSame(201, $result->getStatusCode());
        $line = DB::table('instru_cotserv_directa')->first();
        $this->assertSame('Servicio especial', $line->instrumento_nombre);
        $this->assertSame('Trabajo confirmado', $line->trabajos);
        $this->assertEquals(0, $result->getData(true)['total']);
    }

    public function test_permissions_visibility_and_duplicate_pending_quote(): void
    {
        $controller = new McpCotizacionesController;
        try {
            $controller->registrarServicioDesdeInforme($this->request([], 'Administracion'));
            $this->fail();
        } catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        DB::table('clientes')->update(['asesor' => 88]);
        $this->assertSame(0, $controller->buscarInformesCotizables($this->request())->getData(true)['total']);
        try {
            $controller->verInformeCotizable($this->request(), 9);
            $this->fail();
        } catch (HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        DB::table('clientes')->update(['asesor' => 7]);
        $this->assertSame(1, $controller->buscarInformesCotizables($this->request())->getData(true)['total']);
        $this->assertSame('Ajustar', $controller->verInformeCotizable($this->request(), 9)->getData(true)['instrumentos']['data'][0]['trabajos_sugeridos']);
        $controller->registrarServicioDesdeInforme($this->request());
        try {
            $controller->registrarServicioDesdeInforme($this->request());
            $this->fail();
        } catch (ValidationException $e) { $this->assertArrayHasKey('cliente_id', $e->errors()); }
        $this->assertSame(1, DB::table('cservicios')->count());
    }
}
