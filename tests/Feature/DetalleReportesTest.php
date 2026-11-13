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

class DetalleReportesTest extends TestCase
{
    private McpHistorialReportesController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(McpHistorialReportesController::class)) {
            require_once (getenv('MCP_CENTRAL_PATH') ?: dirname(base_path()).'/Sistema_Balinsa_23_10').'/app/Http/Controllers/Mcp/McpHistorialReportesController.php';
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
        $stage=getenv('MCP_CENTRAL_PATH') ?: dirname(base_path()).'/Sistema_Balinsa_23_10';
        if (!is_file($stage.'/app/Services/ReporteAccionesMcpService.php')) {
            $this->markTestSkipped('Define MCP_CENTRAL_PATH para probar el sistema central actual.');
        }
        foreach(['app/Services/ReporteAccionesMcpService.php','app/Services/ReporteTecnicoDatosPdf.php','app/Services/ReporteTecnicoPdfService.php',
            'app/Services/InstrumentosServicioService.php','app/Models/ReporteInstrumento.php','app/Models/InstrumentoTarea.php',
            'app/Http/Controllers/Mcp/McpDetalleReportesController.php','app/Http/Controllers/Mcp/McpReporteTecnicoPdfController.php'] as $file) { require_once $stage.'/'.$file; }
        config(['mcp.detalle_reportes_roles_edicion'=>['Servicio Tecnico','Operaciones','Administracion'],
            'mcp.detalle_reportes_roles_supervisor'=>['Operaciones','Administracion'],
            'mcp.detalle_reportes_roles_finalizar'=>['Operaciones'],
            'mcp.reporte_tecnico_pdf_roles'=>['Servicio Tecnico','Operaciones'],
            'app.url'=>'https://sistema.test','app.key'=>'base64:'.base64_encode(str_repeat('a',32))]);
        Schema::table('reportes',function(Blueprint $t){$t->string('tecnico_responsable')->nullable();});
        Schema::table('reportes_instrumentos',function(Blueprint $t){$t->integer('instrumento_tarea_id')->nullable();$t->timestamp('updated_at')->nullable();});
        foreach(['diagnosticos','informes_finales'] as $tabla) {
            Schema::table($tabla,function(Blueprint $t){
                $t->integer('tecnico_id')->nullable();$t->text('repuestos_sugeridos')->nullable();$t->text('repuestos_instalados')->nullable();
                foreach(['foto1','foto2','foto3'] as $c){$t->string($c)->nullable();}$t->timestamp('updated_at')->nullable();
            });
        }
        Schema::create('tb_instrumento_tarea',function(Blueprint $t){$t->id();$t->integer('tarea_id');
            foreach(\App\Services\InstrumentosServicioService::CAMPOS as $c){$t->string($c)->nullable();}
            $t->boolean('agregado_en_campo')->default(false);$t->timestamps();});
        Schema::table('reportes_instrumentos',function(Blueprint $t){$t->string('puntos_calibracion')->nullable();});
        DB::table('reportes')->where('id',101)->update(['estado_global'=>'abierto','created_at'=>'2026-10-29 10:00:00']);
        DB::table('reportes_instrumentos')->where('id',21)->update(['tipo_instrumento'=>'Balanza','tipo_servicio'=>'Mantenimiento','orden_pdf'=>1]);
        DB::table('diagnosticos')->where('id',31)->update(['tecnico_id'=>10,'tecnico_responsable'=>'Técnico Uno','estado_diagnostico'=>'pendiente','foto1'=>'original.jpg']);
        DB::table('informes_finales')->where('id',41)->update(['tecnico_id'=>10,'tecnico_responsable'=>'Técnico Uno','foto3'=>'cert.jpg']);
        \Illuminate\Support\Facades\Route::get('mcp/reportes/{id}/instrumentos/{instrumentoId}/pdf',fn()=>null)->name('mcp.reporte_tecnico.pdf');
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
    }


    private function req(array $d=[],string $rol='Servicio Tecnico',int $id=10): Request
    {
        $u=new class($rol,$id){public $roles;public $id;public string $name='Técnico Actual';
            public function __construct($rol,$id){$this->roles=collect([(object)['name'=>$rol]]);$this->id=$id;}
            public function can($permiso){return false;}};
        $r=Request::create('/','POST',$d);$r->setUserResolver(fn()=>$u);return $r;
    }
    private function service(){return new \App\Services\ReporteAccionesMcpService;}

    public function test_consultas_comparten_base_y_version_detecta_edicion_de_hoja(): void
    {
        DB::table('tb_instrumento_tarea')->insert(['id'=>51,'tarea_id'=>11,'tipo_instrumento'=>'Balanza','marca'=>'Base','agregado_en_campo'=>1]);
        DB::table('reportes_instrumentos')->where('id',21)->update(['instrumento_tarea_id'=>51,'marca'=>'Antigua']);
        $p=$this->payload(['marca'=>'Cambio']);
        DB::table('tb_instrumento_tarea')->where('id',51)->update(['marca'=>'Actual']);
        $out=$this->ctl()->acciones($this->req(['instrumento_id'=>21]),101,$this->service())->getData(true);
        $this->assertSame('Actual',$out['datos_instrumento']['marca']);
        $this->assertSame(51,$out['instrumento_tarea_id']);$this->assertTrue($out['agregado_en_campo']);
        $hist=$this->controller->instrumentos($this->req(['instrumento_id'=>21]),101)->getData(true);
        $this->assertSame('Actual',$hist['data'][0]['instrumento']['marca']);
        try { $this->ctl()->instrumento($this->req($p),101,21,$this->service()); $this->fail(); }
        catch (ValidationException $e) { $this->assertArrayHasKey('version_actual',$e->errors()); }
        $p['version_actual']=$out['version_actual'];
        $this->ctl()->instrumento($this->req($p),101,21,$this->service());
        $this->assertSame('Cambio',DB::table('tb_instrumento_tarea')->where('id',51)->value('marca'));
        $this->assertSame('Cambio',DB::table('reportes_instrumentos')->where('id',21)->value('marca'));
    }
    private function ctl(){return new \App\Http\Controllers\Mcp\McpDetalleReportesController;}
    private function payload(array $changes=[],string $rol='Servicio Tecnico'): array
    {
        $ctx=$this->service()->contexto($this->req([],$rol),101);
        return ['confirmado'=>true,'version_actual'=>$ctx['version_actual'],'cambios'=>$changes];
    }
    public function test_edicion_propia_conserva_autoria_fotos_y_omitidos(): void
    {
        DB::table('diagnosticos')->where('id',31)->update(['trabajos_sugeridos'=>'Histórico','repuestos_sugeridos'=>'Original']);
        $p=$this->payload(['fallas_detectadas'=>'Informe actualizado']);
        $this->ctl()->informe($this->req($p),101,21,$this->service());
        $d=DB::table('diagnosticos')->where('id',31)->first();
        $this->assertSame('Informe actualizado',$d->fallas_detectadas);$this->assertSame('original.jpg',$d->foto1);
        $this->assertSame('Histórico',$d->trabajos_sugeridos);
        $this->assertSame('Original',$d->repuestos_sugeridos);
        $this->assertSame('Técnico Uno',$d->tecnico_responsable);
        $this->expectException(ValidationException::class);
        $this->ctl()->informe($this->req($p),101,21,$this->service());
    }
    public function test_informe_ajeno_y_liberado_bloquean_incluso_gerencia(): void
    {
        DB::table('diagnosticos')->where('id',31)->update(['tecnico_id'=>99]);
        try{$this->ctl()->informe($this->req($this->payload(['fallas_detectadas'=>'Nuevo'])),101,21,$this->service());$this->fail();}
        catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        $this->ctl()->informe($this->req($this->payload(['fallas_detectadas'=>'Supervisor'],'Operaciones'),'Operaciones'),101,21,$this->service());
        DB::table('diagnosticos')->where('id',31)->update(['estado_diagnostico'=>'finalizado']);
        $this->expectException(ValidationException::class);
        $this->ctl()->informe($this->req($this->payload(['fallas_detectadas'=>'No'],'Gerencia'),'Gerencia'),101,21,$this->service());
    }
    public function test_informe_rechaza_campos_retirados_y_textos_vacios(): void
    {
        foreach ([['fallas_detectadas'=>''], ['fallas_detectadas'=>'   '], ['fallas_detectadas'=>null], ['trabajos_sugeridos'=>'No'], ['repuestos_sugeridos'=>'No']] as $c) {
            try { $this->ctl()->informe($this->req($this->payload($c)),101,21,$this->service()); $this->fail('Debió rechazar los cambios'); }
            catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        $this->assertSame('Falla', DB::table('diagnosticos')->where('id',31)->value('fallas_detectadas'));
        $out=$this->ctl()->acciones($this->req(['instrumento_id'=>21]),101,$this->service())->getData(true);
        $this->assertSame(['fallas_detectadas'=>'Falla'], $out['informe']);
        $this->assertSame(['trabajos_realizados'=>'Reparado'], $out['resultado']);
    }
    public function test_resultado_obligatorio_campos_no_permitidos_y_confirmacion(): void
    {
        foreach([['trabajos_realizados'=>'   '],['trabajos_realizados'=>null],['observaciones_finales'=>'No'],['n_cotizacion'=>'No'],['repuestos_instalados'=>'No'],['foto3'=>'nuevo.jpg'],['tecnico_id'=>999]] as $c){
            try{$this->ctl()->resultado($this->req($this->payload($c)),101,21,$this->service());$this->fail();}
            catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        }
        $p=$this->payload(['trabajos_realizados'=>'Comprobado']);$p['confirmado']=false;
        try{$this->ctl()->resultado($this->req($p),101,21,$this->service());$this->fail();}
        catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        DB::table('informes_finales')->where('id',41)->update(['observaciones_finales'=>'Histórico','n_cotizacion'=>'COT-001','repuestos_instalados'=>'Original']);
        $this->ctl()->resultado($this->req($this->payload(['trabajos_realizados'=>'Comprobado'])),101,21,$this->service());
        $d=DB::table('informes_finales')->where('id',41)->first();
        $this->assertSame('Comprobado',$d->trabajos_realizados);
        $this->assertSame('Histórico',$d->observaciones_finales);
        $this->assertSame('COT-001',$d->n_cotizacion);
        $this->assertSame('Original',$d->repuestos_instalados);
        $this->assertSame('cert.jpg',DB::table('informes_finales')->where('id',41)->value('foto3'));
    }
    public function test_ficha_tiene_permiso_distinto_y_detecta_vinculo_ambiguo(): void
    {
        try{$this->ctl()->instrumento($this->req($this->payload(['marca'=>'X'],'Operaciones'),'Operaciones',99),101,21,$this->service());$this->fail();}
        catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        foreach([51,52] as $id){DB::table('tb_instrumento_tarea')->insert(['id'=>$id,'tarea_id'=>11,'tipo_instrumento'=>'Balanza']);}
        try{$this->ctl()->instrumento($this->req($this->payload(['marca'=>'X'])),101,21,$this->service());$this->fail();}
        catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        DB::table('tb_instrumento_tarea')->where('id',52)->delete();
        $this->ctl()->instrumento($this->req($this->payload(['marca'=>'X'])),101,21,$this->service());
        $this->assertSame(51,DB::table('reportes_instrumentos')->where('id',21)->value('instrumento_tarea_id'));
        $this->assertSame('X',DB::table('tb_instrumento_tarea')->where('id',51)->value('marca'));
    }
    public function test_cierre_exige_resultados_y_completa_tarea_no_pendiente(): void
    {
        DB::table('informes_finales')->where('id',41)->update(['trabajos_realizados'=>'']);
        $p=$this->payload([],'Operaciones');unset($p['cambios']);
        try{$this->ctl()->finalizar($this->req($p,'Operaciones'),101,$this->service());$this->fail();}
        catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        DB::table('reportes_instrumentos')->where('id',21)->update(['tipo_servicio'=>'Diagnóstico Técnico']);
        $p=$this->payload([],'Operaciones');unset($p['cambios']);
        $this->ctl()->finalizar($this->req($p,'Operaciones'),101,$this->service());
        $this->assertSame('cerrado',DB::table('reportes')->where('id',101)->value('estado_global'));
        $this->assertSame('completada',DB::table('tb_tareas_tecnicos')->where('id',11)->value('estado'));
        $this->assertSame('En Proceso',DB::table('tb_pendientes')->where('id',7)->value('estado'));
        $this->expectException(ValidationException::class);
        $this->ctl()->resultado($this->req($this->payload(['trabajos_realizados'=>'Otro'],'Gerencia'),'Gerencia'),101,21,$this->service());
    }
    public function test_acciones_y_lecturas_ajenas_coinciden(): void
    {
        $r=$this->ctl()->acciones($this->req(['instrumento_id'=>21]),101,$this->service())->getData(true);
        $this->assertContains('editar_informe',$r['acciones_instrumento']);
        $this->assertSame('Falla',$r['informe']['fallas_detectadas']);
        try{$this->ctl()->acciones($this->req(),102,$this->service());$this->fail();}
        catch(HttpException $e){$this->assertSame(404,$e->getStatusCode());}
        try{$this->ctl()->acciones($this->req(['instrumento_id'=>22]),101,$this->service());$this->fail();}
        catch(HttpException $e){$this->assertSame(404,$e->getStatusCode());}
    }
    private function datosPdf(){return new class extends \App\Services\ReporteTecnicoDatosPdf {
        public function cargar(int $id,int $inst): array {
            if($inst!==21){abort(404);}
            return ['reporte'=>['id'=>$id,'created_at'=>'2026-10-29','cliente_nombre'=>'Empresa'],
                'instrumento'=>['id'=>$inst,'orden_pdf'=>1,'tipo_instrumento'=>'Balanza','fallas_detectadas'=>'Falla','trabajos_realizados'=>'Reparado']];
        }
    };}
    private function pdfCtl($user){$c=new class extends \App\Http\Controllers\Mcp\McpReporteTecnicoPdfController {
        public $user;protected function usuario(int $id){return $this->user;}
    };$c->user=$user;return $c;}
    public function test_firma_relativa_tracking_modos_manipulaciones_y_revocacion(): void
    {
        $user=$this->req()->user();$c=$this->pdfCtl($user);$datos=$this->datosPdf();
        $pdf=new class extends \App\Services\ReporteTecnicoPdfService {public function generar(array $datos): array{return ['archivo'=>'Reporte_prueba.pdf','contenido'=>'%PDF-1.4 prueba'];}};
        foreach(['ver'=>'inline','descargar'=>'attachment'] as $modo=>$disposition){
            $r=$c->pdf($this->req(['modo'=>$modo,'u'=>999]),101,21,$datos,$pdf)->getData(true);
            $this->assertStringContainsString('u=10',$r['url']);$this->assertSame(10,$r['vence_en_minutos']);
            $req=Request::create(str_replace('sistema.test','proxy.test',$r['url']).'&utm_source=chatgpt.com');
            $out=$c->abrir($req,101,21,$datos,$pdf);
            $this->assertStringContainsString($disposition,$out->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store',$out->headers->get('Cache-Control'));
            foreach([str_replace('u=10','u=99',$r['url']),$r['url'].'&otro=1',str_replace('/21/','/22/',$r['url'])] as $url){
                try{$c->abrir(Request::create($url),101,21,$datos,$pdf);$this->fail();}
                catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
            }
        }
        $this->travel(11)->minutes();
        try{$c->abrir(Request::create($r['url']),101,21,$datos,$pdf);$this->fail();}
        catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->travelBack();$user->roles=collect([(object)['name'=>'Area de Almacen']]);
        try{$c->abrir(Request::create($r['url']),101,21,$datos,$pdf);$this->fail();}
        catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
    }
    public function test_pdf_numeracion_textos_y_tipos_sin_cambios_en_bd(): void
    {
        $pdf=new \App\Services\ReporteTecnicoPdfService;
        $data=$this->datosPdf()->cargar(101,21);$data['instrumento']+=['marca'=>'0','d_tecnico'=>'Ana','inf_tecnico'=>'Ana','d_fecha'=>'2026-10-29','inf_fecha'=>'2026-10-29'];
        $r=$pdf->calcular($data);
        $this->assertSame('2026-000101-001',$r['numero']);$this->assertSame('Ana',$r['tecnicos']);
        $this->assertSame('Jueves, 29 de octubre de 2026',$r['fechas']);$this->assertSame('0',$r['filas_equipo'][0][1]);
        $data['instrumento']['tipo_instrumento']='Manómetro';$this->assertNull($pdf->calcular($data)['titulo_equipo']);
        $data['instrumento']['orden_pdf']=null;$this->expectException(\InvalidArgumentException::class);$pdf->calcular($data);
    }
}
