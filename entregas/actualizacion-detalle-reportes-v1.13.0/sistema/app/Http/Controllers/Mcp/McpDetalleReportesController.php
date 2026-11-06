<?php
namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\ReporteAccionesMcpService as Acciones;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class McpDetalleReportesController extends Controller
{
    private function audit(Request $r,string $accion,int $id,array $extra=[]): void
    { Log::info('mcp.detalle_reportes',['user_id'=>$r->user()->id,'accion'=>$accion,'reporte_id'=>$id]+$extra); }

    public function acciones(Request $request,int $id,Acciones $service)
    {
        $ctx=$service->contexto($request,$id);
        $d=$request->validate(['instrumento_id'=>'nullable|integer|min:1']);
        $cierre=$service->motivoCierre($request,$ctx);
        $out=['reporte_id'=>$id,'tarea_id'=>$ctx['reporte']->tarea_id,'estado'=>$ctx['reporte']->estado_global,
            'version_actual'=>$ctx['version_actual'],'acciones_permitidas'=>$cierre===null?['finalizar_reporte']:[],
            'motivo_restriccion_cierre'=>$cierre,
            'nota'=>'Para editar consulta esta herramienta con instrumento_id. Las liberaciones, borrados y adjuntos se gestionan en el sistema.'];
        if (isset($d['instrumento_id'])) {
            $i=$ctx['instrumentos']->firstWhere('id',(int)$d['instrumento_id']);
            abort_unless($i,404,'Instrumento no encontrado en el reporte.');
            $di=$ctx['informes']->firstWhere('reporte_instrumento_id',$i->id);
            $re=$ctx['resultados']->firstWhere('reporte_instrumento_id',$i->id);
            $out['instrumento_id']=$i->id;
            $out['datos_instrumento']=array_intersect_key((array)$i,array_flip(Acciones::FICHA));
            $out['informe']=$di?array_intersect_key((array)$di,array_flip(Acciones::INFORME)):null;
            $out['resultado']=$re?array_intersect_key((array)$re,array_flip(Acciones::RESULTADO)):null;
            $out['acciones_instrumento']=[]; $out['restricciones']=[];
            foreach (['instrumento','informe','resultado'] as $tipo) {
                $motivo=$service->motivoEdicion($request,$ctx,$i,$tipo);
                if ($motivo===null) { $out['acciones_instrumento'][]='editar_'.$tipo; }
                else { $out['restricciones'][$tipo]=$motivo; }
            }
            $out['textos_recortados']=false;
            foreach (['datos_instrumento','informe','resultado'] as $grupo) {
                foreach ($out[$grupo] ?? [] as $campo=>$texto) {
                    if (is_string($texto) && mb_strlen($texto)>10000) {
                        $out[$grupo][$campo]=mb_substr($texto,0,10000).'…'; $out['textos_recortados']=true;
                    }
                }
            }
        }
        $this->audit($request,'acciones',$id);
        return response()->json($out);
    }

    public function instrumento(Request $request,int $id,int $instrumentoId,Acciones $s) { return $this->editar($request,$id,$instrumentoId,'instrumento',$s); }
    public function informe(Request $request,int $id,int $instrumentoId,Acciones $s) { return $this->editar($request,$id,$instrumentoId,'informe',$s); }
    public function resultado(Request $request,int $id,int $instrumentoId,Acciones $s) { return $this->editar($request,$id,$instrumentoId,'resultado',$s); }

    private function validar(Request $r,array $rules): array
    {
        $d=$r->validate(['confirmado'=>'required|accepted','version_actual'=>'required|string|size:64']+$rules);
        if ($r->input('confirmado')!==true || array_diff(array_keys($r->all()),array_merge(['confirmado','version_actual'],array_filter(array_keys($rules),fn($k)=>!str_contains($k,'.'))))) {
            throw ValidationException::withMessages(['confirmado'=>'Envía solo los campos permitidos y confirmado=true tras la confirmación del usuario.']);
        }
        return $d;
    }

    private function editar(Request $request,int $id,int $instrumentoId,string $tipo,Acciones $s)
    {
        $s->contexto($request,$id);
        $campos=match($tipo){'instrumento'=>Acciones::FICHA,'informe'=>Acciones::INFORME,default=>Acciones::RESULTADO};
        $rules=['cambios'=>'required|array:'.implode(',',$campos).'|min:1'];
        foreach($campos as $campo) {
            $max=$tipo==='instrumento'?($campo==='material'?150:($campo==='codigo_int'?50:100)):($campo==='n_cotizacion'?50:10000);
            $rules['cambios.'.$campo]='sometimes|nullable|string|max:'.$max;
        }
        if ($tipo==='instrumento') { $rules['cambios.tipo_instrumento']='sometimes|required|string|max:100'; }
        if ($tipo==='resultado') { $rules['cambios.trabajos_realizados']='sometimes|required|string|max:10000'; }
        $d=$this->validar($request,$rules);
        return DB::transaction(function() use($request,$id,$instrumentoId,$tipo,$s,$d) {
            $ctx=$s->contexto($request,$id,true); $s->comprobarVersion($ctx,$d['version_actual']);
            $i=$ctx['instrumentos']->firstWhere('id',$instrumentoId); abort_unless($i,404,'Instrumento no encontrado.');
            if ($motivo=$s->motivoEdicion($request,$ctx,$i,$tipo)) { throw ValidationException::withMessages(['instrumento'=>$motivo]); }
            $cambios=$d['cambios'];
            if ($tipo==='instrumento') {
                // Como updateInstrumento: resolver el vínculo histórico antes de cambiar la identidad.
                if (!$i->instrumento_tarea_id && $ctx['reporte']->tarea_id) {
                    $q=DB::table('tb_instrumento_tarea')->where('tarea_id',$ctx['reporte']->tarea_id);
                    foreach(['tipo_instrumento','marca','modelo','serie'] as $campo) {
                        $i->$campo===null?$q->whereNull($campo):$q->whereRaw('TRIM('.$campo.') = ?',[trim($i->$campo)]);
                    }
                    $matches=$q->orderBy('id')->lockForUpdate()->limit(2)->get(['id']);
                    if($matches->count()>1) { throw ValidationException::withMessages(['instrumento'=>'Vínculo ambiguo: resolver en el sistema antes de editar.']); }
                    if($matches->count()===1) { $cambios['instrumento_tarea_id']=$matches->first()->id; }
                }
                $tabla='reportes_instrumentos'; $registro=$i;
            } else {
                $registro=$ctx[$tipo==='informe'?'informes':'resultados']->firstWhere('reporte_instrumento_id',$i->id);
                $tabla=$tipo==='informe'?'diagnosticos':'informes_finales';
                if (!$registro->tecnico_id) { $cambios['tecnico_id']=$request->user()->id; }
                if (!$registro->tecnico_responsable) { $cambios['tecnico_responsable']=$request->user()->name; }
                if ($tipo==='resultado' && trim((string)($cambios['trabajos_realizados']??$registro->trabajos_realizados))==='') {
                    throw ValidationException::withMessages(['trabajos_realizados'=>'Los trabajos realizados son obligatorios.']);
                }
            }
            DB::table($tabla)->where('id',$registro->id)->update($cambios+['updated_at'=>now()]);
            $this->audit($request,'editar_'.$tipo,$id,['instrumento_id'=>$instrumentoId,'campos'=>array_keys($d['cambios'])]);
            return response()->json(['actualizado'=>true,'id'=>$registro->id,'reporte_id'=>$id,'instrumento_id'=>$instrumentoId,
                'nota'=>'Se conservaron campos omitidos, fotos y estados; consulta de nuevo antes de otro cambio.']);
        },3);
    }

    public function finalizar(Request $request,int $id,Acciones $s)
    {
        $s->contexto($request,$id); $d=$this->validar($request,[]);
        return DB::transaction(function() use($request,$id,$s,$d) {
            $ctx=$s->contexto($request,$id,true); $s->comprobarVersion($ctx,$d['version_actual']);
            if($motivo=$s->motivoCierre($request,$ctx)) { throw ValidationException::withMessages(['reporte'=>$motivo]); }
            DB::table('reportes')->where('id',$id)->update(['estado_global'=>'cerrado','updated_at'=>now()]);
            if($ctx['reporte']->tarea_id) { DB::table('tb_tareas_tecnicos')->where('id',$ctx['reporte']->tarea_id)->update(['estado'=>'completada','updated_at'=>now()]); }
            $this->audit($request,'finalizar',$id);
            return response()->json(['actualizado'=>true,'id'=>$id,'estado_anterior'=>'abierto','estado_nuevo'=>'cerrado',
                'tarea_id'=>$ctx['reporte']->tarea_id,'nota'=>'La tarea asociada queda completada. El pendiente/servicio no se finaliza automáticamente.']);
        },3);
    }
}
