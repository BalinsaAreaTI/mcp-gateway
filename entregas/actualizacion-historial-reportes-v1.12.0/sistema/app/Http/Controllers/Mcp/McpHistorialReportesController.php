<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hojaservico/Index.vue + ReportesController::getReportesPorFecha/finalizarServicio.
 * Historial por ID de REPORTE, no tarea. Informes = diagnosticos; resultados = informes_finales.
 * Más restrictivo: técnicos solo asignados; rangos <= 93 días; sin borrado irreversible.
 * Finaliza únicamente el pendiente, nunca aprueba/cierra reportes ni cambia sus instrumentos.
 */
class McpHistorialReportesController extends Controller
{
    private function acceso(Request $request): bool
    {
        abort_unless($request->user(), 401);
        $roles = $request->user()->roles->pluck('name')->all();
        $total = (bool) array_intersect($roles, (array) config('mcp.roles_acceso_total', []));
        abort_unless($total || array_intersect($roles, (array) config('mcp.historial_reportes_roles', [])),
            403, 'Tu rol no tiene acceso al historial de reportes.');
        return $total || (bool) array_intersect($roles, (array) config('mcp.historial_reportes_roles_ve_todo', []));
    }

    private function puedeFinalizar(Request $request): bool
    {
        $roles = $request->user()->roles->pluck('name')->all();
        return (bool) array_intersect($roles, array_merge((array) config('mcp.roles_acceso_total', []),
            (array) config('mcp.historial_reportes_roles_finalizar', [])))
            || $request->user()->can((string) config('mcp.historial_reportes_permiso_finalizar', 'Finalizar Reporte Operaciones'));
    }

    private function base(Request $request, bool $todos)
    {
        $q = DB::table('reportes as r')->join('clientes as c', 'c.id', '=', 'r.cliente_id')
            ->leftJoin('tb_tareas_tecnicos as t', 't.id', '=', 'r.tarea_id')
            ->leftJoin('tb_pendientes as p', 'p.id', '=', 't.pendiente_id');
        if (! $todos) {
            $q->whereExists(function ($q) use ($request) {
                $q->selectRaw('1')->from('tb_detalles_tarea as dt')
                    ->join('tb_tecnicos_existentes as te', 'te.id', '=', 'dt.id_tecnico')
                    ->whereColumn('dt.id_tarea', 'r.tarea_id')->where('te.user_id', $request->user()->id);
            });
        }
        return $q;
    }

    private function contar($q)
    {
        // Subconsultas evitan multiplicar instrumentos al unir informes y resultados.
        return $q->select('r.id', 'r.n_servicio', 'r.fecha', 'r.estado_global', 'r.tarea_id',
            'c.razonSocial as cliente', 'p.id as pendiente_id', 'p.estado as estado_servicio', 'p.tipo_servicio')
            ->selectSub(DB::table('reportes_instrumentos as i')->selectRaw('COUNT(*)')->whereColumn('i.reporte_id', 'r.id'), 'cantidad_instrumentos')
            ->selectSub(DB::table('diagnosticos as d')->join('reportes_instrumentos as i', 'i.id', '=', 'd.reporte_instrumento_id')
                ->selectRaw('COUNT(DISTINCT d.id)')->whereColumn('i.reporte_id', 'r.id'), 'cantidad_informes')
            ->selectSub(DB::table('informes_finales as f')->join('reportes_instrumentos as i', 'i.id', '=', 'f.reporte_instrumento_id')
                ->selectRaw('COUNT(DISTINCT f.id)')->whereColumn('i.reporte_id', 'r.id'), 'cantidad_resultados');
    }

    private function filtros(Request $request): array
    {
        $d = $request->validate(['desde'=>'nullable|date_format:Y-m-d', 'hasta'=>'nullable|date_format:Y-m-d',
            'cliente'=>'nullable|string|max:100', 'numero_reporte'=>'nullable|string|max:30|regex:/^(?:RPT-)?[0-9]+$/i',
            'numero_pendiente'=>'nullable|string|max:20|regex:/^[0-9]+$/', 'pendiente_id'=>'nullable|integer|min:1',
            'estado'=>['nullable', Rule::in(['abierto', 'cerrado'])],
            'pagina'=>'nullable|integer|min:1', 'limite'=>'nullable|integer|min:1|max:20']);
        $hoy = Carbon::now('America/Lima')->startOfDay();
        $hasta = Carbon::parse($d['hasta'] ?? ($d['desde'] ?? $hoy->toDateString()), 'America/Lima')->startOfDay();
        $desde = Carbon::parse($d['desde'] ?? $hasta->copy()->subDays(7)->toDateString(), 'America/Lima')->startOfDay();
        if ($desde->gt($hasta) || $desde->diffInDays($hasta) > (int) config('mcp.historial_reportes_max_dias', 93)) {
            throw ValidationException::withMessages(['desde'=>'Rango inválido: inicio debe preceder al fin y no superar el máximo de días configurado.']);
        }
        $d['desde'] = $desde->toDateString(); $d['hasta'] = $hasta->toDateString();
        return $d;
    }

    private function filtrada(Request $request, bool $todos, array $d)
    {
        $q = $this->base($request, $todos)->whereDate('r.fecha', '>=', $d['desde'])->whereDate('r.fecha', '<=', $d['hasta']);
        if (! empty($d['cliente'])) { $q->where('c.razonSocial', 'like', '%'.addcslashes($d['cliente'], '\\%_').'%'); }
        if (isset($d['numero_reporte']) && $d['numero_reporte'] !== '') {
            $numero = preg_replace('/^RPT-/i', '', $d['numero_reporte']);
            $q->where('r.n_servicio', 'like', '%'.$numero.'%');
        }
        if (isset($d['numero_pendiente'])) {
            $numero = ltrim($d['numero_pendiente'], '0');
            $q->whereRaw('CAST(p.id AS CHAR) LIKE ?', ['%'.($numero === '' ? '0' : $numero).'%']);
        }
        if (isset($d['pendiente_id'])) { $q->where('p.id', $d['pendiente_id']); }
        if (isset($d['estado'])) { $q->where('r.estado_global', $d['estado']); }
        return $q;
    }

    private function auditar(Request $request, string $accion, array $datos = []): void
    {
        Log::info('mcp.historial_reportes', ['user_id'=>$request->user()->id, 'accion'=>$accion, 'params'=>$datos]);
    }

    private function cabecera($r): array
    {
        return ['reporte_id'=>(int) $r->id, 'numero_reporte'=>$r->n_servicio, 'fecha'=>substr((string) $r->fecha, 0, 10),
            'estado'=>$r->estado_global, 'cliente'=>Str::limit($r->cliente, 200),
            'tarea_id'=>$r->tarea_id ? (int) $r->tarea_id : null, 'pendiente_id'=>$r->pendiente_id ? (int) $r->pendiente_id : null,
            'estado_servicio'=>$r->estado_servicio, 'tipo_servicio'=>Str::limit($r->tipo_servicio ?? '', 200),
            'cantidad_instrumentos'=>(int) $r->cantidad_instrumentos, 'cantidad_informes'=>(int) $r->cantidad_informes,
            'cantidad_resultados'=>(int) $r->cantidad_resultados];
    }

    public function buscar(Request $request)
    {
        $todos = $this->acceso($request); $d = $this->filtros($request);
        $q = $this->filtrada($request, $todos, $d); $total = (clone $q)->count();
        $pagina = (int) ($d['pagina'] ?? 1); $limite = (int) ($d['limite'] ?? 10);
        $filas = $this->contar($q)->orderByDesc('r.fecha')->orderByDesc('r.id')->forPage($pagina, $limite)->get();
        $this->auditar($request, 'buscar', $d);
        return response()->json(['alcance'=>$todos ? 'todos' : 'solo_asignados', 'desde'=>$d['desde'], 'hasta'=>$d['hasta'],
            'pagina'=>$pagina, 'limite'=>$limite, 'total'=>$total, 'hay_mas'=>$pagina * $limite < $total,
            'data'=>$filas->map(fn ($r) => $this->cabecera($r))]);
    }

    public function resumen(Request $request)
    {
        $todos = $this->acceso($request); $d = $this->filtros($request);
        $q = $this->contar($this->filtrada($request, $todos, $d));
        $r = DB::query()->fromSub($q, 'h')->selectRaw("COUNT(*) as reportes, COUNT(DISTINCT pendiente_id) as servicios,
            COALESCE(SUM(CASE WHEN estado_global = 'abierto' THEN 1 ELSE 0 END), 0) as abiertos,
            COALESCE(SUM(CASE WHEN estado_global = 'cerrado' THEN 1 ELSE 0 END), 0) as cerrados,
            COALESCE(SUM(cantidad_instrumentos), 0) as instrumentos,
            COALESCE(SUM(cantidad_informes), 0) as informes, COALESCE(SUM(cantidad_resultados), 0) as resultados")->first();
        $this->auditar($request, 'resumen', $d);
        return response()->json(['alcance'=>$todos ? 'todos' : 'solo_asignados', 'desde'=>$d['desde'], 'hasta'=>$d['hasta'],
            'totales'=>array_map('intval', (array) $r)]);
    }

    private function registro(Request $request, int $id)
    {
        $todos = $this->acceso($request);
        $r = $this->contar($this->base($request, $todos))->where('r.id', $id)->first();
        abort_unless($r, 404, 'Reporte no encontrado.');
        return $r;
    }

    public function ver(Request $request, int $id)
    {
        $r = $this->registro($request, $id);
        $this->auditar($request, 'ver', ['reporte_id'=>$id]);
        return response()->json($this->cabecera($r) + ['acciones_permitidas'=>['ver_instrumentos'],
            'motivo_restriccion'=>'Consulta histórica. Eliminación, aprobación y cierre de reportes se realizan en el sistema.',
            'nota'=>'Para finalizar el servicio usa pendiente_id y consulta primero servicio_tecnico_ver_estado_servicio. Para registrar informe/resultado usa tarea_id, nunca reporte_id.']);
    }

    public function instrumentos(Request $request, int $id)
    {
        $this->registro($request, $id);
        $d = $request->validate(['pagina'=>'nullable|integer|min:1', 'limite'=>'nullable|integer|min:1|max:20',
            'instrumento_id'=>'nullable|integer|min:1']);
        $q = DB::table('reportes_instrumentos')->where('reporte_id', $id);
        if (isset($d['instrumento_id'])) { $q->where('id', $d['instrumento_id']); }
        $total = (clone $q)->count();
        abort_if(isset($d['instrumento_id']) && $total === 0, 404, 'Instrumento no encontrado en este reporte.');
        $pagina = (int) ($d['pagina'] ?? 1); $limite = (int) ($d['limite'] ?? 10);
        $items = $q->orderBy('id')->forPage($pagina, $limite)->get(['id','orden_pdf','tipo_instrumento','tipo_servicio',
            'marca','modelo','serie','codigo_int','capacidad_rango','resolucion_division','clase_tipo_metrologico','material']);
        $informes = DB::table('diagnosticos')->whereIn('reporte_instrumento_id', $items->pluck('id'))->orderBy('id')->get([
            'id','reporte_instrumento_id','fallas_detectadas','trabajos_sugeridos','estado_diagnostico','fecha','tecnico_responsable'])->groupBy('reporte_instrumento_id');
        $resultados = DB::table('informes_finales')->whereIn('reporte_instrumento_id', $items->pluck('id'))->orderBy('id')->get([
            'id','reporte_instrumento_id','trabajos_realizados','observaciones_finales','n_cotizacion','estado_informe','fecha','tecnico_responsable'])->groupBy('reporte_instrumento_id');
        $data = $items->map(function ($i) use ($informes, $resultados) {
            $d = $informes->get($i->id, collect()); $f = $resultados->get($i->id, collect());
            $recortado = false;
            $limitar = function ($row) use (&$recortado) {
                return collect((array) $row)->map(function ($v) use (&$recortado) {
                    if (is_string($v)) { $recortado = $recortado || mb_strlen($v) > 1000; return Str::limit($v, 1000); }
                    return $v;
                })->all();
            };
            $equipo = $limitar($i);
            $informe = $d->first() ? $limitar($d->first()) : null;
            $resultado = $f->first() ? $limitar($f->first()) : null;
            if ($informe) { $informe['estado'] = $informe['estado_diagnostico']; unset($informe['estado_diagnostico']); }
            if ($resultado) { $resultado['estado'] = $resultado['estado_informe']; unset($resultado['estado_informe']); }
            return ['instrumento'=>$equipo, 'informe'=>$informe, 'resultado'=>$resultado,
                'textos_recortados'=>$recortado, 'advertencias'=>($d->count() > 1 || $f->count() > 1)
                    ? ['Hay registros duplicados para este instrumento; se muestra el primero. Revisar en el sistema.'] : []];
        });
        $this->auditar($request, 'instrumentos', ['reporte_id'=>$id, 'pagina'=>$pagina]);
        return response()->json(['reporte_id'=>$id, 'pagina'=>$pagina, 'limite'=>$limite, 'total'=>$total,
            'hay_mas'=>$pagina * $limite < $total, 'data'=>$data]);
    }

    private function servicio(Request $request, int $id, bool $bloquear = false): array
    {
        abort_unless($this->acceso($request), 403, 'Consultar o finalizar un servicio completo requiere acceso global al historial.');
        $q = DB::table('tb_pendientes')->where('id', $id);
        $p = ($bloquear ? $q->lockForUpdate() : $q)->first(['id','cliente_id','estado','updated_at']);
        abort_unless($p, 404, 'Servicio no encontrado.');
        $q = DB::table('tb_tareas_tecnicos')->where('pendiente_id', $id)->orderBy('id');
        $tareas = ($bloquear ? $q->lockForUpdate() : $q)->get(['id','estado','updated_at']);
        $q = DB::table('reportes')->whereIn('tarea_id', $tareas->pluck('id'))->orderBy('id');
        $reportes = ($bloquear ? $q->lockForUpdate() : $q)->get(['id','estado_global','updated_at']);
        $version = hash('sha256', json_encode([$p, $tareas, $reportes], JSON_THROW_ON_ERROR));
        $bloqueantes = $reportes->filter(fn ($r) => $r->estado_global !== 'cerrado')->count();
        $motivo = ! $this->puedeFinalizar($request) ? 'Tu rol no puede finalizar servicios.'
            : ($p->estado === 'Finalizado' ? 'El servicio ya está finalizado.'
            : ($reportes->isEmpty() ? 'No hay reportes asociados al servicio.'
            : ($bloqueantes > 0 ? 'Todos los reportes del servicio deben estar cerrados.' : null)));
        return ['pendiente'=>$p, 'version_actual'=>$version, 'cantidad_reportes'=>$reportes->count(),
            'reportes_no_cerrados'=>$bloqueantes, 'motivo_restriccion'=>$motivo];
    }

    public function verServicio(Request $request, int $id)
    {
        $s = $this->servicio($request, $id);
        $cliente = DB::table('clientes')->where('id', $s['pendiente']->cliente_id)->value('razonSocial');
        $this->auditar($request, 'ver_servicio', ['pendiente_id'=>$id]);
        return response()->json(['pendiente_id'=>$id, 'cliente'=>Str::limit($cliente ?? '', 200),
            'estado'=>$s['pendiente']->estado, 'version_actual'=>$s['version_actual'],
            'cantidad_reportes'=>$s['cantidad_reportes'], 'reportes_no_cerrados'=>$s['reportes_no_cerrados'],
            'acciones_permitidas'=>$s['motivo_restriccion'] === null ? ['finalizar_servicio'] : [],
            'motivo_restriccion'=>$s['motivo_restriccion']]);
    }

    public function finalizarServicio(Request $request, int $id)
    {
        $this->acceso($request);
        abort_unless($this->puedeFinalizar($request), 403, 'No tienes permiso para finalizar servicios.');
        $d = $request->validate(['confirmado'=>'required|accepted', 'version_actual'=>'required|string|size:64']);
        if ($request->input('confirmado') !== true || array_diff(array_keys($request->all()), ['confirmado','version_actual'])) {
            throw ValidationException::withMessages(['confirmado'=>'Envía únicamente confirmado=true y la versión consultada.']);
        }
        return DB::transaction(function () use ($request, $id, $d) {
            $s = $this->servicio($request, $id, true);
            if (! hash_equals($s['version_actual'], $d['version_actual'])) {
                throw ValidationException::withMessages(['version_actual'=>'El servicio cambió. Consulta nuevamente y confirma antes de finalizar.']);
            }
            if ($s['motivo_restriccion']) { throw ValidationException::withMessages(['estado'=>$s['motivo_restriccion']]); }
            DB::table('tb_pendientes')->where('id', $id)->update(['estado'=>'Finalizado','updated_at'=>now()]);
            $this->auditar($request, 'finalizar_servicio', ['pendiente_id'=>$id, 'anterior'=>$s['pendiente']->estado, 'nuevo'=>'Finalizado']);
            return response()->json(['actualizado'=>true, 'id'=>$id, 'pendiente_id'=>$id,
                'estado_anterior'=>$s['pendiente']->estado, 'estado_nuevo'=>'Finalizado',
                'nota'=>'Se finalizó el servicio. No se modificaron reportes, tareas, instrumentos ni archivos.']);
        }, 3);
    }
}
