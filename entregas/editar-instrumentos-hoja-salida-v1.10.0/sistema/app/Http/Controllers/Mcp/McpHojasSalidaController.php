<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\AsignacionInstrumentosTarea;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hojas de salida: consulta y corrección de datos de sus instrumentos. Reproduce la estructura de datos utilizada
 * por obtenerPendientesProgramadosRango, ShowDetalleTarea e Index.vue.
 *
 * Regla MCP adicional: un tecnico solo ve tareas asociadas a su user_id
 * en tb_tecnicos_existentes y tb_detalles_tarea. Los roles con alcance completo
 * se configuran en config/mcp.php. Confirmar nombres reales de roles Spatie.
 * Nunca se toma un user_id del agente.
 */
class McpHojasSalidaController extends Controller
{
    // Reglas confirmadas por el usuario: solo completada bloquea la edición, incluso
    // con actas/reportes. Nunca se modifican tipo_instrumento, tipo_servicio ni vínculos.
    private const CAMPOS_EDITABLES = [
        'marca' => 100,
        'modelo' => 100,
        'serie' => 120,
        'codigo_int' => 80,
        'capacidad_rango' => 220,
        'resolucion_division' => 180,
        'clase_tipo_metrologico' => 120,
        'clase_exactitud' => 120,
        'material' => 120,
        'observaciones' => 2000,
        'puntos_calibracion' => 2000,
        'fecha_calibracion' => 10,
        'hora_servicio' => 5,
        'n_cotizacion' => 120,
    ];

    private function puedeEditarInstrumentos(Request $request): bool
    {
        $roles = $request->user()->roles->pluck('name');
        return $roles->intersect(array_merge(
            config('mcp.roles_acceso_total', ['Gerencia']),
            config('mcp.hojas_salida_roles_edicion', [])
        ))->isNotEmpty();
    }

    private function hojaCompletada(?string $estado): bool
    {
        return mb_strtolower(trim((string) $estado)) === 'completada';
    }

    private function versionInstrumento(object $fila): string
    {
        $campos = array_merge(['id', 'tarea_id', 'tipo_instrumento', 'tipo_servicio', 'updated_at'], array_keys(self::CAMPOS_EDITABLES));
        $valores = [];
        foreach ($campos as $campo) $valores[$campo] = $fila->$campo ?? null;
        return hash('sha256', json_encode($valores, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function detalleEdicion(Request $request, object $hoja, object $fila): array
    {
        $datos = ['id' => $fila->id, 'tarea_id' => $hoja->id,
            'tipo_instrumento' => $fila->tipo_instrumento, 'tipo_servicio' => $fila->tipo_servicio];
        foreach (self::CAMPOS_EDITABLES as $campo => $maximo) {
            // Consulta puntual: conservar el valor completo antes de confirmar una sustitución.
            $datos[$campo] = $fila->$campo ?? null;
        }
        $motivo = !$this->puedeEditarInstrumentos($request) ? 'Tu rol no puede editar instrumentos.'
            : ($this->hojaCompletada($hoja->estado) ? 'La hoja está completada.' : null);
        return $datos + ['version_actual' => $this->versionInstrumento($fila),
            'campos_editables' => array_keys(self::CAMPOS_EDITABLES),
            'acciones_permitidas' => $motivo === null ? ['editar_datos'] : [],
            'motivo_restriccion' => $motivo];
    }

    /** POST: corrección parcial de UNA copia histórica, exclusivamente desde MCP. */
    public function editarInstrumento(Request $request, int $id, int $instrumentoId)
    {
        [, $veTodo] = $this->acceso($request);
        // Comprobar visibilidad antes de dar información sobre la posibilidad de editar.
        $hoja = $this->visibles(DB::table('tb_tareas_tecnicos as t'), $request, $veTodo)->where('t.id', $id)->first(['t.id', 't.estado']);
        abort_unless($hoja, 404, 'Hoja de salida no encontrada.');
        abort_unless($this->puedeEditarInstrumentos($request), 403, 'Tu rol no puede editar instrumentos de hojas de salida.');
        $reglas = [
            'confirmado' => ['required', 'accepted'],
            'version_actual' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'cambios' => ['required', 'array:' . implode(',', array_keys(self::CAMPOS_EDITABLES)), 'min:1'],
        ];
        foreach (self::CAMPOS_EDITABLES as $campo => $maximo) {
            $reglas['cambios.' . $campo] = ['sometimes', 'nullable', 'string', 'max:' . $maximo];
        }
        $reglas['cambios.fecha_calibracion'][] = 'date_format:Y-m-d';
        $reglas['cambios.hora_servicio'][] = 'date_format:H:i';
        $data = $request->validate($reglas, [
            'confirmado.accepted' => 'Muestra los valores actuales y nuevos y solicita confirmación explícita antes de guardar.',
            'cambios.array' => 'Solo se permiten datos descriptivos; no se pueden cambiar tipo de instrumento, tipo de servicio, identificadores, estados ni asignaciones.',
        ]);
        $resultado = DB::transaction(function () use ($request, $id, $instrumentoId, $data, $veTodo) {
            $hoja = $this->visibles(DB::table('tb_tareas_tecnicos as t'), $request, $veTodo)
                ->where('t.id', $id)->lockForUpdate()->first(['t.id', 't.estado']);
            abort_unless($hoja, 404, 'Hoja de salida no encontrada.');
            $fila = DB::table('tb_instrumento_tarea')->where('tarea_id', $id)->where('id', $instrumentoId)->lockForUpdate()->first();
            abort_unless($fila, 404, 'Instrumento no encontrado en esta hoja de salida.');
            if ($this->hojaCompletada($hoja->estado)) {
                throw ValidationException::withMessages(['hoja' => 'La hoja está completada: no se pueden editar sus instrumentos.']);
            }
            if (!hash_equals($this->versionInstrumento($fila), $data['version_actual'])) {
                throw ValidationException::withMessages(['version_actual' => 'El instrumento cambió desde la consulta. Consulta nuevamente sus datos y vuelve a confirmar los cambios.']);
            }
            $cambios = []; $valores = [];
            foreach ($data['cambios'] as $campo => $nuevo) {
                $nuevo = $nuevo === '' ? null : $nuevo;
                $anterior = $fila->$campo ?? null;
                if ($anterior === $nuevo) continue;
                $valores[$campo] = $nuevo;
                $cambios[$campo] = ['anterior' => $anterior, 'nuevo' => $nuevo];
            }
            if ($valores !== []) {
                DB::table('tb_instrumento_tarea')->where('tarea_id', $id)->where('id', $instrumentoId)
                    ->update($valores + ['updated_at' => now()]);
            }
            $actual = DB::table('tb_instrumento_tarea')->where('tarea_id', $id)->where('id', $instrumentoId)->first();
            return ['actualizado' => $valores !== [], 'id' => $id, 'instrumento_id' => $instrumentoId,
                'cambios' => $cambios, 'instrumento' => $this->detalleEdicion($request, $hoja, $actual),
                'nota' => 'Se corrigió únicamente la copia de esta hoja. No se modificaron el pendiente original, otros instrumentos, actas ni reportes. Al volver a generar la hoja de salida, su PDF mostrará estos datos.'];
        });
        $this->auditar($request, 'editar_instrumento', ['id' => $id, 'instrumento_id' => $instrumentoId,
            'campos' => array_keys($resultado['cambios']), 'actualizado' => $resultado['actualizado']]);
        return response()->json($resultado);
    }

    private function acceso(Request $request): array
    {
        $usuario = $request->user();
        abort_unless($usuario, 401, 'Debes iniciar sesion para consultar hojas de salida.');
        $roles = $usuario->roles->pluck('name')->all();
        $total = count(array_intersect($roles, config('mcp.roles_acceso_total', ['Gerencia']))) > 0;
        $rolModulo = count(array_intersect($roles, config('mcp.hojas_salida_roles', []))) > 0;
        abort_unless($total || $rolModulo, 403, 'Tu rol no tiene acceso a hojas de salida.');

        $veTodo = $total || count(array_intersect(
            $roles,
            config('mcp.hojas_salida_roles_ve_todo', [])
        )) > 0;

        return [$usuario, $veTodo];
    }

    private function visibles(Builder $query, Request $request, bool $veTodo): Builder
    {
        if (!$veTodo) {
            $query->whereExists(function ($sub) use ($request) {
                $sub->selectRaw('1')
                    ->from('tb_detalles_tarea as dt_scope')
                    ->join('tb_tecnicos_existentes as te_scope', 'dt_scope.id_tecnico', '=', 'te_scope.id')
                    ->whereColumn('dt_scope.id_tarea', 't.id')
                    ->where('te_scope.user_id', $request->user()->id);
            });
        }

        return $query;
    }

    private function base(Request $request, bool $veTodo): Builder
    {
        return $this->visibles(
            DB::table('tb_tareas_tecnicos as t')
                ->leftJoin('clientes as c', 't.cliente_id', '=', 'c.id')
                ->leftJoin('tb_pendientes as p', 't.pendiente_id', '=', 'p.id'),
            $request,
            $veTodo
        );
    }

    private function like(string $texto): string
    {
        return '%' . addcslashes(trim($texto), '\\%_') . '%';
    }

    private function auditar(Request $request, string $accion, array $datos = []): void
    {
        // Sin textos personales ni respuestas completas en los logs.
        Log::info('mcp.hojas_salida', [
            'user_id' => $request->user()->id,
            'accion' => $accion,
            'datos' => $datos,
        ]);
    }

    private function tareaVisible(Request $request, int $id): object
    {
        [, $veTodo] = $this->acceso($request);
        $tarea = $this->base($request, $veTodo)
            ->where('t.id', $id)
            ->first([
                't.id', 't.pendiente_id', 't.cliente_id', 't.fecha', 't.hora_inicio',
                't.estado', 't.tarea', 't.observacion', 't.transporte',
                't.chofer', 't.chofer_id', 't.lugar', 't.created_at',
                'c.razonSocial as cliente_nombre', 'c.numeroDocumento as cliente_documento',
                'c.direccion as cliente_direccion',
                'p.usuario as asesor', 'p.tipo_servicio',
                'p.fecha_programacion as fecha_pactada',
                'p.lugar as lugar_servicio', 'p.lugar_ejecucion', 'p.link_maps',
                'p.contacto as contacto_servicio', 'p.nro_contacto as telefono_servicio',
                'p.descripcion as descripcion_pendiente',
                'p.observaciones_tecnicas as observaciones_tecnicas',
                'p.certificadora_responsable',
            ]);
        abort_unless($tarea, 404, 'Hoja de salida no encontrada.');
        return $tarea;
    }

    private function tecnicosPorTarea(array $tareaIds): array
    {
        if ($tareaIds === []) {
            return [];
        }

        return DB::table('tb_detalles_tarea as dt')
            ->leftJoin('tb_tecnicos_existentes as te', 'dt.id_tecnico', '=', 'te.id')
            ->leftJoin('users as u', 'te.user_id', '=', 'u.id')
            ->whereIn('dt.id_tarea', $tareaIds)
            ->select(
                'dt.id_tarea', 'dt.id_tecnico', 'dt.nombre_tecnico',
                'te.nombre as nombre_registrado',
                'u.name', 'u.apellidopat', 'u.apellidomat'
            )->get()->groupBy('id_tarea')->map(function ($grupo) {
                return $grupo->map(function ($r) {
                    $nombre = trim(implode(' ', array_filter([
                        $r->name, $r->apellidopat, $r->apellidomat,
                    ])));
                    return [
                        'tecnico_id' => $r->id_tecnico,
                        'nombre' => Str::limit($nombre ?: ($r->nombre_registrado ?: $r->nombre_tecnico), 120),
                    ];
                })->values()->all();
            })->all();
    }

    private function aplicarFiltros(Builder $q, array $v): void
    {
        if (!empty($v['cliente'])) {
            $q->where('c.razonSocial', 'LIKE', $this->like($v['cliente']));
        }
        if (!empty($v['estado'])) {
            $q->where('t.estado', $v['estado']);
        }
        if (isset($v['pendiente_id'])) {
            $q->where('t.pendiente_id', $v['pendiente_id']);
        }
        if (isset($v['tecnico_id'])) {
            $q->whereExists(function ($sub) use ($v) {
                $sub->selectRaw('1')->from('tb_detalles_tarea as dt_filter')
                    ->whereColumn('dt_filter.id_tarea', 't.id')
                    ->where('dt_filter.id_tecnico', $v['tecnico_id']);
            });
        }
    }

    public function buscar(Request $request)
    {
        [, $veTodo] = $this->acceso($request);
        $v = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'tipo_fecha' => ['nullable', Rule::in(['programado', 'registro'])],
            'cliente' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(config('mcp.hojas_salida_estados', ['pendiente', 'en_proceso', 'completada']))],
            'tecnico_id' => ['nullable', 'integer', 'min:1'],
            'pendiente_id' => ['nullable', 'integer', 'min:1'],
            'pagina' => ['nullable', 'integer', 'min:1'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $hoy = CarbonImmutable::now('America/Lima')->toDateString();
        $desde = $v['desde'] ?? CarbonImmutable::now('America/Lima')->subDays(7)->toDateString();
        $hasta = $v['hasta'] ?? $hoy;
        $inicio = CarbonImmutable::parse($desde, 'America/Lima');
        $fin = CarbonImmutable::parse($hasta, 'America/Lima');
        if ($fin->lt($inicio) || $inicio->diffInDays($fin) > (int) config('mcp.hojas_salida_max_dias', 93)) {
            throw ValidationException::withMessages([
                'desde' => 'El rango de fechas es invalido o excede el maximo permitido.',
            ]);
        }
        $pagina = (int) ($v['pagina'] ?? 1);
        $limite = (int) ($v['limite'] ?? 10);
        $tipoFecha = $v['tipo_fecha'] ?? 'programado';
        $columna = $tipoFecha === 'registro' ? 't.created_at' : 't.fecha';
        $q = $this->base($request, $veTodo)
            ->whereDate($columna, '>=', $desde)
            ->whereDate($columna, '<=', $hasta);

        $this->aplicarFiltros($q, $v);

        $total = (clone $q)->count('t.id');
        $filas = $q->orderBy('t.fecha')->orderBy('t.hora_inicio')->orderBy('t.id')
            ->forPage($pagina, $limite)
            ->get([
                't.id', 't.pendiente_id', 't.cliente_id', 't.fecha', 't.hora_inicio',
                't.estado', 't.tarea', 't.transporte', 't.chofer', 't.lugar',
                'c.razonSocial as cliente', 'p.usuario as asesor',
                'p.tipo_servicio',
            ]);

        $copias = DB::table('tb_instrumento_tarea')->whereIn('tarea_id', $filas->pluck('id'))->orderBy('id')->get()->groupBy('tarea_id');
        $filas = $filas->map(fn ($r) => $this->consolidarTarea($r, $copias->get($r->id, collect())));
        $tecnicos = $this->tecnicosPorTarea($filas->pluck('id')->all());
        $data = $filas->map(function ($r) use ($tecnicos) {
            return [
                'id' => $r->id,
                'pendiente_id' => $r->pendiente_id,
                'cliente_id' => $r->cliente_id,
                'fecha' => $r->fecha,
                'hora_inicio' => $r->hora_inicio,
                'cliente' => Str::limit($r->cliente ?? '', 180),
                'tipo_servicio' => Str::limit($r->tipo_servicio ?? '', 100),
                'tarea' => Str::limit($r->tarea ?? '', 250),
                'asesor' => Str::limit($r->asesor ?? '', 120),
                'transporte' => Str::limit($r->transporte ?? '', 90),
                'chofer' => Str::limit($r->chofer ?? '', 100),
                'ciudad' => Str::limit($r->lugar ?? '', 120),
                'estado' => $r->estado,
                'tecnicos' => $tecnicos[$r->id] ?? [],
            ];
        })->all();

        $this->auditar($request, 'buscar', ['desde' => $desde, 'hasta' => $hasta, 'tipo_fecha' => $tipoFecha, 'pagina' => $pagina]);
        return response()->json([
            'alcance' => $veTodo ? 'todos' : 'solo_asignados',
            'tipo_fecha' => $tipoFecha,
            'desde' => $desde,
            'hasta' => $hasta,
            'pagina' => $pagina,
            'limite' => $limite,
            'total' => $total,
            'hay_mas' => ($pagina * $limite) < $total,
            'data' => $data,
        ]);
    }

    /** Misma fuente histórica que ShowDetalleTarea e Index.vue; nunca reconstruir desde el pendiente. */
    private function instrumentosDeTarea(int $tareaId): array
    {
        $filas = app(AsignacionInstrumentosTarea::class)->instrumentosDeTarea($tareaId);
        $data = $filas->map(function ($r) use ($tareaId) {
            return [
                'id' => $r->id,
                'id_lote' => $r->id_lote ?? null,
                'tarea_id' => $tareaId,
                'tipo_instrumento' => Str::limit($r->tipo_instrumento ?? '', 130),
                'marca' => Str::limit($r->marca ?? '', 100),
                'modelo' => Str::limit($r->modelo ?? '', 100),
                'serie' => Str::limit($r->serie ?? '', 120),
                'codigo_int' => Str::limit($r->codigo_int ?? '', 80),
                'capacidad_rango' => Str::limit($r->capacidad_rango ?? '', 220),
                'resolucion_division' => Str::limit($r->resolucion_division ?? '', 180),
                'clase_tipo_metrologico' => Str::limit($r->clase_tipo_metrologico ?? '', 120),
                'clase_exactitud' => Str::limit($r->clase_exactitud ?? '', 120),
                'fecha_calibracion' => $r->fecha_calibracion ?? null,
                'hora_servicio' => $r->hora_servicio ?? null,
                'material' => Str::limit($r->material ?? '', 120),
                'observaciones' => Str::limit($r->observaciones ?? '', 500),
                'tipo_servicio' => Str::limit($r->tipo_servicio ?? '', 120),
                'n_cotizacion' => Str::limit((string) ($r->n_cotizacion ?? ''), 120),
                // Compatibilidad: solo los datos guardados en la copia, sin enlazar IDs de otra tabla.
                'servicios' => empty($r->tipo_servicio) && empty($r->n_cotizacion) ? [] : [[
                    'servicio_id' => null,
                    'tipo_servicio' => Str::limit($r->tipo_servicio ?? '', 120),
                    'n_cotizacion' => $r->n_cotizacion ?? null,
                ]],
            ];
        })->values()->all();
        return [
            'fuente' => 'copia_especifica_tarea',
            'total_instrumentos' => count($data),
            'data' => $data,
            'advertencias' => $data === []
                ? ['Esta tarea no tiene instrumentos guardados. No se reconstruyen desde el pendiente ni se puede generar el PDF.'] : [],
        ];
    }

    /** Replica la consolidación del listado web usando únicamente las copias de cada tarea. */
    private function consolidarTarea(object $tarea, Collection $instrumentos): object
    {
        $tipos = $instrumentos->pluck('tipo_servicio')->filter()->unique()->values();
        if ($tipos->isNotEmpty()) {
            $tarea->tipo_servicio = $tipos->count() > 1 ? 'Varios' : $tipos->first();
        }
        if (trim((string) $tarea->tarea) === '' && $instrumentos->isNotEmpty()) {
            $tarea->tarea = $instrumentos->groupBy('tipo_instrumento')->map(function ($items, $tipo) {
                $cantidad = $items->groupBy(fn ($i) => $i->instrumento_id ?? $i->id_lote ?? $i->id)->count();
                $nombre = match ($tipo) { 'Pesas_patron' => 'Pesas patrón', 'Termometro' => 'Termómetro', default => $tipo };
                return "{$cantidad} {$nombre}" . ($cantidad > 1 ? 's' : '');
            })->values()->implode(', ');
        }
        return $tarea;
    }

    public function ver(Request $request, int $id)
    {
        $r = $this->tareaVisible($request, $id);
        $r = $this->consolidarTarea($r, app(AsignacionInstrumentosTarea::class)->instrumentosDeTarea($id));
        $tecnicos = $this->tecnicosPorTarea([$id]);
        $chofer = null;
        if ($r->chofer_id) {
            $choferR = DB::table('tb_chofer_existentes as te')
                ->leftJoin('users as u', 'te.user_id', '=', 'u.id')
                ->where('te.id', $r->chofer_id)
                ->first([
                    'te.id', 'te.nom_chofer', 'u.name', 'u.apellidopat', 'u.apellidomat',
                ]);
            if ($choferR) {
                $chofer = trim(implode(' ', array_filter([
                    $choferR->name, $choferR->apellidopat, $choferR->apellidomat,
                ]))) ?: $choferR->nom_chofer;
            }
        }
        $nombreContacto = $r->contacto_servicio;
        $telefonoContacto = $r->telefono_servicio;
        $cargo = null;
        if ($nombreContacto) {
            $contacto = DB::table('datos')
                ->where('cliente_id', $r->cliente_id)
                ->whereNull('deleted_at')
                ->where('nombre', $nombreContacto)
                ->orderBy('id')->first(['cargo', 'telefono']);
            $telefonoContacto = $telefonoContacto ?: ($contacto->telefono ?? null);
            $cargo = $contacto->cargo ?? null;
        }
        $instrumentos = $this->instrumentosDeTarea($id);
        $reporteId = DB::table('reportes')->where('tarea_id', $id)->min('id');
        $this->auditar($request, 'ver', ['id' => $id]);

        return response()->json([
            'id' => $r->id,
            'numero_hoja_salida' => $r->id,
            'pendiente_id' => $r->pendiente_id,
            'fecha_ejecucion' => $r->fecha,
            'hora_inicio' => $r->hora_inicio,
            'estado' => $r->estado,
            'descripcion_tarea' => Str::limit($r->tarea ?? '', 1000),
            'indicaciones' => Str::limit($r->observacion ?? '', 1000),
            'cliente' => [
                'id' => $r->cliente_id,
                'razon_social' => Str::limit($r->cliente_nombre ?? '', 200),
                'numero_documento' => $r->cliente_documento,
                'direccion' => Str::limit($r->cliente_direccion ?: $r->lugar_servicio ?: '', 300),
                'contacto' => Str::limit($nombreContacto ?? '', 150),
                'cargo_contacto' => Str::limit($cargo ?? '', 100),
                'telefono_contacto' => Str::limit($telefonoContacto ?? '', 60),
            ],
            'servicio' => [
                'tipo_servicio' => Str::limit($r->tipo_servicio ?? '', 120),
                'descripcion_pendiente' => Str::limit($r->descripcion_pendiente ?? '', 700),
                'observaciones_tecnicas' => Str::limit($r->observaciones_tecnicas ?? '', 500),
                'certificadora_responsable' => Str::limit($r->certificadora_responsable ?? '', 120),
                'fecha_pactada' => $r->fecha_pactada,
                'lugar_ejecucion' => Str::limit($r->lugar_ejecucion ?: $r->lugar ?: '', 200),
                'ubicacion_maps' => $r->link_maps,
            ],
            'asesor' => Str::limit($r->asesor ?? '', 120),
            'logistica' => [
                'transporte' => Str::limit($r->transporte ?? '', 120),
                'chofer' => Str::limit($chofer ?: $r->chofer ?: '', 130),
                'tecnicos' => $tecnicos[$id] ?? [],
            ],
            'reporte_id' => $reporteId,
            'instrumentos' => [
                'fuente' => $instrumentos['fuente'],
                'total_instrumentos' => $instrumentos['total_instrumentos'],
                'data' => array_slice($instrumentos['data'], 0, 20),
                'pagina' => 1,
                'limite' => 20,
                'siguiente_pagina' => $instrumentos['total_instrumentos'] > 20 ? 2 : null,
                'hay_mas' => $instrumentos['total_instrumentos'] > 20,
                'advertencias' => $instrumentos['advertencias'],
            ],
            'acciones_permitidas' => $instrumentos['total_instrumentos'] > 0 && $request->user()->roles->pluck('name')->intersect(array_merge(
                config('mcp.roles_acceso_total', ['Gerencia']), config('mcp.hojas_salida_pdf_roles', [])
            ))->isNotEmpty() ? array_merge(['consultar', 'generar_pdf'], $this->puedeEditarInstrumentos($request) && !$this->hojaCompletada($r->estado) ? ['editar_instrumentos'] : []) : ['consultar'],
            'motivo_restriccion' => $this->hojaCompletada($r->estado) ? 'La hoja está completada: no admite edición de instrumentos.' : 'Solo se editan datos de las copias de instrumentos; no tipos, asignaciones, estados ni documentos oficiales.',
        ]);
    }

    /** Permite consultar todos los instrumentos sin saturar la respuesta de detalle. */
    public function instrumentos(Request $request, int $id)
    {
        [, $veTodo] = $this->acceso($request);
        $hoja = $this->visibles(DB::table('tb_tareas_tecnicos as t'), $request, $veTodo)->where('t.id', $id)->first(['t.id', 't.estado']);
        abort_unless($hoja, 404, 'Hoja de salida no encontrada.');
        $v = $request->validate([
            'instrumento_id' => ['nullable', 'integer', 'min:1'],
            'pagina' => ['nullable', 'integer', 'min:1'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        if (isset($v['instrumento_id'])) {
            $fila = DB::table('tb_instrumento_tarea')->where('tarea_id', $id)->where('id', $v['instrumento_id'])->first();
            abort_unless($fila, 404, 'Instrumento no encontrado en esta hoja de salida.');
            $this->auditar($request, 'ver_instrumento', ['id' => $id, 'instrumento_id' => $fila->id]);
            return response()->json(['id' => $id, 'fuente' => 'copia_especifica_tarea',
                'detalle_completo' => true, 'pagina' => 1, 'limite' => 1, 'total' => 1,
                'hay_mas' => false, 'siguiente_pagina' => null, 'advertencias' => [],
                'data' => [$this->detalleEdicion($request, $hoja, $fila)]]);
        }
        $pagina = (int) ($v['pagina'] ?? 1);
        $limite = (int) ($v['limite'] ?? 20);
        $resultado = $this->instrumentosDeTarea($id);
        $total = count($resultado['data']);
        $this->auditar($request, 'instrumentos', ['id' => $id, 'pagina' => $pagina]);
        return response()->json([
            'id' => $id,
            'fuente' => $resultado['fuente'],
            'pagina' => $pagina,
            'limite' => $limite,
            'total' => $total,
            'hay_mas' => $pagina * $limite < $total,
            'siguiente_pagina' => $pagina * $limite < $total ? $pagina + 1 : null,
            'advertencias' => $resultado['advertencias'],
            'data' => array_slice($resultado['data'], ($pagina - 1) * $limite, $limite),
        ]);
    }

    public function resumen(Request $request)
    {
        [, $veTodo] = $this->acceso($request);
        $v = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
            'tipo_fecha' => ['nullable', Rule::in(['programado', 'registro'])],
            'cliente' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(config('mcp.hojas_salida_estados', ['pendiente', 'en_proceso', 'completada']))],
            'tecnico_id' => ['nullable', 'integer', 'min:1'],
            'pendiente_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $desde = CarbonImmutable::parse($v['desde'], 'America/Lima');
        $hasta = CarbonImmutable::parse($v['hasta'], 'America/Lima');
        abort_if($hasta->lt($desde) || $desde->diffInDays($hasta) > (int) config('mcp.hojas_salida_max_dias', 93), 422, 'Rango de fechas invalido.');
        $tipo = $v['tipo_fecha'] ?? 'programado';
        $col = $tipo === 'registro' ? 't.created_at' : 't.fecha';
        $q = $this->base($request, $veTodo)
            ->whereDate($col, '>=', $v['desde'])
            ->whereDate($col, '<=', $v['hasta']);
        $this->aplicarFiltros($q, $v);
        $porEstado = (clone $q)->select('t.estado', DB::raw('COUNT(*) as total'))
            ->groupBy('t.estado')->orderBy('t.estado')->get();
        $total = (clone $q)->count('t.id');
        $this->auditar($request, 'resumen', ['desde' => $v['desde'], 'hasta' => $v['hasta'], 'tipo_fecha' => $tipo]);
        return response()->json([
            'alcance' => $veTodo ? 'todos' : 'solo_asignados',
            'tipo_fecha' => $tipo,
            'desde' => $v['desde'],
            'hasta' => $v['hasta'],
            'total' => $total,
            'por_estado' => $porEstado,
        ]);
    }

    public function enlacePdf(Request $request, int $id)
    {
        $this->tareaVisible($request, $id);
        abort_unless(config('mcp.hojas_salida_habilitar_enlace_pdf', false), 422, 'La vista previa web de hojas de salida esta deshabilitada.');
        $ruta = config('mcp.hojas_salida_ruta_listado_web');
        abort_unless(is_string($ruta) && $ruta !== '' && Route::has($ruta), 422, 'Falta configurar la ruta web del listado de hojas de salida.');
        $vencimiento = now()->addMinutes(10);
        $url = URL::temporarySignedRoute('mcp.hojas_salida.abrir_pdf', $vencimiento, ['id' => $id]);
        $this->auditar($request, 'enlace_pdf', ['id' => $id]);
        return response()->json([
            'id' => $id,
            'modo' => 'vista_previa_generada_por_navegador',
            'url' => $url,
            'vence_en' => $vencimiento->toIso8601String(),
            'nota' => 'Se requiere sesion web del empleado. Esta URL abre la pantalla Vue existente, que genera el PDF con jsPDF. No es un PDF generado por el backend ni una descarga directa desde MCP. No compartir el enlace.',
        ]);
    }

    public function abrirPdf(Request $request, int $id)
    {
        // Protegido por auth web + signed. Revalida asignacion/permisos en cada acceso.
        $this->tareaVisible($request, $id);
        abort_unless(config('mcp.hojas_salida_habilitar_enlace_pdf', false), 404);
        $ruta = config('mcp.hojas_salida_ruta_listado_web');
        abort_unless(is_string($ruta) && $ruta !== '' && Route::has($ruta), 404);
        $this->auditar($request, 'abrir_pdf', ['id' => $id]);
        $respuesta = redirect()->route($ruta, [
            'buscar' => $id,
            'action' => 'pdf',
            'preview' => 1,
        ]);
        $respuesta->headers->set('Cache-Control', 'private, no-store');
        return $respuesta;
    }
}
