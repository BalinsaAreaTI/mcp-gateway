<?php
namespace App\Http\Controllers\Mcp;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReporteTecnicoDatosPdf;
use App\Services\ReporteTecnicoPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderUtils;

class McpReporteTecnicoPdfController extends Controller
{
    private const TRACKING=['utm_source','utm_medium','utm_campaign','utm_term','utm_content','fbclid','gclid'];
    protected function usuario(int $id) { return User::find($id); }
    private function acceso(Request $r,int $id): void
    {
        abort_unless($r->user(),401);
        $roles=$r->user()->roles->pluck('name')->all();
        abort_unless(array_intersect($roles,array_merge((array)config('mcp.roles_acceso_total',[]),(array)config('mcp.reporte_tecnico_pdf_roles',[]))),403,'Tu rol no tiene acceso al PDF de reportes.');
        app(McpHistorialReportesController::class)->ver($r,$id);
    }
    private function auditar(Request $r,int $id,int $instrumentoId,string $accion): void
    { Log::info('mcp.reporte_tecnico_pdf',['user_id'=>$r->user()->id,'reporte_id'=>$id,'instrumento_id'=>$instrumentoId,'accion'=>$accion]); }
    public function pdf(Request $request,int $id,int $instrumentoId,ReporteTecnicoDatosPdf $datos,ReporteTecnicoPdfService $pdf)
    {
        $this->acceso($request,$id);
        $d=$request->validate(['modo'=>['nullable',Rule::in(['ver','descargar'])]]);$modo=$d['modo']??'ver';
        try { $resumen=$pdf->resumen($datos->cargar($id,$instrumentoId)); }
        catch(InvalidArgumentException $e) { abort(422,$e->getMessage()); }
        $base=rtrim((string)config('app.url'),'/');
        abort_unless(filter_var($base,FILTER_VALIDATE_URL)&&parse_url($base,PHP_URL_SCHEME)==='https',422,'Configura APP_URL con el HTTPS real del sistema.');
        $minutos=min(30,max(1,(int)config('mcp.reporte_tecnico_pdf_enlace_minutos',10)));
        $relativa=URL::temporarySignedRoute('mcp.reporte_tecnico.pdf',now()->addMinutes($minutos),
            ['id'=>$id,'instrumentoId'=>$instrumentoId,'u'=>$request->user()->id,'d'=>$modo==='descargar'?1:0],false);
        $this->auditar($request,$id,$instrumentoId,'enlace');
        return response()->json($resumen+['modo'=>$modo,'url'=>$base.'/'.ltrim($relativa,'/'),'vence_en_minutos'=>$minutos,
            'nota'=>'Enlace temporal sin sesión: quien tenga el enlace puede ver el PDF hasta que venza. No lo compartas. Si caduca, pide uno nuevo.'])
            ->header('Cache-Control','private, no-store');
    }
    public function abrir(Request $request,int $id,int $instrumentoId,ReporteTecnicoDatosPdf $datos,ReporteTecnicoPdfService $pdf)
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(self::TRACKING,false),403,'El enlace no es válido o ya venció. Pide uno nuevo al asistente.');
        $uid=filter_var($request->query('u'),FILTER_VALIDATE_INT);
        abort_unless($uid && $uid>0,403,'El enlace no es válido.');
        $user=$this->usuario($uid);abort_unless($user,404,'El enlace ya no es válido.');
        $request->setUserResolver(fn()=>$user);$this->acceso($request,$id);
        $d=(string)$request->query('d');abort_unless(in_array($d,['0','1'],true),403,'El enlace no es válido.');
        try { $resultado=$pdf->generar($datos->cargar($id,$instrumentoId)); }
        catch(InvalidArgumentException $e) { abort(422,$e->getMessage()); }
        $this->auditar($request,$id,$instrumentoId,'abrir');
        return response($resultado['contenido'],200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>HeaderUtils::makeDisposition($d==='1'?HeaderUtils::DISPOSITION_ATTACHMENT:HeaderUtils::DISPOSITION_INLINE,
                $resultado['archivo'],Str::ascii($resultado['archivo'])),
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer']);
    }
}
