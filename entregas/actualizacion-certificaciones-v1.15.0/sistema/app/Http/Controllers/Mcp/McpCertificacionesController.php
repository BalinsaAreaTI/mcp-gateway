<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Lecturas de SolicitudCertificacionController y PorVencer.vue.
 * La pantalla no limita por asesor: todos los roles autorizados ven todos los activos.
 * Se refuerza canAccess en servidor, incluso para usuarios con varios roles.
 * No sirve ni genera certificados oficiales; archivos devuelve solo metadatos.
 */
class McpCertificacionesController extends Controller
{
    protected const CATALOGOS = [
        'marcas' => ['tb_marcas_certificacion', 'idMarcaCert', 'nombreMarcaCert', 'tipoInstrumento'],
        'modelos' => ['tbmodelo_pendientes', 'id', 'nombre_modelo', 'tipo_instrumento'],
        'escalas' => ['tb_escala', 'idEscala', 'nombreDiv'],
        'capacidad_escala' => ['tb_capacidad_escala', 'id', 'capacidad'],
        'procedencias_balanza' => ['tb_procedencia_balanza', 'id', 'nombre'],
        'procedencias_termometro' => ['tb_procedencia_termometro', 'id', 'nombre'],
        'valores_nominales' => ['tb_pesa_valor_nominal', 'id', 'nombre'],
        'clases_exactitud' => ['tb_pesa_clase_exactitud', 'id', 'nombre'],
        'elementos_sensor' => ['tb_termometro_elemento_sensor', 'id', 'nombre'],
    ];

    protected function acceso(Request $request): void
    {
        $roles = $request->user()->roles->pluck('name')->all();
        $permitidos = array_merge(config('mcp.certificaciones_roles', []), config('mcp.roles_acceso_total', ['Gerencia']));
        abort_if(array_intersect($roles, config('mcp.certificaciones_roles_bloqueados', ['Servicio Tecnico', 'Area de Almacen'])), 403, 'Tu rol no tiene acceso a certificaciones.');
        abort_unless(array_intersect($roles, $permitidos), 403, 'Tu rol no tiene acceso a certificaciones.');
    }

    protected function auditar(Request $request, string $accion, array $contexto = []): void
    {
        Log::info('mcp.certificaciones', ['user_id' => $request->user()->id, 'accion' => $accion, 'contexto' => $contexto]);
    }

    protected function paginacion(): array
    {
        return ['pagina' => ['nullable', 'integer', 'min:1'], 'limite' => ['nullable', 'integer', 'min:1', 'max:20']];
    }

    protected function limpiar($value)
    {
        if (is_string($value)) {
            return Str::limit($value, 1000);
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            return array_map(fn ($v) => $this->limpiar($v), $value);
        }

        return $value;
    }

    protected function lista($query, array $data, Request $request, string $accion)
    {
        $pagina = (int) ($data['pagina'] ?? 1);
        $limite = (int) ($data['limite'] ?? 10);
        $cargar = fn () => [(clone $query)->count(), (clone $query)->forPage($pagina, $limite)->get()];
        [$total, $rows] = $cargar();
        if ($accion === 'por_vencer') {
            $hoy = CarbonImmutable::now('America/Lima')->startOfDay();
            foreach ($rows as $row) {
                $row->dias_restantes = (int) $hoy->diffInDays(CarbonImmutable::parse($row->fecha_caducidad, 'America/Lima'), false);
            }
        }
        $this->auditar($request, $accion);

        return response()->json(['alcance' => 'todos_activos', 'pagina' => $pagina, 'limite' => $limite,
            'total' => $total, 'hay_mas' => $pagina * $limite < $total,
            'aviso' => 'Textos limitados a 1000 caracteres. Los archivos son metadatos, no enlaces de descarga.',
            'textos_recortados' => json_encode($rows->all()) !== json_encode($this->limpiar($rows->all())),
            'data' => $this->limpiar($rows->all())]);
    }

    private function fechaSql(string $campo): string
    {
        // Campos fijos internos: nunca se interpola un identificador enviado por el agente.
        return "CASE WHEN $campo IS NULL OR $campo = '' THEN NULL WHEN $campo LIKE '%/%' THEN DATE_FORMAT(STR_TO_DATE($campo, '%d/%m/%Y'), '%Y-%m-%d') ELSE DATE_FORMAT($campo, '%Y-%m-%d') END";
    }

    private function base()
    {
        return DB::table('tb_certificado as a')->join('clientes as b', 'b.id', '=', 'a.idCliente')
            ->where('a.estado_soli', 1);
    }

    private function seleccion($query)
    {
        return $query->select(['a.idCertificado as id', 'a.idCliente as cliente_id', 'b.razonSocial as cliente',
            'b.numeroDocumento as documento', 'a.direccionCertificado as direccion', 'a.created_at as creado_en',
            'a.tiempoCaducidad as tiempo_caducidad'])
            ->selectRaw($this->fechaSql('a.fechaSolicitud').' as fecha_solicitud')
            ->selectRaw($this->fechaSql('a.fechaCaducidad').' as fecha_caducidad')
            ->selectRaw('(SELECT COUNT(*) FROM tb_certificado_archivo f WHERE f.id_certificado = a.idCertificado) as cantidad_archivos')
            ->selectRaw("CASE WHEN EXISTS (SELECT 1 FROM tb_certificado ch WHERE ch.parent_solicitud_id = a.idCertificado) THEN 'Pendiente' ELSE 'Sin atender' END as estado_renovacion");
    }

    protected function registro(Request $request, int $id)
    {
        $this->acceso($request);
        $row = $this->seleccion($this->base()->where('a.idCertificado', $id))
            ->addSelect(['a.contactoCertificado as contacto', 'a.telefonoContactoCertificado as telefono'])->first();
        abort_unless($row, 404, 'Solicitud no encontrada.');

        return $row;
    }

    private function filtrosLista(Request $request): array
    {
        return $request->validate($this->paginacion() + [
            'cliente' => ['nullable', 'string', 'max:100'], 'cliente_id' => ['nullable', 'integer', 'min:1'],
            'desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('desde') ? ['after_or_equal:desde'] : []),
            'estado_archivos' => ['nullable', Rule::in(['Pendiente', 'Recibido'])],
            'documento' => ['nullable', 'string', 'max:30'], 'direccion' => ['nullable', 'string', 'max:500'],
            'fecha_solicitud' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    private function aplicar($query, array $data)
    {
        foreach (['documento' => 'b.numeroDocumento', 'direccion' => 'a.direccionCertificado'] as $key => $column) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $query->where($column, 'like', '%'.addcslashes($data[$key], '\\%_').'%');
            }
        }
        if (! empty($data['fecha_solicitud'])) {
            $query->whereRaw($this->fechaSql('a.fechaSolicitud').' = ?', [$data['fecha_solicitud']]);
        }
        if (! empty($data['cliente'])) {
            $query->where('b.razonSocial', 'like', '%'.addcslashes($data['cliente'], '\\%_').'%');
        }
        if (! empty($data['cliente_id'])) {
            $query->where('a.idCliente', $data['cliente_id']);
        }
        if (! empty($data['desde'])) {
            $query->where('a.created_at', '>=', $data['desde'].' 00:00:00');
        }
        if (! empty($data['hasta'])) {
            $query->where('a.created_at', '<', CarbonImmutable::parse($data['hasta'])->addDay()->toDateString());
        }
        if (! empty($data['estado_archivos'])) {
            $sql = 'EXISTS (SELECT 1 FROM tb_certificado_archivo f WHERE f.id_certificado = a.idCertificado)';
            $query->whereRaw(($data['estado_archivos'] === 'Pendiente' ? 'NOT ' : '').$sql);
        }

        return $query;
    }

    public function buscar(Request $request)
    {
        $this->acceso($request);
        $data = $this->filtrosLista($request);

        return $this->lista($this->seleccion($this->aplicar($this->base(), $data))->orderByDesc('a.idCertificado'), $data, $request, 'buscar');
    }

    public function certificados(Request $request)
    {
        $this->acceso($request);
        $data = $this->filtrosLista($request);
        $hoy = CarbonImmutable::now('America/Lima');
        $data['desde'] = $data['desde'] ?? $hoy->subMonths(2)->toDateString();
        $data['hasta'] = $data['hasta'] ?? $hoy->toDateString();
        validator($data, ['hasta' => 'after_or_equal:desde'])->validate();

        return $this->lista($this->seleccion($this->aplicar($this->base(), $data))->orderByDesc('a.created_at')->orderByDesc('a.idCertificado'), $data, $request, 'certificados');
    }

    public function ver(Request $request, int $id)
    {
        $row = $this->registro($request, $id);
        $slots = 0;
        foreach (DB::table('tb_instrumento')->where('id_certificado', $id)->select('cantidad', 'serie')->cursor() as $i) {
            $series = json_decode($i->serie ?? '[]', true);
            $slots += is_array($series) && count($series) ? count($series) : max((int) $i->cantidad, 1);
        }
        $this->auditar($request, 'ver');

        return response()->json($this->limpiar((array) $row) + ['archivos_esperados' => $slots,
            'archivos_pendientes' => max($slots - $row->cantidad_archivos, 0),
            'aviso' => 'Conteo de archivos como en pantalla; no garantiza cobertura de cada serie. Textos limitados a 1000 caracteres.',
            'version_actual' => $this->version($id),
            'acciones_permitidas' => $this->acciones($request), 'motivo_restriccion' => 'Cada escritura requiere confirmación y la versión consultada; eliminar solo TI.']);
    }

    public function instrumentos(Request $request, int $id)
    {
        $this->registro($request, $id);
        $data = $request->validate($this->paginacion() + ['instrumento_id' => ['nullable', 'integer', 'min:1']]);
        $q = DB::table('tb_instrumento')->where('id_certificado', $id);
        if (! empty($data['instrumento_id'])) {
            $q->where('id_Instrumento', $data['instrumento_id']);
            abort_unless((clone $q)->exists(), 404, 'Instrumento no encontrado.');
        }
        // JSON conservado como texto acotado: no inventar series cuando quede truncado.
        $q->select(['id_Instrumento as id', 'tipoInstrumento as tipo_instrumento', 'cantidad', 'marca', 'modelo',
            'valorNominal as valor_nominal', 'procedencia', 'capacidadMax as capacidad_maxima', 'forma',
            'interbaloDesde as intervalo_desde', 'interbaloHasta as intervalo_hasta', 'resolucion',
            'elementoSensor as elemento_sensor', 'puntosCalibracion as puntos_calibracion_json', 'serie as series_json', 'material', 'clase_exactitud']);

        return $this->lista($q->orderBy('id_Instrumento'), $data, $request, 'instrumentos');
    }

    public function archivos(Request $request, int $id)
    {
        $this->registro($request, $id);
        $data = $request->validate($this->paginacion() + ['instrumento_id' => ['nullable', 'integer', 'min:1']]);
        if (! empty($data['instrumento_id'])) {
            abort_unless(DB::table('tb_instrumento')->where('id_certificado', $id)->where('id_Instrumento', $data['instrumento_id'])->exists(), 404, 'Instrumento no encontrado.');
        }
        $q = DB::table('tb_certificado_archivo')->where('id_certificado', $id);
        if (! empty($data['instrumento_id'])) {
            $q->where('id_instrumento', $data['instrumento_id']);
        }

        return $this->lista($q->select(['id_archivo as id', 'id_instrumento as instrumento_id', 'nombre_original', 'serie', 'created_at as creado_en'])
            ->orderByDesc('id_archivo'), $data, $request, 'archivos');
    }

    public function porVencer(Request $request)
    {
        $this->acceso($request);
        $data = $request->validate($this->paginacion() + ['dias' => ['nullable', 'integer', Rule::in([7, 15, 30])],
            'modo' => ['nullable', Rule::in(['por_vencer', 'caducados'])], 'cliente' => ['nullable', 'string', 'max:100'],
            'cliente_id' => ['nullable', 'integer', 'min:1'], 'direccion' => ['nullable', 'string', 'max:500']]);
        $hoy = CarbonImmutable::now('America/Lima')->startOfDay();
        $caducados = ($data['modo'] ?? 'por_vencer') === 'caducados';
        $desde = $caducados ? $hoy->subDays(7) : $hoy;
        $hasta = $caducados ? $hoy->subDay() : $hoy->addDays((int) ($data['dias'] ?? 30));
        $q = $this->aplicar($this->base(), $data)
            ->whereRaw('EXISTS (SELECT 1 FROM tb_certificado_archivo f WHERE f.id_certificado = a.idCertificado)')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM tb_certificado ch JOIN tb_certificado_archivo f ON f.id_certificado = ch.idCertificado WHERE ch.parent_solicitud_id = a.idCertificado)')
            ->whereBetween(DB::raw($this->fechaSql('a.fechaCaducidad')), [$desde->toDateString(), $hasta->toDateString()]);

        return $this->lista($this->seleccion($q)->orderByRaw($this->fechaSql('a.fechaCaducidad'))->orderBy('a.idCertificado'), $data, $request, 'por_vencer');
    }

    public function filtros(Request $request)
    {
        $this->acceso($request);
        $this->auditar($request, 'filtros');

        return response()->json(['estado_archivos' => ['Pendiente', 'Recibido'], 'estado_renovacion' => ['Sin atender', 'Pendiente'],
            'modos_vencimiento' => ['por_vencer', 'caducados'], 'dias' => [7, 15, 30], 'zona_horaria' => 'America/Lima',
            'tipos_instrumento' => ['Balanza', 'Pesa', 'Termometro'], 'tipos_catalogo' => [1 => 'Balanza', 2 => 'Termometro'], 'formas' => config('mcp.certificaciones_formas', []), 'materiales' => config('mcp.certificaciones_materiales', []), 'catalogos' => array_keys(self::CATALOGOS), 'nota' => 'Recibido significa al menos un archivo; no implica que todos los instrumentos tengan certificado.']);
    }

    public function catalogos(Request $request)
    {
        $this->acceso($request);
        $data = $request->validate($this->paginacion() + ['catalogo' => ['required', Rule::in(array_keys(self::CATALOGOS))],
            'tipo' => ['nullable', 'integer', Rule::in([1, 2])]]);
        [$tabla, $id, $nombre] = self::CATALOGOS[$data['catalogo']];
        $tipo = self::CATALOGOS[$data['catalogo']][3] ?? null;
        $q = DB::table($tabla)->select([$id.' as id', $nombre.' as nombre']);
        if ($tipo) {
            $q->addSelect($tipo.' as tipo');
            if (isset($data['tipo'])) {
                $q->where($tipo, $data['tipo']);
            }
        }
        if ($data['catalogo'] === 'capacidad_escala') {
            $q->addSelect('division');
        }

        return $this->lista($q->orderBy($nombre)->orderBy($id), $data, $request, 'catalogos');
    }

    /** Autocompletado de Create.vue con permisos propios de certificaciones. */
    public function clientes(Request $request)
    {
        $this->acceso($request);
        $data = $request->validate($this->paginacion() + [
            'buscar' => ['nullable', 'string', 'max:100'], 'cliente_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = DB::table('clientes')->join('tbprovincias', 'tbprovincias.id', '=', 'clientes.tbprovincia_id')
            ->select('clientes.id', 'clientes.razonSocial as cliente', 'clientes.numeroDocumento as documento',
                'clientes.direccion', 'clientes.cli_direccion2 as direccion_alternativa');
        if (isset($data['cliente_id'])) {
            $q->where('clientes.id', $data['cliente_id']);
        }
        if (isset($data['buscar']) && $data['buscar'] !== '') {
            $term = '%'.addcslashes($data['buscar'], '\\%_').'%';
            $q->where(fn ($q) => $q->where('clientes.razonSocial', 'like', $term)->orWhere('clientes.numeroDocumento', 'like', $term));
        }

        return $this->lista($q->orderBy('clientes.razonSocial')->orderBy('clientes.id'), $data, $request, 'clientes');
    }

    /** Series paginadas sin truncar el JSON; varios PDF no cubren otras series. */
    public function series(Request $request, int $id, int $instrumentoId)
    {
        $this->registro($request, $id);
        $data = $request->validate($this->paginacion());
        $i = DB::table('tb_instrumento')->where('id_certificado', $id)->where('id_Instrumento', $instrumentoId)->first();
        abort_unless($i, 404, 'Instrumento no encontrado.');
        $series = json_decode($i->serie ?? '[]', true);
        abort_unless(is_array($series) && array_is_list($series), 422, 'Series inválidas; revisar en el sistema.');
        $files = DB::table('tb_certificado_archivo')->where('id_certificado', $id)->where('id_instrumento', $instrumentoId)
            ->select('id_archivo', 'serie')->get();
        $nombre = fn ($s) => is_string($s) ? $s : (is_array($s) ? ($s['num_serie'] ?? $s['num'] ?? $s['serie'] ?? $s['identificacion'] ?? '') : '');
        $nombres = array_map($nombre, $series);
        $filas = [];
        foreach ($series as $index => $s) {
            $serie = $nombres[$index];
            $valid = is_string($serie) && $serie !== '' && mb_strlen($serie) <= 255;
            $ambiguo = ! $valid || count(array_filter($nombres, fn ($n) => $n === $serie)) !== 1;
            $cantidad = $valid ? $files->filter(fn ($f) => (string) ($f->serie ?? '') === $serie)->count() : 0;
            $filas[] = ['numero' => $index + 1, 'serie' => is_string($serie) ? $serie : null,
                'identificacion' => is_array($s) ? ($s['identificacion'] ?? null) : null,
                'ubicacion' => is_array($s) ? ($s['ubicacion'] ?? null) : null,
                'cantidad_archivos' => $cantidad, 'requiere_revision' => $ambiguo,
                'puede_subir' => ! $ambiguo && $cantidad === 0 && $this->puede($request, 'subir')];
        }
        $pagina = (int) ($data['pagina'] ?? 1);
        $limite = (int) ($data['limite'] ?? 10);
        $this->auditar($request, 'series', ['id' => $id, 'instrumento_id' => $instrumentoId]);

        return response()->json(['id' => $id, 'instrumento_id' => $instrumentoId,
            'pagina' => $pagina, 'limite' => $limite, 'total' => count($filas), 'hay_mas' => $pagina * $limite < count($filas),
            'series_pendientes' => count(array_filter($filas, fn ($f) => ! $f['requiere_revision'] && $f['cantidad_archivos'] === 0)),
            'series_ambiguas' => count(array_filter($filas, fn ($f) => $f['requiere_revision'])),
            'sin_series' => count($filas) === 0, 'cantidad_archivos' => $files->count(),
            'aviso' => 'Sin series: la carga usa serie omitida. Cantidad de PDF no equivale a unidades cubiertas. Revisar series ambiguas en el sistema.',
            'data' => $this->limpiar(array_slice($filas, ($pagina - 1) * $limite, $limite))]);
    }

    protected function version(int $id): string
    {
        return hash('sha256', json_encode([
            DB::table('tb_certificado')->where('idCertificado', $id)->first(),
            DB::table('tb_instrumento')->where('id_certificado', $id)->orderBy('id_Instrumento')->get(),
            DB::table('tb_certificado_archivo')->where('id_certificado', $id)->orderBy('id_archivo')->get(),
            DB::table('tb_certificado')->where('parent_solicitud_id', $id)->orderBy('idCertificado')->pluck('idCertificado'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function puede(Request $request, string $accion): bool
    {
        $roles = $request->user()->roles->pluck('name')->all();
        // Index.vue: eliminar exclusivamente Area de TI, sin excepción genérica.
        $permitidos = config('mcp.certificaciones_roles_'.$accion, []);

        return (bool) array_intersect($roles, $permitidos);
    }

    protected function acciones(Request $request): array
    {
        $acciones = ['ver_instrumentos', 'ver_archivos', 'pdf_solicitud'];
        foreach (['editar_fechas', 'renovar', 'subir', 'eliminar'] as $accion) {
            if ($this->puede($request, $accion)) {
                $acciones[] = $accion;
            }
        }

        return $acciones;
    }
}
