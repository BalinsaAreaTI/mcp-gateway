<?php

namespace Tests\Feature;

use App\Http\Controllers\Mcp\McpReportesController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

class ReportesSistemaTest extends TestCase
{
    private McpReportesController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        // Solo carga código de referencia; todas las consultas usan SQLite :memory:.
        $central = getenv('MCP_CENTRAL_PATH') ?: dirname(base_path()).'/Sistema_Balinsa_23_10';
        if (! is_file($central.'/app/Models/Reporte.php')) {
            $this->markTestSkipped('Define MCP_CENTRAL_PATH para probar los modelos/servicios reales del sistema.');
        }
        foreach (['Models/Reporte', 'Models/ReporteInstrumento', 'Models/InstrumentoTarea',
            'Models/TareasTecnicos', 'Models/Diagnostico', 'Models/InformeFinal',
            'Services/ReporteInstrumentoService', 'Services/ReporteNumeracionService'] as $file) {
            require_once $central.'/app/'.$file.'.php';
        }
        require_once base_path('entregas/actualizacion-reportes-v1.11.0/sistema/app/Http/Controllers/Mcp/McpReportesController.php');
        config(['database.default' => 'reportes_test', 'database.connections.reportes_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'mcp.roles_acceso_total' => ['Gerencia', 'Area de TI'],
            'mcp.reportes_roles' => ['Servicio Tecnico', 'Operaciones'],
            'mcp.reportes_roles_escritura' => ['Servicio Tecnico', 'Operaciones'], 'mcp.reportes_roles_ve_todo' => []]);
        DB::purge('reportes_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('tb_tareas_tecnicos', function (Blueprint $t) {
            $t->id(); foreach (['cliente','fecha','estado','hora_inicio','hora_termino','nombre'] as $c) { $t->string($c)->nullable(); }
            $t->integer('cliente_id')->nullable(); $t->timestamps();
        });
        Schema::create('tb_tecnicos_existentes', function (Blueprint $t) { $t->id(); $t->integer('user_id'); });
        Schema::create('tb_detalles_tarea', function (Blueprint $t) { $t->integer('id_tarea'); $t->integer('id_tecnico'); });
        foreach (['tb_instrumento_tarea', 'reportes_instrumentos'] as $tabla) {
            Schema::create($tabla, function (Blueprint $t) {
                $t->id(); foreach (['tarea_id','pendiente_id','reporte_id','instrumento_tarea_id','tb_instrumento_pendiente_id','orden_pdf'] as $c) { $t->integer($c)->nullable(); }
                foreach (['tipo_instrumento','tipo_servicio','marca','modelo','serie','codigo_int','capacidad_rango','resolucion_division','clase_tipo_metrologico','material','puntos_calibracion'] as $c) { $t->string($c)->nullable(); }
                $t->timestamps();
            });
        }
        Schema::create('reportes', function (Blueprint $t) {
            $t->id(); $t->integer('tarea_id')->unique(); $t->integer('cliente_id')->nullable();
            $t->integer('ultimo_orden_pdf')->default(0);
            foreach (['n_servicio','tecnico_responsable','fecha','hora_inicio','hora_termino','estado_global','estado_bloqueo'] as $c) { $t->string($c)->nullable(); }
            $t->timestamps();
        });
        foreach (['diagnosticos','informes_finales'] as $tabla) {
            Schema::create($tabla, function (Blueprint $t) {
                $t->id(); $t->integer('reporte_instrumento_id')->unique(); $t->integer('tecnico_id');
                foreach (['tecnico_responsable','fecha','estado_diagnostico','estado_informe','fallas_detectadas','trabajos_sugeridos','trabajos_realizados','observaciones_finales','n_cotizacion'] as $c) { $t->text($c)->nullable(); }
                $t->timestamps();
            });
        }
        DB::table('tb_tareas_tecnicos')->insert(['id'=>1,'cliente'=>'Cliente A','fecha'=>'2026-10-29','estado'=>'pendiente']);
        DB::table('tb_tareas_tecnicos')->insert(['id'=>2,'cliente'=>'Cliente B','fecha'=>'2026-10-29','estado'=>'pendiente']);
        DB::table('tb_tecnicos_existentes')->insert(['id'=>1,'user_id'=>10]);
        DB::table('tb_detalles_tarea')->insert(['id_tarea'=>1,'id_tecnico'=>1]);
        DB::table('tb_instrumento_tarea')->insert(['id'=>1,'tarea_id'=>1,'tipo_instrumento'=>'Balanza','marca'=>'Marca','tipo_servicio'=>'Mantenimiento']);
        DB::table('tb_instrumento_tarea')->insert(['id'=>2,'tarea_id'=>2,'tipo_instrumento'=>'Balanza']);
        $this->controller = new McpReportesController;
    }

    private function req(array $data = [], string $rol = 'Servicio Tecnico', int $id = 10): Request
    {
        $r = Request::create('/api/mcp/servicio-tecnico/reportes/1', 'POST', $data);
        $r->setUserResolver(fn () => (object) ['id' => $id, 'name' => 'Usuario Prueba',
            'roles' => collect([(object) ['name' => $rol]])]);
        return $r;
    }

    private function version(string $rol = 'Servicio Tecnico', int $tarea = 1): string
    {
        return $this->controller->ver($this->req([], $rol), $tarea)->getData(true)['version_actual'];
    }

    private function payload(array $extra = [], string $rol = 'Servicio Tecnico', int $tarea = 1): array
    {
        return $extra + ['instrumento_tarea_id' => $tarea, 'confirmado' => true,
            'version_actual' => $this->version($rol, $tarea), 'fallas_detectadas' => 'No enciende'];
    }

    public function test_rol_sin_acceso_es_403(): void
    {
        try { $this->controller->buscar($this->req([], 'Asesor de Ventas')); $this->fail(); }
        catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
    }

    public function test_lectura_no_crea_snapshot_y_oculta_ajenos(): void
    {
        $this->version(); $this->assertSame(0, DB::table('reportes')->count());
        $this->expectException(ModelNotFoundException::class);
        $this->controller->ver($this->req(), 2);
    }

    public function test_escritura_ajena_no_crea_registros(): void
    {
        $this->expectException(ModelNotFoundException::class);
        try { $this->controller->diagnostico($this->req([]), 2); }
        finally { $this->assertSame(0, DB::table('reportes')->count()); }
    }

    public function test_registro_propio_snapshot_numeracion_y_duplicado(): void
    {
        $p = $this->payload();
        $r = $this->controller->diagnostico($this->req($p), 1);
        $this->assertSame(201, $r->getStatusCode());
        $this->assertTrue($r->getData(true)['progreso']['fase_diagnostico_completa']);
        $this->assertSame(10, DB::table('diagnosticos')->value('tecnico_id'));
        $this->assertSame(1, DB::table('reportes_instrumentos')->value('orden_pdf'));
        $this->assertSame(0, $this->controller->buscar($this->req())->getData(true)['total']);
        $this->expectException(ValidationException::class);
        try { $this->controller->diagnostico($this->req($p), 1); }
        finally { $this->assertSame(1, DB::table('diagnosticos')->count()); }
    }

    public function test_resultado_sin_diagnostico_no_cierra_tarea(): void
    {
        $p = ['instrumento_tarea_id'=>1, 'confirmado'=>true, 'version_actual'=>$this->version(), 'trabajos_realizados'=>'Ajuste'];
        $r = $this->controller->resultado($this->req($p), 1)->getData(true);
        $this->assertTrue($r['progreso']['fase_informe_completa']);
        $this->assertSame('pendiente', DB::table('tb_tareas_tecnicos')->where('id',1)->value('estado'));
        $this->assertSame(0, DB::table('diagnosticos')->count());
        $this->expectException(ValidationException::class);
        $this->controller->diagnostico($this->req($this->payload()), 1);
    }

    public function test_gerencia_ajeno_y_cerrado_siguen_reglas(): void
    {
        $this->controller->diagnostico($this->req($this->payload([], 'Gerencia', 2), 'Gerencia'), 2);
        DB::table('reportes')->where('tarea_id',2)->update(['estado_global'=>'cerrado']);
        $p = ['instrumento_tarea_id'=>2,'confirmado'=>true,'version_actual'=>$this->version('Gerencia',2),'trabajos_realizados'=>'Reparado'];
        $this->expectException(ValidationException::class);
        $this->controller->resultado($this->req($p,'Gerencia'),2);
    }

    public function test_paginacion_filtra_antes_de_contar(): void
    {
        $r = $this->controller->buscar($this->req(['limite'=>1],'Gerencia'))->getData(true);
        $this->assertSame(2,$r['total']); $this->assertTrue($r['hay_mas']);
        $r = $this->controller->buscar($this->req(['limite'=>1,'pagina'=>2],'Gerencia'))->getData(true);
        $this->assertCount(1,$r['data']); $this->assertFalse($r['hay_mas']);
        $this->assertSame(1,$this->controller->buscar($this->req())->getData(true)['total']);
    }

    public function test_extra_conserva_caracteristicas_y_reenvio_no_duplica(): void
    {
        $p = ['instrumento_extra'=>['tipo_instrumento'=>'Termómetro','material'=>'Acero','clase_tipo_metrologico'=>'II','tipo_servicio'=>'Reparación'],
            'confirmado'=>true,'version_actual'=>$this->version(),'trabajos_realizados'=>'Reparado'];
        $this->controller->resultado($this->req($p),1);
        $extra=DB::table('reportes_instrumentos')->whereNull('instrumento_tarea_id')->first();
        $this->assertSame('Reparación',$extra->tipo_servicio); $this->assertSame('II',$extra->clase_tipo_metrologico);
        $this->expectException(ValidationException::class);
        try { $this->controller->resultado($this->req($p),1); }
        finally { $this->assertSame(2,DB::table('reportes_instrumentos')->count()); }
    }

    public function test_referencia_de_otra_tarea_revierte_snapshot(): void
    {
        $this->expectException(ValidationException::class);
        try { $this->controller->diagnostico($this->req($this->payload(['instrumento_tarea_id'=>2])),1); }
        finally { $this->assertSame(0,DB::table('reportes')->count()); }
    }

    public function test_campos_suplantacion_confirmacion_y_limites(): void
    {
        foreach ([['tecnico_id'=>999],['confirmado'=>false],['fallas_detectadas'=>'   '],
            ['fallas_detectadas'=>str_repeat('a',10001)],['reporte_instrumento_id'=>1]] as $cambio) {
            try { $this->controller->diagnostico($this->req($this->payload($cambio)),1); $this->fail('Debió rechazar datos'); }
            catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        $this->assertSame(0,DB::table('reportes')->count());
        $this->expectException(ValidationException::class);
        $this->controller->buscar($this->req(['limite'=>21]));
    }

    public function test_config_vacia_cierra_acceso_y_ampliada_habilita_lectura(): void
    {
        config(['mcp.reportes_roles'=>[]]);
        try { $this->controller->buscar($this->req()); $this->fail(); }
        catch (HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        config(['mcp.reportes_roles'=>['Auditor'],'mcp.reportes_roles_ve_todo'=>['Auditor']]);
        $r=$this->controller->ver($this->req([],'Auditor'),2)->getData(true);
        $this->assertSame([],$r['acciones_permitidas']);
        $this->assertSame([],$r['data'][0]['acciones_permitidas']);
    }

    public function test_caracteristicas_preservan_omitidos_y_bloquean_diagnostico(): void
    {
        $reporte=app(\App\Services\ReporteInstrumentoService::class)->obtenerOCrearReporte(1);
        $inst=DB::table('reportes_instrumentos')->where('reporte_id',$reporte->id)->first();
        $p=['reporte_instrumento_id'=>$inst->id,'confirmado'=>true,'version_actual'=>$this->version(),'cambios'=>['serie'=>'ABC']];
        $this->controller->caracteristicas($this->req($p),1);
        $this->assertSame('Marca',DB::table('reportes_instrumentos')->value('marca'));
        $this->assertSame('ABC',DB::table('reportes_instrumentos')->value('serie'));
        $this->assertNull(DB::table('tb_instrumento_tarea')->where('id',1)->value('serie'));
        $this->controller->diagnostico($this->req($this->payload()),1);
        $p['version_actual']=$this->version();
        $this->expectException(ValidationException::class);
        $this->controller->caracteristicas($this->req($p),1);
    }
}
