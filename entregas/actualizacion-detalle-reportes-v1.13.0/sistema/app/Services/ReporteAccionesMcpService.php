<?php
namespace App\Services;

use App\Http\Controllers\Mcp\McpHistorialReportesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Políticas de Show.vue; consultas no crean snapshots ni numeración. */
class ReporteAccionesMcpService
{
    public const FICHA = ['tipo_instrumento','marca','modelo','serie','codigo_int','capacidad_rango','resolucion_division','clase_tipo_metrologico','material'];
    public const INFORME = ['fallas_detectadas','repuestos_sugeridos','trabajos_sugeridos'];
    public const RESULTADO = ['n_cotizacion','trabajos_realizados','repuestos_instalados','observaciones_finales'];

    public function contexto(Request $request, int $id, bool $bloquear = false): array
    {
        app(McpHistorialReportesController::class)->ver($request, $id);
        $pre = DB::table('reportes')->where('id', $id)->first();
        abort_unless($pre, 404, 'Reporte no encontrado.');
        // Orden compatible con registro MCP: tarea -> reporte -> instrumentos -> hijos.
        if ($bloquear && $pre->tarea_id) { DB::table('tb_tareas_tecnicos')->where('id', $pre->tarea_id)->lockForUpdate()->first(); }
        $q = DB::table('reportes')->where('id', $id);
        $r = ($bloquear ? $q->lockForUpdate() : $q)->first();
        abort_unless($r, 404, 'Reporte no encontrado.');
        if ($bloquear) { app(McpHistorialReportesController::class)->ver($request, $id); }
        $q = DB::table('reportes_instrumentos')->where('reporte_id', $id)->orderBy('id');
        $items = ($bloquear ? $q->lockForUpdate() : $q)->get();
        $q = DB::table('diagnosticos')->whereIn('reporte_instrumento_id', $items->pluck('id'))->orderBy('id');
        $informes = ($bloquear ? $q->lockForUpdate() : $q)->get();
        $q = DB::table('informes_finales')->whereIn('reporte_instrumento_id', $items->pluck('id'))->orderBy('id');
        $resultados = ($bloquear ? $q->lockForUpdate() : $q)->get();
        $tarea = $r->tarea_id ? DB::table('tb_tareas_tecnicos')->where('id', $r->tarea_id)->first() : null;
        $pendiente = $tarea && $tarea->pendiente_id ? DB::table('tb_pendientes')->where('id', $tarea->pendiente_id)->first() : null;
        return ['reporte'=>$r, 'instrumentos'=>$items, 'informes'=>$informes, 'resultados'=>$resultados,
            'tarea'=>$tarea, 'pendiente'=>$pendiente,
            'version_actual'=>hash('sha256', json_encode([$r,$items,$informes,$resultados,$tarea,$pendiente], JSON_THROW_ON_ERROR))];
    }

    private function roles(Request $request): array { return $request->user()->roles->pluck('name')->all(); }
    private function total(Request $request): bool { return (bool) array_intersect($this->roles($request), (array) config('mcp.roles_acceso_total', [])); }
    public function asignado(Request $request, $r): bool
    {
        if (! $r->tarea_id) { return ! empty($r->tecnico_responsable) && $r->tecnico_responsable === $request->user()->name; }
        return DB::table('tb_detalles_tarea as dt')->join('tb_tecnicos_existentes as te','te.id','=','dt.id_tecnico')
            ->where('dt.id_tarea',$r->tarea_id)->where('te.user_id',$request->user()->id)->exists();
    }

    public function motivoEdicion(Request $request, array $ctx, $item, string $tipo): ?string
    {
        if ($ctx['reporte']->estado_global !== 'abierto') { return 'El reporte está cerrado para edición.'; }
        $total = $this->total($request);
        if (! $total && ! array_intersect($this->roles($request),(array) config('mcp.detalle_reportes_roles_edicion',[]))) {
            return 'Tu rol no puede editar desde el asistente.';
        }
        $supervisor = (bool) array_intersect($this->roles($request),(array) config('mcp.detalle_reportes_roles_supervisor',[]));
        if ($tipo === 'instrumento') {
            return $total || $this->asignado($request,$ctx['reporte']) ? null : 'Solo el técnico asignado o acceso total puede editar la ficha.';
        }
        $registros = $ctx[$tipo === 'informe' ? 'informes' : 'resultados']->where('reporte_instrumento_id',$item->id);
        if ($registros->count() !== 1) { return 'Se requiere exactamente un registro existente; revisa el instrumento en el sistema.'; }
        $registro = $registros->first();
        if ($tipo === 'informe' && $registro->estado_diagnostico === 'finalizado') { return 'El informe está liberado y no se puede editar.'; }
        if (! $total && ! $supervisor && ! $this->asignado($request,$ctx['reporte'])) { return 'No estás asignado a la tarea.'; }
        if (! $total && ! $supervisor && $registro->tecnico_id && (int)$registro->tecnico_id !== (int)$request->user()->id) {
            return 'El registro pertenece a otro técnico.';
        }
        return null;
    }

    public function motivoCierre(Request $request, array $ctx): ?string
    {
        $permitido = $this->total($request)
            || array_intersect($this->roles($request),(array) config('mcp.detalle_reportes_roles_finalizar',[]))
            || $request->user()->can((string) config('mcp.historial_reportes_permiso_finalizar','Finalizar Reporte Operaciones'));
        if (! $permitido) { return 'No tienes permiso para finalizar reportes.'; }
        if ($ctx['reporte']->estado_global !== 'abierto') { return 'El reporte ya está cerrado.'; }
        if ($ctx['instrumentos']->isEmpty()) { return 'El reporte no tiene instrumentos.'; }
        $esDiag = fn ($s) => str_contains(Str::lower(Str::ascii((string)$s)), 'diagnost');
        foreach ($ctx['instrumentos'] as $i) {
            $ds = $ctx['informes']->where('reporte_instrumento_id',$i->id);
            $rs = $ctx['resultados']->where('reporte_instrumento_id',$i->id);
            if ($ds->count() > 1 || $rs->count() > 1) { return 'Hay registros duplicados; revisa el reporte en el sistema.'; }
            if ($rs->first() && trim((string)$rs->first()->trabajos_realizados) !== '') { continue; }
            if (($esDiag($i->tipo_servicio) || $esDiag($ctx['pendiente']->tipo_servicio ?? '')) && $ds->isNotEmpty()) { continue; }
            return 'Falta resultado en un instrumento (o informe para un servicio de diagnóstico).';
        }
        return null;
    }

    public function comprobarVersion(array $ctx, string $version): void
    {
        if (! hash_equals($ctx['version_actual'],$version)) {
            throw ValidationException::withMessages(['version_actual'=>'El reporte cambió. Consulta de nuevo y confirma los cambios.']);
        }
    }
}
