<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\Diagnostico;
use App\Models\InformeFinal;
use App\Models\InstrumentoTarea;
use App\Models\Reporte;
use App\Models\ReporteInstrumento;
use App\Models\TareasTecnicos;
use App\Services\ReporteInstrumentoService;
use App\Services\ReporteNumeracionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Replica RegistrarDiagnostico/RegistrarResultados y sus controladores, sin modificarlos.
 * MCP: asignación por tb_detalles_tarea, lista blanca de roles, confirmación y versión.
 * Resultado no exige diagnóstico; no cierra ni aprueba la tarea. Sin fotos por MCP.
 * Características: solo snapshot sin diagnóstico/informe (más restrictivo que la pantalla).
 * Extras conservan tipo_servicio y clase_tipo_metrologico; no se pierden al validar.
 */
class McpReportesController extends Controller
{
    private const CAMPOS = ['tipo_instrumento', 'marca', 'material', 'modelo', 'capacidad_rango',
        'resolucion_division', 'clase_tipo_metrologico', 'serie', 'codigo_int'];

    private function acceso(Request $request, bool $escritura = false): bool
    {
        abort_unless($request->user(), 401);
        $roles = $request->user()->roles->pluck('name')->all();
        $total = count(array_intersect($roles, (array) config('mcp.roles_acceso_total', []))) > 0;
        $clave = $escritura ? 'reportes_roles_escritura' : 'reportes_roles';
        abort_unless($total || array_intersect($roles, (array) config('mcp.'.$clave, [])), 403,
            'Tu rol no tiene acceso a esta operación de reportes.');
        return $total || count(array_intersect($roles, (array) config('mcp.reportes_roles_ve_todo', []))) > 0;
    }

    private function tareas(Request $request, bool $todos)
    {
        $query = TareasTecnicos::query();
        if (! $todos) {
            $query->whereExists(function ($q) use ($request) {
                $q->selectRaw('1')->from('tb_detalles_tarea as dt')
                    ->join('tb_tecnicos_existentes as te', 'te.id', '=', 'dt.id_tecnico')
                    ->whereColumn('dt.id_tarea', 'tb_tareas_tecnicos.id')
                    ->where('te.user_id', $request->user()->id);
            });
        }
        return $query;
    }

    private function fallo(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }

    private function auditar(Request $request, string $accion, array $datos): void
    {
        Log::info('mcp.reportes', ['user_id' => $request->user()->id, 'accion' => $accion, 'params' => $datos]);
    }

    private function editable($tarea, $reporte): bool
    {
        return in_array($tarea->estado, ['pendiente', 'en_proceso'], true)
            && (! $reporte || $reporte->estado_global === 'abierto');
    }

    private function instrumentos($tarea, $reporte, bool $bloquear = false)
    {
        $q = $reporte
            ? ReporteInstrumento::where('reporte_id', $reporte->id)->with([
                'diagnostico' => fn ($q) => $bloquear ? $q->lockForUpdate() : $q,
                'informeFinal' => fn ($q) => $bloquear ? $q->lockForUpdate() : $q,
            ])
            : InstrumentoTarea::where('tarea_id', $tarea->id);
        if ($bloquear) { $q->lockForUpdate(); }
        return $q->orderBy('id')->get();
    }

    private function version($tarea, $reporte, $instrumentos): string
    {
        // Incluye textos completos y registros hijos: detecta cambios aunque updated_at no cambie.
        return hash('sha256', json_encode([$tarea->getAttributes(), $reporte?->getAttributes(),
            $instrumentos->toArray()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function progreso($instrumentos, bool $snapshot): array
    {
        $diagnosticos = 0;
        $informes = 0;
        $procesados = 0;
        foreach ($instrumentos as $inst) {
            $d = $snapshot && $inst->diagnostico !== null;
            $i = $snapshot && $inst->informeFinal !== null;
            $diagnosticos += (int) $d;
            $informes += (int) $i;
            $procesados += (int) ($d || $i);
        }
        $total = $instrumentos->count();
        return ['total_instrumentos' => $total, 'total_diagnosticos' => $diagnosticos,
            'total_informes' => $informes, 'procesados_diagnostico' => $procesados,
            'fase_diagnostico_completa' => $total > 0 && $procesados === $total,
            'fase_informe_completa' => $total > 0 && $informes === $total];
    }

    public function buscar(Request $request)
    {
        $todos = $this->acceso($request);
        $data = $request->validate(['fase' => ['nullable', Rule::in(['diagnostico', 'resultado'])],
            'cliente' => 'nullable|string|max:100', 'pagina' => 'nullable|integer|min:1',
            'limite' => 'nullable|integer|min:1|max:20']);
        $fase = $data['fase'] ?? 'diagnostico';
        $q = $this->tareas($request, $todos)->whereIn('estado', ['pendiente', 'en_proceso'])
            ->whereDoesntHave('reporte', fn ($r) => $r->where('estado_global', '!=', 'abierto'));
        // Filtrar antes de paginar: total y hay_mas reflejan realmente tareas pendientes.
        $q->where(function ($q) use ($fase) {
            $q->where(function ($q) {
                $q->whereDoesntHave('reporte')->whereHas('instrumentos');
            })->orWhereHas('reporte.instrumentos', function ($i) use ($fase) {
                $i->whereDoesntHave('informeFinal');
                if ($fase === 'diagnostico') {
                    $i->whereDoesntHave('diagnostico');
                }
            });
        });
        if (! empty($data['cliente'])) {
            $q->where('cliente', 'like', '%'.addcslashes($data['cliente'], '\\%_').'%');
        }
        $pagina = (int) ($data['pagina'] ?? 1);
        $limite = (int) ($data['limite'] ?? 10);
        $total = (clone $q)->count();
        $filas = $q->orderByDesc('fecha')->orderByDesc('id')->forPage($pagina, $limite)
            ->get(['id', 'cliente', 'fecha', 'estado'])->map(fn ($t) => [
                'tarea_id' => $t->id, 'cliente' => Str::limit($t->cliente, 200),
                'fecha' => substr((string) $t->fecha, 0, 10), 'estado' => $t->estado]);
        $this->auditar($request, 'buscar', ['fase' => $fase, 'pagina' => $pagina]);
        return response()->json(['alcance' => $todos ? 'todos' : 'solo_asignados', 'fase' => $fase,
            'pagina' => $pagina, 'limite' => $limite, 'total' => $total,
            'hay_mas' => $pagina * $limite < $total, 'data' => $filas]);
    }

    public function ver(Request $request, int $id)
    {
        $todos = $this->acceso($request);
        $tarea = $this->tareas($request, $todos)->findOrFail($id);
        $data = $request->validate(['pagina' => 'nullable|integer|min:1', 'limite' => 'nullable|integer|min:1|max:20']);
        $reporte = Reporte::where('tarea_id', $id)->first();
        $instrumentos = $this->instrumentos($tarea, $reporte);
        $roles = $request->user()->roles->pluck('name')->all();
        $puede = count(array_intersect($roles, array_merge((array) config('mcp.roles_acceso_total', []),
            (array) config('mcp.reportes_roles_escritura', [])))) > 0;
        $editable = $puede && $this->editable($tarea, $reporte);
        $pagina = (int) ($data['pagina'] ?? 1);
        $limite = (int) ($data['limite'] ?? 10);
        $filas = $instrumentos->forPage($pagina, $limite)->map(function ($inst) use ($reporte, $editable) {
            $diag = $reporte ? $inst->diagnostico : null;
            $inf = $reporte ? $inst->informeFinal : null;
            $acciones = [];
            if ($editable && ! $inf) {
                $acciones[] = 'registrar_resultado';
                if (! $diag) {
                    $acciones[] = 'registrar_diagnostico';
                    if ($reporte) { $acciones[] = 'editar_caracteristicas'; }
                }
            }
            $textos = [$diag?->fallas_detectadas, $diag?->trabajos_sugeridos, $inf?->trabajos_realizados, $inf?->observaciones_finales];
            return ['reporte_instrumento_id' => $reporte ? $inst->id : null,
                'instrumento_tarea_id' => $reporte ? $inst->instrumento_tarea_id : $inst->id,
                'caracteristicas' => collect($inst->only(array_merge(self::CAMPOS, ['tipo_servicio'])))
                    ->map(fn ($v) => $v === null ? null : Str::limit((string) $v, 200)),
                'diagnostico_previo' => $diag ? ['fallas_detectadas' => Str::limit($diag->fallas_detectadas, 1000),
                    'trabajos_sugeridos' => Str::limit($diag->trabajos_sugeridos ?? '', 1000)] : null,
                'resultado' => $inf ? ['trabajos_realizados' => Str::limit($inf->trabajos_realizados, 1000),
                    'observaciones_finales' => Str::limit($inf->observaciones_finales ?? '', 1000),
                    'n_cotizacion' => Str::limit($inf->n_cotizacion ?? '', 50)] : null,
                'textos_recortados' => collect($textos)->contains(fn ($v) => mb_strlen($v ?? '') > 1000),
                'acciones_permitidas' => $acciones,
                'motivo_restriccion' => ! $editable ? 'Tarea/reporte cerrado o rol sin escritura.' : ($inf ? 'Informe ya registrado.' : ($diag ? 'Diagnóstico inmutable; admite resultado.' : null))];
        })->values();
        $this->auditar($request, 'ver', ['tarea_id' => $id]);
        return response()->json(['id' => $id, 'tarea_id' => $id, 'reporte_id' => $reporte?->id,
            'cliente' => Str::limit($tarea->cliente, 200), 'estado_tarea' => $tarea->estado,
            'estado_reporte' => $reporte?->estado_global, 'version_actual' => $this->version($tarea, $reporte, $instrumentos),
            'acciones_permitidas' => $editable ? ['registrar_diagnostico_extra', 'registrar_resultado_extra'] : [],
            'motivo_restriccion' => $editable ? null : 'Tarea/reporte cerrado o rol sin escritura.',
            'progreso' => $this->progreso($instrumentos, $reporte !== null),
            'pagina' => $pagina, 'limite' => $limite, 'total' => $instrumentos->count(),
            'hay_mas' => $pagina * $limite < $instrumentos->count(), 'data' => $filas]);
    }

    public function diagnostico(Request $request, int $id) { return $this->guardar($request, $id, 'diagnostico'); }
    public function resultado(Request $request, int $id) { return $this->guardar($request, $id, 'resultado'); }
    public function caracteristicas(Request $request, int $id) { return $this->guardar($request, $id, 'caracteristicas'); }

    private function guardar(Request $request, int $id, string $tipo)
    {
        $todos = $this->acceso($request, true);
        $this->tareas($request, $todos)->findOrFail($id);
        $rules = ['confirmado' => 'required|accepted', 'version_actual' => 'required|string|size:64',
            'reporte_instrumento_id' => 'nullable|integer|min:1', 'instrumento_tarea_id' => 'nullable|integer|min:1'];
        if ($tipo === 'caracteristicas') {
            $rules['reporte_instrumento_id'] = 'required|integer|min:1';
            $rules['instrumento_tarea_id'] = 'prohibited';
            $rules['cambios'] = 'required|array:'.implode(',', self::CAMPOS).'|min:1';
            $prefix = 'cambios.';
        } else {
            $rules['instrumento_extra'] = 'sometimes|min:1|array:'.implode(',', array_merge(self::CAMPOS, ['tipo_servicio']));
            $prefix = 'instrumento_extra.';
            $rules[$prefix.'tipo_servicio'] = 'sometimes|nullable|string|max:100';
            if ($tipo === 'diagnostico') {
                $rules['fallas_detectadas'] = 'required|string|max:10000';
                $rules['trabajos_sugeridos'] = 'nullable|string|max:10000';
            } else {
                $rules['trabajos_realizados'] = 'required|string|max:10000';
                $rules['observaciones_finales'] = 'nullable|string|max:10000';
                $rules['n_cotizacion'] = 'nullable|string|max:50';
            }
        }
        foreach (self::CAMPOS as $campo) {
            $max = $campo === 'material' ? 150 : ($campo === 'codigo_int' ? 50 : 100);
            $rules[$prefix.$campo] = 'sometimes|nullable|string|max:'.$max;
        }
        $rules[$prefix.'tipo_instrumento'] = $tipo === 'caracteristicas'
            ? 'sometimes|required|string|max:100' : 'required_with:instrumento_extra|string|max:100';
        $data = $request->validate($rules);
        // Rechaza claves no previstas también si se llama directamente al endpoint.
        $permitidas = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($request->all()), $permitidas)) {
            $this->fallo('datos', 'Hay campos no permitidos; usuario, fecha, estados y fotos los controla el sistema.');
        }
        if ($request->input('confirmado') !== true) {
            $this->fallo('confirmado', 'Confirma explícitamente antes de guardar (confirmado=true).');
        }
        $referencias = (int) ! empty($data['reporte_instrumento_id']) + (int) ! empty($data['instrumento_tarea_id'])
            + (int) array_key_exists('instrumento_extra', $data);
        if ($referencias !== 1) {
            $this->fallo('instrumento', 'Indica exactamente una referencia de instrumento o instrumento_extra.');
        }
        foreach (['fallas_detectadas', 'trabajos_realizados'] as $campo) {
            if (isset($data[$campo]) && trim($data[$campo]) === '') { $this->fallo($campo, 'El texto no puede estar vacío.'); }
        }
        return DB::transaction(function () use ($request, $id, $tipo, $todos, $data) {
            // Orden de bloqueo estable: tarea -> reporte -> instrumentos -> registros hijos.
            $tarea = $this->tareas($request, $todos)->lockForUpdate()->findOrFail($id);
            $reporte = Reporte::where('tarea_id', $id)->lockForUpdate()->first();
            if (! $this->editable($tarea, $reporte)) { $this->fallo('estado', 'La tarea o el reporte están cerrados para registro.'); }
            $instrumentos = $this->instrumentos($tarea, $reporte, true);
            if (! hash_equals($this->version($tarea, $reporte, $instrumentos), $data['version_actual'])) {
                $this->fallo('version_actual', 'Los datos cambiaron. Consulta nuevamente el reporte y confirma antes de reenviar.');
            }
            // Comprobar pertenencia ANTES de crear snapshot o numeración.
            if (! empty($data['reporte_instrumento_id']) && (! $reporte || ! $instrumentos->contains('id', (int) $data['reporte_instrumento_id']))) {
                $this->fallo('instrumento', 'El instrumento no pertenece al reporte de esta tarea.');
            }
            if (! empty($data['instrumento_tarea_id']) && ! InstrumentoTarea::where('tarea_id', $id)->whereKey($data['instrumento_tarea_id'])->exists()) {
                $this->fallo('instrumento', 'El instrumento base no pertenece a esta tarea.');
            }
            $service = app(ReporteInstrumentoService::class);
            $reporte = $reporte ?? $service->obtenerOCrearReporte($id);
            if (isset($data['instrumento_extra'])) {
                $extra = $data['instrumento_extra'];
                $extra['tipo_servicio'] = $extra['tipo_servicio'] ?? ($tipo === 'diagnostico' ? 'Diagnóstico Técnico' : 'Mantenimiento');
                $inst = $service->crearInstrumentoExtra($reporte, $extra);
            } else {
                $q = ReporteInstrumento::where('reporte_id', $reporte->id);
                $q = ! empty($data['reporte_instrumento_id']) ? $q->whereKey($data['reporte_instrumento_id'])
                    : $q->where('instrumento_tarea_id', $data['instrumento_tarea_id']);
                $inst = $q->lockForUpdate()->first();
                if (! $inst) { $this->fallo('instrumento', 'No se encontró el instrumento en este reporte.'); }
            }
            $diag = Diagnostico::where('reporte_instrumento_id', $inst->id)->lockForUpdate()->first();
            $inf = InformeFinal::where('reporte_instrumento_id', $inst->id)->lockForUpdate()->first();
            if ($inf || ($diag && $tipo !== 'resultado')) {
                $this->fallo('instrumento', 'Ya existe un registro que bloquea esta operación; no se sobrescribe.');
            }
            if ($tipo === 'caracteristicas') {
                $inst->fill($data['cambios'])->save();
                $registroId = $inst->id;
            } else {
                $valores = ['reporte_instrumento_id' => $inst->id, 'tecnico_id' => $request->user()->id,
                    'tecnico_responsable' => $request->user()->name, 'fecha' => now()->toDateString()];
                if ($tipo === 'diagnostico') {
                    $registro = Diagnostico::create($valores + ['estado_diagnostico' => 'subido',
                        'fallas_detectadas' => $data['fallas_detectadas'], 'trabajos_sugeridos' => $data['trabajos_sugeridos'] ?? null]);
                } else {
                    $registro = InformeFinal::create($valores + ['estado_informe' => 'subido',
                        'trabajos_realizados' => $data['trabajos_realizados'], 'observaciones_finales' => $data['observaciones_finales'] ?? null,
                        'n_cotizacion' => $data['n_cotizacion'] ?? null]);
                }
                $registroId = $registro->id;
                app(ReporteNumeracionService::class)->asignar((int) $reporte->id);
            }
            $this->auditar($request, $tipo, ['tarea_id' => $id, 'instrumento_id' => $inst->id, 'id' => $registroId]);
            return response()->json([$tipo === 'caracteristicas' ? 'actualizado' : 'registrado' => true,
                'id' => $registroId, 'tarea_id' => $id, 'reporte_id' => $reporte->id,
                'reporte_instrumento_id' => $inst->id,
                'progreso' => $this->progreso($this->instrumentos($tarea, $reporte), true),
                'nota' => 'Guardado a nombre del usuario conectado. No aprueba ni cierra la tarea. Fotos desde el sistema.'],
                $tipo === 'caracteristicas' ? 200 : 201);
        }, 3);
    }
}
