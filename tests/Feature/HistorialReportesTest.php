<?php

namespace Tests\Feature;

use App\Http\Controllers\Mcp\McpHistorialReportesController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HistorialReportesTest extends TestCase
{
    private McpHistorialReportesController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(McpHistorialReportesController::class)) {
            require_once base_path('entregas/actualizacion-historial-reportes-v1.12.0/sistema/app/Http/Controllers/Mcp/McpHistorialReportesController.php');
        }
        config(['logging.default'=>'historial_test', 'logging.channels.historial_test'=>[
            'driver'=>'monolog', 'handler'=>\Monolog\Handler\NullHandler::class],
            'database.default'=>'historial_test', 'database.connections.historial_test'=>[
            'driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true],
            'mcp.roles_acceso_total'=>['Gerencia','Area de TI'],
            'mcp.historial_reportes_roles'=>['Servicio Tecnico','Operaciones','Asesor de Ventas'],
            'mcp.historial_reportes_roles_ve_todo'=>['Operaciones','Asesor de Ventas'],
            'mcp.historial_reportes_roles_finalizar'=>['Operaciones'],
            'mcp.historial_reportes_max_dias'=>93]);
        DB::purge('historial_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('razonSocial'); });
        Schema::create('tb_pendientes', function (Blueprint $t) {
            $t->id(); $t->integer('cliente_id'); $t->string('estado'); $t->string('tipo_servicio')->nullable(); $t->timestamps();
        });
        Schema::create('tb_tareas_tecnicos', function (Blueprint $t) {
            $t->id(); $t->integer('pendiente_id')->nullable(); $t->string('estado'); $t->timestamps();
        });
        Schema::create('tb_tecnicos_existentes', function (Blueprint $t) { $t->id(); $t->integer('user_id'); });
        Schema::create('tb_detalles_tarea', function (Blueprint $t) { $t->integer('id_tarea'); $t->integer('id_tecnico'); });
        Schema::create('reportes', function (Blueprint $t) {
            $t->id(); $t->integer('cliente_id'); $t->integer('tarea_id')->nullable();
            $t->string('n_servicio'); $t->string('fecha'); $t->string('estado_global'); $t->timestamps();
        });
        Schema::create('reportes_instrumentos', function (Blueprint $t) {
            $t->id(); $t->integer('reporte_id'); $t->integer('orden_pdf')->nullable();
            foreach (['tipo_instrumento','tipo_servicio','marca','modelo','serie','codigo_int','capacidad_rango',
                'resolucion_division','clase_tipo_metrologico','material'] as $c) { $t->string($c)->nullable(); }
        });
        foreach (['diagnosticos','informes_finales'] as $tabla) {
            Schema::create($tabla, function (Blueprint $t) {
                $t->id(); $t->integer('reporte_instrumento_id');
                foreach (['fallas_detectadas','trabajos_sugeridos','estado_diagnostico','trabajos_realizados',
                    'observaciones_finales','n_cotizacion','estado_informe','fecha','tecnico_responsable'] as $c) { $t->text($c)->nullable(); }
                $t->string('secreto')->nullable();
            });
        }
        DB::table('clientes')->insert(['id'=>1,'razonSocial'=>'Empresa Uno']);
        DB::table('tb_pendientes')->insert(['id'=>7,'cliente_id'=>1,'estado'=>'En Proceso','tipo_servicio'=>'Mantenimiento']);
        DB::table('tb_tareas_tecnicos')->insert([['id'=>11,'pendiente_id'=>7,'estado'=>'completada'],['id'=>12,'pendiente_id'=>7,'estado'=>'pendiente']]);
        DB::table('reportes')->insert([
            ['id'=>101,'cliente_id'=>1,'tarea_id'=>11,'n_servicio'=>'RPT-000101','fecha'=>'2026-10-29','estado_global'=>'cerrado'],
            ['id'=>102,'cliente_id'=>1,'tarea_id'=>12,'n_servicio'=>'RPT-000102','fecha'=>'2026-08-01','estado_global'=>'abierto']]);
        DB::table('tb_tecnicos_existentes')->insert(['id'=>1,'user_id'=>10]);
        DB::table('tb_detalles_tarea')->insert(['id_tarea'=>11,'id_tecnico'=>1]);
        DB::table('reportes_instrumentos')->insert([['id'=>21,'reporte_id'=>101],['id'=>22,'reporte_id'=>102]]);
        DB::table('diagnosticos')->insert(['id'=>31,'reporte_instrumento_id'=>21,'fallas_detectadas'=>'Falla','secreto'=>'NO MOSTRAR']);
        DB::table('informes_finales')->insert(['id'=>41,'reporte_instrumento_id'=>21,'trabajos_realizados'=>'Reparado']);
        $this->controller = new McpHistorialReportesController;
    }

    private function req(array $data=[], string $rol='Operaciones', bool $permiso=false): Request
    {
        $user = new class($rol,$permiso) {
            public int $id=10; public $roles;
            public function __construct(string $rol, private bool $permiso) { $this->roles=collect([(object)['name'=>$rol]]); }
            public function can($permission): bool { return $this->permiso; }
        };
        $r=Request::create('/api/mcp/servicio-tecnico/historial-reportes','GET',$data);
        $r->setUserResolver(fn () => $user); return $r;
    }

    private function rango(array $extra=[]): array { return $extra+['desde'=>'2026-08-01','hasta'=>'2026-10-29']; }
    private function version(): string { return $this->controller->verServicio($this->req(),7)->getData(true)['version_actual']; }

    public function test_paginacion_filtros_numeros_y_resumen_coinciden(): void
    {
        $r=$this->controller->buscar($this->req($this->rango(['limite'=>1])))->getData(true);
        $this->assertSame(2,$r['total']); $this->assertTrue($r['hay_mas']);
        $this->assertSame(101,$r['data'][0]['reporte_id']); $this->assertSame(11,$r['data'][0]['tarea_id']);
        $r=$this->controller->buscar($this->req($this->rango(['limite'=>1,'pagina'=>2])))->getData(true);
        $this->assertSame(102,$r['data'][0]['reporte_id']); $this->assertFalse($r['hay_mas']);
        $d=$this->rango(['numero_reporte'=>'RPT-000101','numero_pendiente'=>'00007','cliente'=>'Empresa']);
        $r=$this->controller->buscar($this->req($d))->getData(true);
        $sum=$this->controller->resumen($this->req($d))->getData(true)['totales'];
        $this->assertSame(1,$r['total']); $this->assertSame($r['total'],$sum['reportes']);
        $this->assertSame(1,$sum['informes']); $this->assertSame(1,$sum['resultados']);
        $this->assertSame(1,$sum['instrumentos']);
    }

    public function test_roles_y_visibilidad_tambien_en_detalle_y_resumen(): void
    {
        $r=$this->controller->buscar($this->req($this->rango(),'Servicio Tecnico'))->getData(true);
        $this->assertSame(1,$r['total']); $this->assertSame('solo_asignados',$r['alcance']);
        $sum=$this->controller->resumen($this->req($this->rango(),'Servicio Tecnico'))->getData(true);
        $this->assertSame(1,$sum['totales']['reportes']);
        foreach (['ver','instrumentos'] as $metodo) {
            try { $this->controller->$metodo($this->req([],'Servicio Tecnico'),102); $this->fail(); }
            catch (HttpException $e) { $this->assertSame(404,$e->getStatusCode()); }
        }
        foreach (['Area de Almacen','Desconocido'] as $rol) {
            try { $this->controller->buscar($this->req($this->rango(),$rol)); $this->fail(); }
            catch (HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        }
        $this->assertSame(2,$this->controller->buscar($this->req($this->rango(),'Gerencia'))->getData(true)['total']);
    }

    public function test_detalle_cerrado_usa_id_reporte_no_tarea_y_no_escribe(): void
    {
        $r=$this->controller->ver($this->req(),101)->getData(true);
        $this->assertSame('cerrado',$r['estado']); $this->assertSame(7,$r['pendiente_id']);
        DB::table('diagnosticos')->where('id',31)->update(['fallas_detectadas'=>str_repeat('a',1100)]);
        $r=$this->controller->instrumentos($this->req(['instrumento_id'=>21]),101)->getData(true);
        $this->assertTrue($r['data'][0]['textos_recortados']);
        $this->assertArrayNotHasKey('secreto',$r['data'][0]['informe']);
        $this->assertSame('Reparado',$r['data'][0]['resultado']['trabajos_realizados']);
        $this->assertNull(DB::table('reportes_instrumentos')->where('id',21)->value('orden_pdf'));
        try { $this->controller->ver($this->req(),11); $this->fail(); }
        catch (HttpException $e) { $this->assertSame(404,$e->getStatusCode()); }
        try { $this->controller->instrumentos($this->req(['instrumento_id'=>22]),101); $this->fail(); }
        catch (HttpException $e) { $this->assertSame(404,$e->getStatusCode()); }
    }

    public function test_conteos_no_multiplican_instrumentos_con_registros_duplicados(): void
    {
        DB::table('diagnosticos')->insert(['id'=>32,'reporte_instrumento_id'=>21]);
        DB::table('informes_finales')->insert(['id'=>42,'reporte_instrumento_id'=>21]);
        $r=$this->controller->ver($this->req(),101)->getData(true);
        $this->assertSame(1,$r['cantidad_instrumentos']); $this->assertSame(2,$r['cantidad_informes']);
        $this->assertSame(2,$r['cantidad_resultados']);
        $r=$this->controller->instrumentos($this->req(),101)->getData(true);
        $this->assertCount(1,$r['data']); $this->assertNotEmpty($r['data'][0]['advertencias']);
    }

    public function test_validacion_fechas_limites_y_config_vacia(): void
    {
        foreach ([['desde'=>'2026-10-31','hasta'=>'2026-10-01'],['desde'=>'2025-01-01','hasta'=>'2026-10-29'],
            ['desde'=>'2026-02-30'],['limite'=>21],['numero_reporte'=>"1' OR 1=1"],['numero_pendiente'=>'%']] as $d) {
            try { $this->controller->buscar($this->req($d)); $this->fail(); }
            catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        $r=$this->controller->buscar($this->req(['desde'=>null,'hasta'=>null]))->getData(true);
        $this->assertNotNull($r['desde']); $this->assertNotNull($r['hasta']);
        config(['mcp.historial_reportes_roles'=>[]]);
        try { $this->controller->buscar($this->req()); $this->fail(); }
        catch (HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
    }

    public function test_finalizar_revisa_todos_los_reportes_fuera_del_rango(): void
    {
        $r=$this->controller->buscar($this->req(['desde'=>'2026-10-29','hasta'=>'2026-10-29']))->getData(true);
        $this->assertSame(1,$r['total']); $this->assertSame('cerrado',$r['data'][0]['estado']);
        $estado=$this->controller->verServicio($this->req(),7)->getData(true);
        $this->assertSame(2,$estado['cantidad_reportes']); $this->assertSame(1,$estado['reportes_no_cerrados']);
        foreach (['Operaciones','Gerencia'] as $rol) {
            try { $this->controller->finalizarServicio($this->req(['confirmado'=>true,'version_actual'=>$estado['version_actual']],$rol),7); $this->fail(); }
            catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        $this->assertSame('En Proceso',DB::table('tb_pendientes')->value('estado'));
    }

    public function test_finalizar_exige_confirmacion_version_y_permiso_y_solo_cambia_pendiente(): void
    {
        $vieja=$this->version();
        DB::table('reportes')->where('id',102)->update(['estado_global'=>'cerrado']);
        $actual=$this->version();
        foreach ([['confirmado'=>false,'version_actual'=>$actual],['confirmado'=>true,'version_actual'=>$vieja],
            ['confirmado'=>true,'version_actual'=>$actual,'estado'=>'Otro']] as $data) {
            try { $this->controller->finalizarServicio($this->req($data),7); $this->fail(); }
            catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        try { $this->controller->finalizarServicio($this->req(['confirmado'=>true,'version_actual'=>$actual],'Asesor de Ventas'),7); $this->fail(); }
        catch (HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $r=$this->controller->finalizarServicio($this->req(['confirmado'=>true,'version_actual'=>$actual]),7)->getData(true);
        $this->assertSame('Finalizado',$r['estado_nuevo']);
        $this->assertSame('pendiente',DB::table('tb_tareas_tecnicos')->where('id',12)->value('estado'));
        $this->assertSame(2,DB::table('reportes')->count());
        $this->expectException(ValidationException::class);
        $this->controller->finalizarServicio($this->req(['confirmado'=>true,'version_actual'=>$this->version()]),7);
    }

    public function test_permiso_explicito_y_servicio_sin_reportes(): void
    {
        DB::table('reportes')->update(['estado_global'=>'cerrado']);
        $r=$this->controller->finalizarServicio($this->req(['confirmado'=>true,'version_actual'=>$this->version()],'Asesor de Ventas',true),7)->getData(true);
        $this->assertTrue($r['actualizado']);
        DB::table('tb_pendientes')->insert(['id'=>9,'cliente_id'=>1,'estado'=>'Pendiente']);
        $s=$this->controller->verServicio($this->req(),9)->getData(true);
        $this->assertSame([],$s['acciones_permitidas']);
        $this->expectException(ValidationException::class);
        $this->controller->finalizarServicio($this->req(['confirmado'=>true,'version_actual'=>$s['version_actual']]),9);
    }
}
