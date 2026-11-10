<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\C_Servicio;
use App\Models\Cventa;
use App\Models\TbproductosAgregados;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cotizaciones por venta (cventas) y por servicio (cservicios) para el MCP.
 * Replica las reglas de CventaController y CServicioController sin tocarlos.
 *
 * Reglas propias del MCP (más estrictas que las pantallas):
 *  - Solo se modifican cotizaciones PROPIAS, y solo con rol Asesor de Ventas o Jefe de Ventas.
 *  - Los productos/instrumentos a editar deben pertenecer a la cotización indicada.
 */
class McpCotizacionesController extends Controller
{
    use CotizacionesDesdeInforme;

    // ───────────────────────── Ayudas ─────────────────────────

    private function rolesDe(Request $request): array
    {
        return $request->user()->roles->pluck('name')->all();
    }

    /** Roles con acceso total a todas las herramientas del asistente (config mcp.roles_acceso_total; por defecto Gerencia). */
    private function accesoTotal(array $rolesUsuario): bool
    {
        return count(array_intersect($rolesUsuario, config('mcp.roles_acceso_total', ['Gerencia']))) > 0;
    }

    private function tiene(array $rolesUsuario, array $permitidos): bool
    {
        return $this->accesoTotal($rolesUsuario) || count(array_intersect($rolesUsuario, $permitidos)) > 0;
    }

    private function fallo(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }

    private function like(string $texto): string
    {
        return '%' . addcslashes($texto, '\\%_') . '%';
    }

    private function auditar(Request $request, string $accion, array $params): void
    {
        Log::info('mcp.cotizaciones', [
            'user_id' => $request->user()->id,
            'accion'  => $accion,
            'params'  => $params,
        ]);
    }

    private function normalizar(?string $texto): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $texto)));
    }

    private function nombreCompleto($user): string
    {
        return trim("{$user->name} {$user->apellidopat} {$user->apellidomat}");
    }

    /** Variantes del nombre tal como se guardan en cventas.tecnico / cservicios.asesor. */
    private function variantesNombre($user): array
    {
        $crudo = "{$user->name} {$user->apellidopat} {$user->apellidomat}";

        return array_values(array_unique([$crudo, trim($crudo), trim((string) preg_replace('/\s+/u', ' ', $crudo))]));
    }

    private function rango(?string $desde, ?string $hasta, int $diasPorDefecto, int $maxDias): array
    {
        $hasta = $hasta ?? now()->toDateString();
        $desde = $desde ?? Carbon::parse($hasta)->subDays($diasPorDefecto)->toDateString();

        if ($desde > $hasta) {
            $this->fallo('desde', 'La fecha "desde" no puede ser posterior a "hasta".');
        }
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > $maxDias) {
            $this->fallo('hasta', "El rango máximo es de {$maxDias} días.");
        }

        return [$desde, $hasta];
    }

    /** @return array{0: \App\Models\User, 1: bool, 2: bool} usuario, ve todo, rol que puede editar */
    private function acceso(Request $request): array
    {
        $user = $request->user();
        $roles = $this->rolesDe($request);

        abort_unless(
            $this->tiene($roles, config('mcp.cotizaciones_roles')),
            403,
            'Tu rol no tiene acceso a las cotizaciones.'
        );

        $veTodo = $this->tiene($roles, config('mcp.cotizaciones_roles_ve_todo'))
            || $user->can(config('mcp.cotizaciones_permiso_ver_todo'));

        return [$user, $veTodo, $this->tiene($roles, config('mcp.cotizaciones_roles_edicion'))];
    }

    private function esMiaVenta(object $cv, $user): bool
    {
        return $this->normalizar($cv->tecnico) === $this->normalizar($this->nombreCompleto($user));
    }

    private function esMiaServicio(object $cs, $user): bool
    {
        if ($cs->id_asesor !== null) {
            return (int) $cs->id_asesor === (int) $user->id;
        }

        return $this->normalizar($cs->asesor) === $this->normalizar($this->nombreCompleto($user));
    }

    /**
     * Totales de una cotización por venta (misma fórmula de CventaController::actualizarPrecios).
     * Total incluye IGV; se redondea el precio convertido y luego cada línea, igual que la pantalla.
     *
     * @return array{0: float, 1: float, 2: float} subtotal, igv, total
     */
    private function totalesVenta($productos, ?string $moneda, $tipoCambio): array
    {
        $isDollar = fn ($currency) => preg_match('/\$|usd|d[oó]lar/iu', (string) $currency) === 1;
        $subtotal = 0;
        foreach ($productos as $pa) {
            $precio = (float) $pa->importe;
            if ($isDollar($pa->moneda) !== $isDollar($moneda)) {
                $tc = (float) $tipoCambio;
                if ($tc <= 0) {
                    $this->fallo('tipo_cambio', 'Hay productos en otra moneda: indica un tipo de cambio mayor que cero.');
                }
                $precio = $isDollar($moneda) ? $precio / $tc : $precio * $tc;
            }
            $subtotal += round($pa->cantidad * round($precio, 2), 2);
        }
        $subtotal = round($subtotal, 2);
        $igv = round($subtotal * 0.18, 2);

        return [$subtotal, $igv, round($subtotal + $igv, 2)];
    }

    /** Aceptado, Rechazado y Finalizado son definitivos: no admiten cambio de estado ni ediciones. */
    private function estadoBloqueado(?string $estado): bool
    {
        return in_array($estado, config('mcp.cotizacion_estados_bloqueados', ['Aceptado', 'Rechazado', 'Finalizado']), true);
    }

    private function validarEditable(?string $estado): void
    {
        if ($this->estadoBloqueado($estado)) {
            $this->fallo('estado', "La cotización está en estado {$estado}: es definitiva y ya no admite cambios de estado ni ediciones.");
        }
    }

    private function numero($valor): ?float
    {
        return ($valor === null || $valor === '') ? null : round((float) $valor, 2);
    }

    /** Carga una cotización por venta visible para el usuario; con $paraEditar exige rol y titularidad. */
    private function cotizacionVenta(Request $request, int $id, bool $paraEditar = false): array
    {
        [$user, $veTodo, $puedeEditarRol] = $this->acceso($request);

        $cv = DB::table('cventas as cv')
            ->join('clientes as c', 'cv.cliente_id', '=', 'c.id')
            ->where('cv.id', $id)
            ->first([
                'cv.*',
                'c.razonSocial as cliente',
                'c.nom_comercial as cliente_nombre_comercial',
                'c.numeroDocumento as cliente_documento',
                'c.direccion as cliente_direccion',
            ]);

        abort_if(! $cv || (int) $cv->estado_cot === 0, 404, 'Cotización no encontrada.');

        $mia = $this->esMiaVenta($cv, $user);
        abort_unless($veTodo || $mia, 404, 'Cotización no encontrada.');

        if ($paraEditar) {
            abort_unless($puedeEditarRol, 403, 'Tu rol no puede modificar cotizaciones desde el asistente (solo Asesor de Ventas y Jefe de Ventas).');
            abort_unless($mia || $this->accesoTotal($this->rolesDe($request)), 403, 'Solo puedes modificar tus propias cotizaciones.');
        }

        return [$user, $cv, $mia, $puedeEditarRol];
    }

    private function cotizacionServicio(Request $request, int $id, bool $paraEditar = false): array
    {
        [$user, $veTodo, $puedeEditarRol] = $this->acceso($request);

        $cs = DB::table('cservicios as cs')
            ->leftJoin('clientes as c', 'cs.cliente_id', '=', 'c.id')
            ->where('cs.id', $id)
            ->first([
                'cs.*',
                DB::raw('COALESCE(c.razonSocial, cs.razonSocial) as cliente'),
                'c.nom_comercial as cliente_nombre_comercial',
                'c.numeroDocumento as cliente_documento',
                'c.direccion as cliente_direccion',
            ]);

        abort_if(! $cs || (int) $cs->estado_cotserv === 0, 404, 'Cotización no encontrada.');

        $mia = $this->esMiaServicio($cs, $user);
        abort_unless($veTodo || $mia, 404, 'Cotización no encontrada.');

        if ($paraEditar) {
            abort_unless($puedeEditarRol, 403, 'Tu rol no puede modificar cotizaciones desde el asistente (solo Asesor de Ventas y Jefe de Ventas).');
            abort_unless($mia || $this->accesoTotal($this->rolesDe($request)), 403, 'Solo puedes modificar tus propias cotizaciones.');
        }

        return [$user, $cs, $mia, $puedeEditarRol];
    }

    private function tipoInstrumento($instrumentoId, ?string $nombre): string
    {
        $base = [1 => 'Balanza', 2 => 'Termómetro', 3 => 'Pesa'];

        if ($instrumentoId !== null && isset($base[(int) $instrumentoId])) {
            return $base[(int) $instrumentoId];
        }

        return trim((string) $nombre) !== '' ? Str::limit(trim((string) $nombre), 60) : 'Otro';
    }

    // Mismas validaciones de calidad de datos que CServicioController
    private function esCampoValido($val): bool
    {
        if (empty($val)) {
            return false;
        }
        $trimmed = trim((string) $val);
        if ($trimmed === '') {
            return false;
        }

        $soloAlfanumerico = preg_replace('/[\s\-_.\/,:]+/', '', $trimmed);
        if (empty($soloAlfanumerico)) {
            return false;
        }

        $placeholders = ['noindica', 'sinindica', 'na', 'si', 'sinmodelo', 'sinmarca', 'sincapacidad', 'ninguno', 'ninguna', 'null', 'undefined', 'sm', 'nm', 'sn'];

        return ! in_array(mb_strtolower($soloAlfanumerico), $placeholders);
    }

    private function esCapacidadValidaParaBalanza($val): bool
    {
        if (! $this->esCampoValido($val)) {
            return false;
        }
        $str = trim((string) $val);
        if (! preg_match('/\d/', $str)) {
            return false;
        }

        $soloAlfanumerico = mb_strtolower(preg_replace('/[\s\-_.\/,:]+/', '', $str));

        return ! in_array($soloAlfanumerico, ['kg', 'g', 'gr', 'lb', 'tn', 't', 'oz', 'mg']);
    }

    private function normalizarPrecio($valor): string
    {
        return number_format(is_numeric($valor) ? (float) $valor : 0.0, 2, '.', '');
    }

    // ───────────────────────── Filtros ─────────────────────────

    // GET /api/mcp/ventas/cotizaciones/filtros
    public function filtros(Request $request)
    {
        $this->acceso($request);

        $catalogo = Cache::remember('mcp.cotizaciones.filtros.v2', 600, function () {
            $subs = DB::table('tbsubcategorias')->orderBy('nombre')->get(['id', 'nombre', 'tbcategoria_id'])->groupBy('tbcategoria_id');

            $categorias = DB::table('tbcategorias')->orderBy('nombre')->get(['id', 'nombre'])->map(fn ($c) => [
                'categoria'     => $c->nombre,
                'subcategorias' => ($subs->get($c->id) ?? collect())->pluck('nombre')->values()->all(),
            ])->values()->all();

            $monedas = DB::table('cventas')->whereNotNull('moneda')->distinct()->pluck('moneda')
                ->merge(DB::table('cservicios')->whereNotNull('moneda')->distinct()->pluck('moneda'))
                ->unique()->values()->all();

            // Valores que más se usan hoy, para que las cotizaciones nuevas escriban igual
            $top = fn (string $tabla, string $col) => DB::table($tabla)
                ->whereNotNull($col)->where($col, '!=', '')
                ->select($col, DB::raw('COUNT(*) as n'))->groupBy($col)->orderByDesc('n')->limit(8)->pluck($col)->all();

            return [
                'categorias' => $categorias,
                'monedas'    => $monedas,
                'venta_usados' => [
                    'forma_pago'   => $top('cventas', 'forma_pago'),
                    'dias_entrega' => $top('cventas', 'dias_entrega'),
                    'validez'      => $top('cventas', 'validez_cot'),
                ],
                'servicio_usados' => [
                    'forma_pago'    => $top('cservicios', 'fpago'),
                    'garantia'      => $top('cservicios', 'garantia'),
                    'plazo_entrega' => $top('cservicios', 'plazo_entrega'),
                    'validez'       => $top('cservicios', 'validez'),
                ],
            ];
        });

        return response()->json($catalogo + [
            'estados'     => config('mcp.cotizacion_estados'),
            'estados_definitivos' => config('mcp.cotizacion_estados_bloqueados', ['Aceptado', 'Rechazado', 'Finalizado']),
            'instrumentos' => ['Balanza', 'Termómetro', 'Pesa'],
            'nota'        => 'El filtro "instrumento" también busca por nombre del servicio, marca y modelo.',
        ]);
    }

    // ───────────────────────── Cotización por VENTA ─────────────────────────

    // GET /api/mcp/ventas/cotizaciones/venta
    public function buscarVentas(Request $request)
    {
        [$user, $veTodo, $puedeEditarRol] = $this->acceso($request);

        $data = $request->validate([
            'cliente'      => ['nullable', 'string', 'max:100'],
            'categoria'    => ['nullable', 'string', 'max:100'],
            'subcategoria' => ['nullable', 'string', 'max:100'],
            'estado'       => ['nullable', Rule::in(config('mcp.cotizacion_estados'))],
            'numero'       => ['nullable', 'string', 'max:20'],
            'vendedor'     => ['nullable', 'string', 'max:100'],
            'desde'        => ['nullable', 'date_format:Y-m-d'],
            'hasta'        => ['nullable', 'date_format:Y-m-d'],
            'pagina'       => ['nullable', 'integer', 'min:1'],
            'limite'       => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        [$desde, $hasta] = $this->rango($data['desde'] ?? null, $data['hasta'] ?? null, 90, 1830);
        $pagina = (int) ($data['pagina'] ?? 1);
        $limite = (int) ($data['limite'] ?? 10);
        $avisos = [];

        $query = DB::table('cventas as cv')
            ->join('clientes as c', 'cv.cliente_id', '=', 'c.id')
            ->where('cv.estado_cot', 1)
            ->whereDate('cv.fecha', '>=', $desde)
            ->whereDate('cv.fecha', '<=', $hasta);

        if (! $veTodo) {
            $query->whereIn('cv.tecnico', $this->variantesNombre($user));
        } elseif (! empty($data['vendedor'])) {
            $query->where('cv.tecnico', 'LIKE', $this->like($data['vendedor']));
        }

        if (! empty($data['cliente'])) {
            foreach (array_slice(preg_split('/\s+/', trim($data['cliente'])) ?: [], 0, 5) as $palabra) {
                $like = $this->like($palabra);
                $query->where(function ($w) use ($like) {
                    $w->where('c.razonSocial', 'LIKE', $like)->orWhere('c.nom_comercial', 'LIKE', $like);
                });
            }
        }
        if (! empty($data['estado'])) {
            $query->where('cv.estado', $data['estado']);
        }
        if (! empty($data['numero'])) {
            $query->where('cv.n_cotizacion', 'LIKE', $this->like($data['numero']));
        }

        if (! empty($data['categoria'])) {
            $catIds = DB::table('tbcategorias')->where('nombre', 'LIKE', $this->like($data['categoria']))->pluck('id')->all();
            if (! $catIds) {
                $avisos[] = "No existe una categoría que coincida con \"{$data['categoria']}\". Consulta ventas_filtros_cotizaciones.";
            }
            $query->whereExists(function ($s) use ($catIds) {
                $s->select(DB::raw(1))->from('tbproductos_agregados as pa')
                    ->whereColumn('pa.idCotizacion', 'cv.id')
                    ->where(function ($w) use ($catIds) {
                        $w->whereIn('pa.categoria_id', $catIds)
                          ->orWhereIn('pa.subcategoria_id', function ($sub) use ($catIds) {
                              $sub->select('id')->from('tbsubcategorias')->whereIn('tbcategoria_id', $catIds);
                          });
                    });
            });
        }

        if (! empty($data['subcategoria'])) {
            $subIds = DB::table('tbsubcategorias')->where('nombre', 'LIKE', $this->like($data['subcategoria']))->pluck('id')->all();
            if (! $subIds) {
                $avisos[] = "No existe una subcategoría que coincida con \"{$data['subcategoria']}\". Consulta ventas_filtros_cotizaciones.";
            }
            $query->whereExists(function ($s) use ($subIds) {
                $s->select(DB::raw(1))->from('tbproductos_agregados as pa')
                    ->whereColumn('pa.idCotizacion', 'cv.id')
                    ->whereIn('pa.subcategoria_id', $subIds);
            });
        }

        $total = (clone $query)->count();

        $filas = $query->orderByDesc('cv.id')->forPage($pagina, $limite)->get([
            'cv.id', 'cv.n_cotizacion', 'cv.cliente_id', 'c.razonSocial as cliente', 'cv.fecha', 'cv.estado',
            'cv.tecnico', 'cv.moneda', 'cv.subtotal', 'cv.igv', 'cv.total',
        ]);

        // Categorías y subcategorías de cada cotización (misma resolución que CventaController::index)
        $prods = $filas->isEmpty() ? collect() : DB::table('tbproductos_agregados')
            ->whereIn('idCotizacion', $filas->pluck('id')->all())
            ->get(['idCotizacion', 'categoria_id', 'subcategoria_id']);

        $subs = DB::table('tbsubcategorias')->whereIn('id', $prods->pluck('subcategoria_id')->filter()->unique()->all())
            ->get(['id', 'nombre', 'tbcategoria_id'])->keyBy('id');
        $cats = DB::table('tbcategorias')
            ->whereIn('id', $prods->pluck('categoria_id')->merge($subs->pluck('tbcategoria_id'))->filter()->unique()->all())
            ->pluck('nombre', 'id');
        $prodsPor = $prods->groupBy('idCotizacion');

        $resultado = $filas->map(function ($f) use ($prodsPor, $subs, $cats, $user) {
            $categorias = [];
            $subcategorias = [];
            $lista = $prodsPor->get($f->id, collect());

            foreach ($lista as $p) {
                $catId = $p->categoria_id;
                if (empty($catId) && ! empty($p->subcategoria_id) && isset($subs[$p->subcategoria_id])) {
                    $catId = $subs[$p->subcategoria_id]->tbcategoria_id;
                }
                if (! empty($catId) && isset($cats[$catId])) {
                    $categorias[] = $cats[$catId];
                }
                $subcategorias[] = (! empty($p->subcategoria_id) && isset($subs[$p->subcategoria_id]))
                    ? $subs[$p->subcategoria_id]->nombre : 'Sin subcategoría';
            }

            return [
                'id'            => $f->id,
                'n_cotizacion'  => $f->n_cotizacion,
                'cliente_id'    => $f->cliente_id,
                'cliente'       => $f->cliente,
                'fecha'         => $f->fecha,
                'estado'        => $f->estado,
                'vendedor'      => $f->tecnico,
                'es_mia'        => $this->esMiaVenta($f, $user),
                'moneda'        => $f->moneda,
                'subtotal'      => $this->numero($f->subtotal),
                'igv'           => $this->numero($f->igv),
                'total'         => $this->numero($f->total),
                'productos'     => $lista->count(),
                'categorias'    => array_values(array_unique($categorias)),
                'subcategorias' => array_values(array_unique($subcategorias)),
            ];
        })->values();

        $this->auditar($request, 'buscar_cotizaciones_venta', $data);

        return response()->json([
            'alcance' => $veTodo ? 'todas' : 'solo_propias',
            'rango'   => ['desde' => $desde, 'hasta' => $hasta],
            'pagina'  => $pagina,
            'limite'  => $limite,
            'total'   => $total,
            'hay_mas' => ($pagina * $limite) < $total,
            'avisos'  => $avisos,
            'data'    => $resultado,
        ]);
    }

    // GET /api/mcp/ventas/cotizaciones/venta/{id}
    public function verVenta(Request $request, int $id)
    {
        [$user, $cv, $mia, $puedeEditarRol] = $this->cotizacionVenta($request, $id);

        $productos = DB::table('tbproductos_agregados as ta')
            ->leftJoin('tbsubcategorias as ts', 'ts.id', '=', 'ta.subcategoria_id')
            ->leftJoin('tbcategorias as tc', 'tc.id', '=', DB::raw('COALESCE(ta.categoria_id, ts.tbcategoria_id)'))
            ->where('ta.idCotizacion', $cv->id)
            ->orderBy('ta.id')
            ->limit(200)
            ->get([
                'ta.id', 'tc.nombre as categoria', 'ts.nombre as subcategoria', 'ta.modelo', 'ta.marca',
                'ta.especificaciones', 'ta.capacidades', 'ta.medidas', 'ta.precio_list', 'ta.precio_min', 'ta.precio_max',
                'ta.cantidad', 'ta.importe', 'ta.moneda', 'ta.garantia', 'ta.dias_entrega', 'ta.forma_pago',
                'ta.requerimientos',
            ])
            ->map(fn (object $p) => [
                'id'               => $p->id,
                'categoria'        => $p->categoria,
                'subcategoria'     => $p->subcategoria,
                'modelo'           => $p->modelo,
                'marca'            => $p->marca,
                'especificaciones' => Str::limit((string) $p->especificaciones, 500),
                'capacidades'      => (string) $p->capacidades,
                'medidas'          => (string) $p->medidas,
                'precio_lista'     => $this->numero($p->precio_list),
                'precio_min'       => $this->numero($p->precio_min),
                'precio_max'       => $this->numero($p->precio_max),
                'cantidad'         => $p->cantidad,
                'importe_unitario' => $this->numero($p->importe),
                'moneda_producto'  => $p->moneda,
                'garantia'         => $p->garantia,
                'dias_entrega'     => $p->dias_entrega,
                'forma_pago'       => $p->forma_pago,
                'requerimientos'   => Str::limit((string) $p->requerimientos, 500),
            ])->values();

        $acciones = [];
        $motivo = null;
        if (! $puedeEditarRol) {
            $motivo = 'Tu rol solo puede consultar cotizaciones.';
        } elseif (! $mia && ! $this->accesoTotal($this->rolesDe($request))) {
            $motivo = 'Solo se pueden modificar las cotizaciones propias.';
        } elseif ($this->estadoBloqueado($cv->estado)) {
            $motivo = "Cotización {$cv->estado}: es definitiva y ya no admite cambios.";
        } else {
            $acciones = ['estado', 'precios'];
        }

        $this->auditar($request, 'ver_cotizacion_venta', ['id' => $id]);

        return response()->json([
            'id'            => $cv->id,
            'n_cotizacion'  => $cv->n_cotizacion,
            'estado'        => $cv->estado,
            'fecha'         => $cv->fecha,
            'fecha_registro' => $cv->fecha_registro,
            'vendedor'      => $cv->tecnico,
            'es_mia'        => $mia,
            'cliente'       => [
                'id'               => $cv->cliente_id,
                'razon_social'     => $cv->cliente,
                'nombre_comercial' => $cv->cliente_nombre_comercial,
                'documento'        => $cv->cliente_documento,
                'direccion'        => $cv->cliente_direccion,
            ],
            'moneda'        => $cv->moneda,
            'tipo_cambio'   => $cv->tipoCambio,
            'forma_pago'    => $cv->forma_pago,
            'dias_entrega'  => $cv->dias_entrega,
            'validez'       => $cv->validez_cot,
            'subtotal'      => $this->numero($cv->subtotal),
            'igv'           => $this->numero($cv->igv),
            'total'         => $this->numero($cv->total),
            'total_con_igv_calculado' => round((float) $cv->subtotal + (float) $cv->igv, 2),
            'acciones_permitidas' => $acciones,
            'motivo_restriccion'  => $motivo,
            'productos'     => $productos,
        ]);
    }

    // POST /api/mcp/ventas/cotizaciones/venta/{id}/estado
    public function estadoVenta(Request $request, int $id)
    {
        [$user, $cv] = $this->cotizacionVenta($request, $id, true);

        $data = $request->validate([
            'estado' => ['required', Rule::in(config('mcp.cotizacion_estados'))],
        ]);

        $this->validarEditable($cv->estado);
        $anterior = $cv->estado;

        DB::transaction(function () use ($cv, $data) {
            $cotizacion = Cventa::lockForUpdate()->findOrFail($cv->id);
            $this->validarEditable($cotizacion->estado);

            $cotizacion->estado = $data['estado'];
            $cotizacion->save();
        });

        $this->auditar($request, 'estado_cotizacion_venta', ['id' => $id, 'de' => $anterior, 'a' => $data['estado']]);

        return response()->json([
            'actualizado'     => true,
            'id'              => $cv->id,
            'n_cotizacion'    => $cv->n_cotizacion,
            'estado_anterior' => $anterior,
            'estado_nuevo'    => $data['estado'],
        ]);
    }

    // POST /api/mcp/ventas/cotizaciones/venta/{id}/precios
    public function preciosVenta(Request $request, int $id)
    {
        [$user, $cv] = $this->cotizacionVenta($request, $id, true);

        $data = $request->validate([
            'productos'            => ['required', 'array', 'min:1', 'max:100'],
            'productos.*.id'       => ['required', 'integer'],
            'productos.*.importe'  => ['required', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $this->validarEditable($cv->estado);

        $ids = collect($data['productos'])->pluck('id')->map(fn ($v) => (int) $v);
        if ($ids->duplicates()->isNotEmpty()) {
            $this->fallo('productos', 'Hay productos repetidos en la lista.');
        }

        $propios = DB::table('tbproductos_agregados')->where('idCotizacion', $cv->id)->pluck('id')->map(fn ($v) => (int) $v);
        $ajenos = $ids->diff($propios);
        if ($ajenos->isNotEmpty()) {
            $this->fallo('productos', 'Estos productos no pertenecen a la cotización ' . $cv->n_cotizacion . ': ' . $ajenos->implode(', ') . '.');
        }

        $advertencias = [];
        $cambios = [];

        $resultado = DB::transaction(function () use ($cv, $data, &$advertencias, &$cambios) {
            $cotizacion = Cventa::lockForUpdate()->findOrFail($cv->id);

            $this->validarEditable($cotizacion->estado);
            if ((int) $cotizacion->estado_cot === 0) {
                $this->fallo('estado', 'No se pueden editar los precios de una cotización eliminada.');
            }

            foreach ($data['productos'] as $p) {
                $producto = TbproductosAgregados::findOrFail((int) $p['id']);
                $nuevo = round((float) $p['importe'], 2);
                $antes = $this->numero($producto->importe);

                $producto->importe = $nuevo;
                $producto->save();

                $cambios[] = ['id' => $producto->id, 'modelo' => $producto->modelo, 'importe_anterior' => $antes, 'importe_nuevo' => $nuevo];

                if (is_numeric($producto->precio_min) && (float) $producto->precio_min > 0 && $nuevo < (float) $producto->precio_min) {
                    $advertencias[] = "Producto {$producto->id} ({$producto->modelo}): {$nuevo} está por debajo del precio mínimo {$producto->precio_min}.";
                }
                if (is_numeric($producto->precio_max) && (float) $producto->precio_max > 0 && $nuevo > (float) $producto->precio_max) {
                    $advertencias[] = "Producto {$producto->id} ({$producto->modelo}): {$nuevo} supera el precio máximo {$producto->precio_max}.";
                }
            }

            // Recalcular totales: misma fórmula de CventaController::actualizarPrecios
            $productosAgregados = TbproductosAgregados::where('idCotizacion', $cotizacion->id)->get();
            [$subtotal, $igv, $total] = $this->totalesVenta($productosAgregados, $cotizacion->moneda, $cotizacion->tipoCambio);

            $cotizacion->subtotal = $subtotal;
            $cotizacion->igv = $igv;
            $cotizacion->total = $total;
            $cotizacion->save();

            return $cotizacion;
        });

        $this->auditar($request, 'precios_cotizacion_venta', ['id' => $id, 'cambios' => $cambios]);

        return response()->json([
            'actualizado'  => true,
            'id'           => $resultado->id,
            'n_cotizacion' => $resultado->n_cotizacion,
            'moneda'       => $resultado->moneda,
            'subtotal'     => $this->numero($resultado->subtotal),
            'igv'          => $this->numero($resultado->igv),
            'total'        => $this->numero($resultado->total),
            'total_con_igv_calculado' => round((float) $resultado->subtotal + (float) $resultado->igv, 2),
            'cambios'      => $cambios,
            'advertencias' => $advertencias,
        ]);
    }

    // ───────────────────────── Cotización por SERVICIO ─────────────────────────

    // GET /api/mcp/ventas/cotizaciones/servicio
    public function buscarServicios(Request $request)
    {
        [$user, $veTodo] = $this->acceso($request);

        $data = $request->validate([
            'cliente'     => ['nullable', 'string', 'max:100'],
            'estado'      => ['nullable', Rule::in(config('mcp.cotizacion_estados'))],
            'instrumento' => ['nullable', 'string', 'max:100'],
            'numero'      => ['nullable', 'string', 'max:20'],
            'vendedor'    => ['nullable', 'string', 'max:100'],
            'desde'       => ['nullable', 'date_format:Y-m-d'],
            'hasta'       => ['nullable', 'date_format:Y-m-d'],
            'pagina'      => ['nullable', 'integer', 'min:1'],
            'limite'      => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        [$desde, $hasta] = $this->rango($data['desde'] ?? null, $data['hasta'] ?? null, 90, 1830);
        $pagina = (int) ($data['pagina'] ?? 1);
        $limite = (int) ($data['limite'] ?? 10);

        $query = DB::table('cservicios as cs')
            ->leftJoin('clientes as c', 'cs.cliente_id', '=', 'c.id')
            ->leftJoin('users as u', 'u.id', '=', 'cs.id_asesor')
            ->where('cs.estado_cotserv', 1)
            ->whereRaw('LEFT(cs.fecha, 10) >= ?', [$desde])
            ->whereRaw('LEFT(cs.fecha, 10) <= ?', [$hasta]);

        if (! $veTodo) {
            $nombres = $this->variantesNombre($user);
            $query->where(function ($w) use ($user, $nombres) {
                $w->where('cs.id_asesor', $user->id)
                  ->orWhere(function ($x) use ($nombres) {
                      $x->whereNull('cs.id_asesor')->whereIn('cs.asesor', $nombres);
                  });
            });
        } elseif (! empty($data['vendedor'])) {
            $like = $this->like($data['vendedor']);
            $query->where(function ($w) use ($like) {
                $w->where('cs.asesor', 'LIKE', $like)
                  ->orWhereRaw("CONCAT_WS(' ', u.name, u.apellidopat, u.apellidomat) LIKE ?", [$like]);
            });
        }

        if (! empty($data['cliente'])) {
            foreach (array_slice(preg_split('/\s+/', trim($data['cliente'])) ?: [], 0, 5) as $palabra) {
                $like = $this->like($palabra);
                $query->where(function ($w) use ($like) {
                    $w->whereRaw('COALESCE(c.razonSocial, cs.razonSocial) LIKE ?', [$like])
                      ->orWhere('c.nom_comercial', 'LIKE', $like);
                });
            }
        }
        if (! empty($data['estado'])) {
            $query->where('cs.estado_servicio', $data['estado']);
        }
        if (! empty($data['numero'])) {
            $query->where('cs.n_cotizacion', 'LIKE', $this->like($data['numero']));
        }

        if (! empty($data['instrumento'])) {
            $texto = mb_strtolower(trim($data['instrumento']));
            $tipoIds = [];
            if (str_contains($texto, 'balanza')) {
                $tipoIds[] = 1;
            }
            if (str_contains($texto, 'term')) {
                $tipoIds[] = 2;
            }
            if (str_contains($texto, 'pesa')) {
                $tipoIds[] = 3;
            }
            $like = $this->like($texto);

            $query->whereExists(function ($s) use ($tipoIds, $like) {
                $s->select(DB::raw(1))->from('instru_cotserv_directa as i')
                    ->whereColumn('i.id_cservicios', 'cs.id')
                    ->where(function ($w) use ($tipoIds, $like) {
                        if ($tipoIds) {
                            $w->orWhereIn('i.instrumento_id', $tipoIds);
                        }
                        $w->orWhere('i.instrumento_nombre', 'LIKE', $like)
                          ->orWhere('i.marca', 'LIKE', $like)
                          ->orWhere('i.modelo', 'LIKE', $like);
                    });
            });
        }

        $total = (clone $query)->count();

        $filas = $query->orderByDesc('cs.id')->forPage($pagina, $limite)->get([
            'cs.id', 'cs.n_cotizacion', 'cs.cliente_id', DB::raw('COALESCE(c.razonSocial, cs.razonSocial) as cliente'),
            'cs.fecha', 'cs.estado_servicio as estado', 'cs.n_informe', 'cs.descripcion', 'cs.moneda', 'cs.fpago',
            'cs.validez', 'cs.subtotal', 'cs.igv', 'cs.total', 'cs.id_asesor', 'cs.asesor',
            DB::raw("CONCAT_WS(' ', u.name, u.apellidopat, u.apellidomat) as asesor_usuario"),
        ]);

        $instrumentos = $filas->isEmpty() ? collect() : DB::table('instru_cotserv_directa')
            ->whereIn('id_cservicios', $filas->pluck('id')->all())
            ->orderBy('id')
            ->get(['id', 'id_cservicios', 'instrumento_id', 'instrumento_nombre', 'marca', 'modelo', 'precio_unitario'])
            ->groupBy('id_cservicios');

        $resultado = $filas->map(function ($f) use ($instrumentos, $user) {
            $lista = $instrumentos->get($f->id, collect());

            return [
                'id'           => $f->id,
                'n_cotizacion' => $f->n_cotizacion,
                'cliente_id'   => $f->cliente_id,
                'cliente'      => $f->cliente,
                'fecha'        => $f->fecha,
                'estado'       => $f->estado,
                'origen'       => $f->n_informe === 'DIRECTA' ? 'directa' : 'desde_informe',
                'descripcion'  => Str::limit((string) $f->descripcion, 150),
                'vendedor'     => trim((string) $f->asesor) !== '' ? $f->asesor : trim((string) $f->asesor_usuario),
                'es_mia'       => $this->esMiaServicio($f, $user),
                'moneda'       => $f->moneda,
                'forma_pago'   => $f->fpago,
                'validez'      => $f->validez,
                'subtotal'     => $this->numero($f->subtotal),
                'igv'          => $this->numero($f->igv),
                'total'        => $this->numero($f->total),
                'instrumentos' => $lista->take(10)->map(fn ($i) => [
                    'id'     => $i->id,
                    'tipo'   => $this->tipoInstrumento($i->instrumento_id, $i->instrumento_nombre),
                    'marca'  => $i->marca,
                    'modelo' => $i->modelo,
                    'precio' => $this->numero($i->precio_unitario),
                ])->values(),
                'cantidad_instrumentos' => $lista->count(),
            ];
        })->values();

        $this->auditar($request, 'buscar_cotizaciones_servicio', $data);

        return response()->json([
            'alcance' => $veTodo ? 'todas' : 'solo_propias',
            'rango'   => ['desde' => $desde, 'hasta' => $hasta],
            'pagina'  => $pagina,
            'limite'  => $limite,
            'total'   => $total,
            'hay_mas' => ($pagina * $limite) < $total,
            'data'    => $resultado,
        ]);
    }

    // GET /api/mcp/ventas/cotizaciones/servicio/{id}
    public function verServicio(Request $request, int $id)
    {
        [$user, $cs, $mia, $puedeEditarRol] = $this->cotizacionServicio($request, $id);

        $instrumentos = DB::table('instru_cotserv_directa')
            ->where('id_cservicios', $cs->id)
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($i) => [
                'id'                     => $i->id,
                'tipo'                   => $this->tipoInstrumento($i->instrumento_id, $i->instrumento_nombre),
                'titulo_servicio'        => $i->instrumento_nombre,
                'trabajos'               => Str::limit((string) $i->trabajos, 1500),
                'precio_unitario'        => $this->numero($i->precio_unitario),
                'marca'                  => $i->marca,
                'modelo'                 => $i->modelo,
                'numero_serie'           => $i->numero_serie,
                'capacidad_rango'        => $i->capacidad_rango,
                'resolucion_division'    => $i->resolucion_division,
                'clase_tipo_metrologico' => $i->clase_tipo_metrologico,
                'material'               => $i->material,
                'plataforma'             => $i->plataforma,
                'clase_exactitud'        => $i->clase_exactitud,
                'bastago'                => $i->bastago,
            ])->values();

        // condiciones_servicio se guarda como JSON (lista de textos)
        $condiciones = json_decode((string) $cs->condiciones_servicio, true);
        if (! is_array($condiciones)) {
            $condiciones = trim((string) $cs->condiciones_servicio) !== '' ? [(string) $cs->condiciones_servicio] : [];
        }

        $acciones = [];
        $motivo = null;
        if (! $puedeEditarRol) {
            $motivo = 'Tu rol solo puede consultar cotizaciones.';
        } elseif (! $mia && ! $this->accesoTotal($this->rolesDe($request))) {
            $motivo = 'Solo se pueden modificar las cotizaciones propias.';
        } elseif ($this->estadoBloqueado($cs->estado_servicio)) {
            $motivo = "Cotización {$cs->estado_servicio}: es definitiva y ya no admite cambios.";
        } else {
            $acciones = ['estado', 'precios', 'trabajos', 'caracteristicas', 'condiciones'];
        }

        $this->auditar($request, 'ver_cotizacion_servicio', ['id' => $id]);

        return response()->json([
            'id'            => $cs->id,
            'n_cotizacion'  => $cs->n_cotizacion,
            'estado'        => $cs->estado_servicio,
            'fecha'         => $cs->fecha,
            'origen'        => $cs->n_informe === 'DIRECTA' ? 'directa' : 'desde_informe',
            'n_informe'     => $cs->n_informe,
            'descripcion'   => $cs->descripcion,
            'vendedor'      => trim((string) $cs->asesor),
            'es_mia'        => $mia,
            'cliente'       => [
                'id'               => $cs->cliente_id,
                'razon_social'     => $cs->cliente,
                'nombre_comercial' => $cs->cliente_nombre_comercial,
                'documento'        => $cs->cliente_documento,
                'direccion'        => $cs->cliente_direccion,
            ],
            'moneda'        => $cs->moneda,
            'forma_pago'    => $cs->fpago,
            'garantia'      => $cs->garantia,
            'plazo_entrega' => $cs->plazo_entrega,
            'validez'       => $cs->validez,
            'subtotal'      => $this->numero($cs->subtotal),
            'igv'           => $this->numero($cs->igv),
            'total'         => $this->numero($cs->total),
            'condiciones_servicio' => $condiciones,
            'acciones_permitidas'  => $acciones,
            'motivo_restriccion'   => $motivo,
            'instrumentos'  => $instrumentos,
        ]);
    }

    // POST /api/mcp/ventas/cotizaciones/servicio/{id}/estado
    public function estadoServicio(Request $request, int $id)
    {
        [$user, $cs] = $this->cotizacionServicio($request, $id, true);

        $data = $request->validate([
            'estado' => ['required', Rule::in(config('mcp.cotizacion_estados'))],
        ]);

        $this->validarEditable($cs->estado_servicio);

        // Para Aceptar, los instrumentos deben tener los datos mínimos (igual que la pantalla)
        if ($data['estado'] === 'Aceptado') {
            $instrumentos = DB::table('instru_cotserv_directa')->where('id_cservicios', $cs->id)->get();

            if ($instrumentos->isEmpty() && ! empty($cs->instrumentos_json)) {
                $json = json_decode($cs->instrumentos_json, true);
                if (is_array($json)) {
                    $instrumentos = collect($json)->map(function ($item) {
                        $caract = $item['caracteristicas'] ?? [];

                        return (object) [
                            'marca'           => $caract['marca'] ?? '',
                            'modelo'          => $caract['modelo'] ?? '',
                            'capacidad_rango' => $caract['capacidad_rango'] ?? '',
                        ];
                    });
                }
            }

            if ($instrumentos->isEmpty()) {
                $this->fallo('estado', 'No se puede cambiar a estado Aceptado porque la cotización no tiene instrumentos registrados.');
            }

            foreach ($instrumentos as $inst) {
                $nombre = mb_strtolower((string) ($inst->instrumento_nombre ?? $inst->instrumento ?? ''));
                $esPesa = (string) ($inst->instrumento_id ?? '') === '3' || str_contains($nombre, 'pesa');
                $material = trim((string) ($inst->material ?? ''));
                $marca = trim((string) ($inst->marca ?? ''));

                $principalValido = $esPesa
                    ? $this->esCampoValido($material !== '' ? $material : $marca)
                    : $this->esCampoValido($inst->marca ?? null);

                if (! $principalValido
                    || ! $this->esCampoValido($inst->modelo ?? null)
                    || ! $this->esCapacidadValidaParaBalanza($inst->capacidad_rango ?? null)) {
                    $requeridos = $esPesa ? 'Material, Modelo y Capacidad' : 'Marca, Modelo y Capacidad';
                    $this->fallo('estado', "Para cambiar a Aceptado, completa {$requeridos} de cada instrumento (usa ventas_editar_caracteristicas_cotizacion_servicio).");
                }
            }
        }

        $anterior = $cs->estado_servicio;

        DB::transaction(function () use ($cs, $data) {
            $cotizacion = C_Servicio::lockForUpdate()->findOrFail($cs->id);
            $this->validarEditable($cotizacion->estado_servicio);

            $cotizacion->estado_servicio = $data['estado'];
            $cotizacion->save();
        });

        $this->auditar($request, 'estado_cotizacion_servicio', ['id' => $id, 'de' => $anterior, 'a' => $data['estado']]);

        return response()->json([
            'actualizado'     => true,
            'id'              => $cs->id,
            'n_cotizacion'    => $cs->n_cotizacion,
            'estado_anterior' => $anterior,
            'estado_nuevo'    => $data['estado'],
        ]);
    }

    /** Valida que los ids de instrumentos enviados pertenezcan a la cotización y no se repitan. */
    private function instrumentosPropios(object $cs, array $lista): void
    {
        $ids = collect($lista)->pluck('id')->map(fn ($v) => (int) $v);

        if ($ids->duplicates()->isNotEmpty()) {
            $this->fallo('instrumentos', 'Hay instrumentos repetidos en la lista.');
        }

        $propios = DB::table('instru_cotserv_directa')->where('id_cservicios', $cs->id)->pluck('id')->map(fn ($v) => (int) $v);
        $ajenos = $ids->diff($propios);

        if ($ajenos->isNotEmpty()) {
            $this->fallo('instrumentos', 'Estos instrumentos no pertenecen a la cotización ' . $cs->n_cotizacion . ': ' . $ajenos->implode(', ') . '.');
        }
    }

    /** Bloqueos comunes de edición (se repiten dentro de la transacción con la fila bloqueada). */
    private function servicioEditable(object $cs, string $que): void
    {
        $this->validarEditable($cs->estado_servicio);
        if ((int) $cs->estado_cotserv === 0) {
            $this->fallo('estado', "No se pueden editar {$que} de una cotización eliminada.");
        }
    }

    // POST /api/mcp/ventas/cotizaciones/servicio/{id}/precios
    public function preciosServicio(Request $request, int $id)
    {
        [$user, $cs] = $this->cotizacionServicio($request, $id, true);

        $data = $request->validate([
            'instrumentos'          => ['required', 'array', 'min:1', 'max:100'],
            'instrumentos.*.id'     => ['required', 'integer'],
            'instrumentos.*.precio' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $this->servicioEditable($cs, 'los precios');
        $this->instrumentosPropios($cs, $data['instrumentos']);

        $cambios = [];

        $resultado = DB::transaction(function () use ($cs, $data, &$cambios) {
            $cotizacion = C_Servicio::lockForUpdate()->findOrFail($cs->id);
            $this->servicioEditable($cotizacion, 'los precios');

            foreach ($data['instrumentos'] as $inst) {
                $nuevo = $this->normalizarPrecio($inst['precio']);
                $antes = $this->numero(DB::table('instru_cotserv_directa')->where('id', (int) $inst['id'])->value('precio_unitario'));

                DB::table('instru_cotserv_directa')->where('id', (int) $inst['id'])->update([
                    'precio_unitario' => $nuevo,
                    'updated_at'      => now(),
                ]);

                $cambios[] = ['id' => (int) $inst['id'], 'precio_anterior' => $antes, 'precio_nuevo' => (float) $nuevo];
            }

            // Totales: misma fórmula de CServicioController::actualizarPrecios (sin cantidades)
            $todos = DB::table('instru_cotserv_directa')->where('id_cservicios', $cotizacion->id)->orderBy('id')->get(['precio_unitario']);
            $subtotal = $todos->sum(fn ($i) => (float) $i->precio_unitario);
            $igv = config('mcp.cotizacion_igv', 0.18);

            // Campo legacy: lista de precios de TODOS los instrumentos, en orden
            $cotizacion->precio_unitario = json_encode($todos->map(fn ($i) => $this->normalizarPrecio($i->precio_unitario))->values()->all());
            $cotizacion->subtotal = number_format(round($subtotal, 2), 2, '.', '');
            $cotizacion->igv = number_format(round($subtotal * $igv, 2), 2, '.', '');
            $cotizacion->total = number_format(round($subtotal * (1 + $igv), 2), 2, '.', '');
            $cotizacion->save();

            return $cotizacion;
        });

        $this->auditar($request, 'precios_cotizacion_servicio', ['id' => $id, 'cambios' => $cambios]);

        return response()->json([
            'actualizado'  => true,
            'id'           => $resultado->id,
            'n_cotizacion' => $resultado->n_cotizacion,
            'moneda'       => $resultado->moneda,
            'subtotal'     => $this->numero($resultado->subtotal),
            'igv'          => $this->numero($resultado->igv),
            'total'        => $this->numero($resultado->total),
            'cambios'      => $cambios,
        ]);
    }

    // POST /api/mcp/ventas/cotizaciones/servicio/{id}/trabajos
    public function trabajosServicio(Request $request, int $id)
    {
        [$user, $cs] = $this->cotizacionServicio($request, $id, true);

        $data = $request->validate([
            'instrumentos'            => ['required', 'array', 'min:1', 'max:100'],
            'instrumentos.*.id'       => ['required', 'integer'],
            'instrumentos.*.trabajos' => ['required', 'string', 'max:2000'],
        ]);

        $this->servicioEditable($cs, 'los trabajos');
        $this->instrumentosPropios($cs, $data['instrumentos']);

        DB::transaction(function () use ($cs, $data) {
            $cotizacion = DB::table('cservicios')->where('id', $cs->id)->lockForUpdate()->first(['estado_servicio', 'estado_cotserv']);
            $this->servicioEditable($cotizacion, 'los trabajos');

            foreach ($data['instrumentos'] as $inst) {
                DB::table('instru_cotserv_directa')->where('id', (int) $inst['id'])->update([
                    'trabajos'   => trim($inst['trabajos']),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->auditar($request, 'trabajos_cotizacion_servicio', ['id' => $id, 'instrumentos' => collect($data['instrumentos'])->pluck('id')->all()]);

        return response()->json([
            'actualizado'  => true,
            'id'           => $cs->id,
            'n_cotizacion' => $cs->n_cotizacion,
            'instrumentos_actualizados' => count($data['instrumentos']),
        ]);
    }

    // POST /api/mcp/ventas/cotizaciones/servicio/{id}/caracteristicas
    public function caracteristicasServicio(Request $request, int $id)
    {
        [$user, $cs] = $this->cotizacionServicio($request, $id, true);

        $data = $request->validate([
            'instrumentos'                    => ['required', 'array', 'min:1', 'max:100'],
            'instrumentos.*.id'               => ['required', 'integer'],
            'instrumentos.*.marca'            => ['nullable', 'string', 'max:255'],
            'instrumentos.*.material'         => ['nullable', 'string', 'max:255'],
            'instrumentos.*.modelo'           => ['required', 'string', 'max:255'],
            'instrumentos.*.capacidad_rango'  => ['nullable', 'string', 'max:255'],
        ], [
            'instrumentos.*.modelo.required' => 'El Modelo es obligatorio para todos los instrumentos.',
        ]);

        $this->servicioEditable($cs, 'las características');
        $this->instrumentosPropios($cs, $data['instrumentos']);

        // 1.ª pasada: validar todo (si algo falla, no se guarda nada)
        $actualizaciones = [];
        foreach ($data['instrumentos'] as $idx => $inst) {
            $marca = trim($inst['marca'] ?? '');
            $material = trim($inst['material'] ?? '');
            $modelo = trim($inst['modelo'] ?? '');
            $capacidad = trim($inst['capacidad_rango'] ?? '');

            $dbInst = DB::table('instru_cotserv_directa')->where('id', (int) $inst['id'])->first();
            $nombre = mb_strtolower((string) ($dbInst->instrumento_nombre ?? ''));
            $esPesa = ($dbInst && ((int) $dbInst->instrumento_id === 3 || str_contains($nombre, 'pesa'))) || $material !== '';
            $n = $idx + 1;

            if ($esPesa) {
                if (! $this->esCampoValido($material) || ! $this->esCampoValido($modelo) || ! $this->esCapacidadValidaParaBalanza($capacidad)) {
                    $this->fallo('instrumentos', "Debes ingresar información válida para Material, Modelo y Capacidad en la pesa #{$n}.");
                }
            } else {
                if (! $this->esCampoValido($marca) || ! $this->esCampoValido($modelo)) {
                    $this->fallo('instrumentos', "Debes ingresar información válida para Marca y Modelo en el instrumento #{$n}.");
                }
            }

            $actualizaciones[(int) $inst['id']] = [
                'marca'           => $esPesa ? $material : $marca,
                'material'        => $material !== '' ? $material : ($dbInst->material ?? ''),
                'modelo'          => $modelo,
                'capacidad_rango' => $capacidad !== '' ? $capacidad : 'NO INDICA',
                'updated_at'      => now(),
            ];
        }

        // 2.ª pasada: guardar
        DB::transaction(function () use ($cs, $actualizaciones) {
            $cotizacion = DB::table('cservicios')->where('id', $cs->id)->lockForUpdate()->first(['estado_servicio', 'estado_cotserv']);
            $this->servicioEditable($cotizacion, 'las características');

            foreach ($actualizaciones as $idInst => $campos) {
                DB::table('instru_cotserv_directa')->where('id', $idInst)->update($campos);
            }
        });

        $this->auditar($request, 'caracteristicas_cotizacion_servicio', ['id' => $id, 'instrumentos' => array_keys($actualizaciones)]);

        return response()->json([
            'actualizado'  => true,
            'id'           => $cs->id,
            'n_cotizacion' => $cs->n_cotizacion,
            'instrumentos_actualizados' => count($actualizaciones),
        ]);
    }

    // POST /api/mcp/ventas/cotizaciones/servicio/{id}/condiciones
    public function condicionesServicio(Request $request, int $id)
    {
        [$user, $cs] = $this->cotizacionServicio($request, $id, true);

        $data = $request->validate([
            'condiciones'   => ['required', 'array', 'min:1', 'max:30'],
            'condiciones.*' => ['required', 'string', 'max:1000'],
        ]);

        $this->servicioEditable($cs, 'las condiciones');

        $condiciones = array_values(array_filter(
            array_map('trim', $data['condiciones']),
            static fn ($c) => $c !== ''
        ));

        if (empty($condiciones)) {
            $this->fallo('condiciones', 'Debe existir al menos una condición del servicio.');
        }

        DB::transaction(function () use ($cs, $condiciones) {
            $cotizacion = C_Servicio::lockForUpdate()->findOrFail($cs->id);
            $this->servicioEditable($cotizacion, 'las condiciones');

            // El modelo la guarda como lista (igual que CServicioController::actualizarCondicionesServicio)
            $cotizacion->condiciones_servicio = $condiciones;
            $cotizacion->save();
        });

        $this->auditar($request, 'condiciones_cotizacion_servicio', ['id' => $id, 'cantidad' => count($condiciones)]);

        return response()->json([
            'actualizado'  => true,
            'id'           => $cs->id,
            'n_cotizacion' => $cs->n_cotizacion,
            'condiciones'  => $condiciones,
        ]);
    }

    // ───────────────────────── Catálogos para registrar ─────────────────────────

    // GET /api/mcp/ventas/cotizaciones/productos
    public function buscarProductos(Request $request)
    {
        $this->acceso($request);

        $data = $request->validate([
            'q'            => ['nullable', 'string', 'min:2', 'max:100'],
            'categoria'    => ['nullable', 'string', 'max:100'],
            'subcategoria' => ['nullable', 'string', 'max:100'],
            'marca'        => ['nullable', 'string', 'max:100'],
            'pagina'       => ['nullable', 'integer', 'min:1'],
            'limite'       => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        if (collect(['q', 'categoria', 'subcategoria', 'marca'])->every(fn ($k) => empty($data[$k]))) {
            $this->fallo('q', 'Indica al menos un criterio: texto (modelo, código, marca), categoría, subcategoría o marca.');
        }

        $pagina = (int) ($data['pagina'] ?? 1);
        $limite = (int) ($data['limite'] ?? 10);

        $query = DB::table('tbproductos as p')
            ->join('tbcategorias as c', 'c.id', '=', 'p.tbcategoria_id')
            ->leftJoin('tbsubcategorias as s', 's.id', '=', 'p.tbsubcategoria_id')
            ->join('tbmarcas as m', 'm.id', '=', 'p.tbmarca_id');

        if (! empty($data['q'])) {
            foreach (array_slice(preg_split('/\s+/', trim($data['q'])) ?: [], 0, 5) as $palabra) {
                $like = $this->like($palabra);
                $query->where(function ($w) use ($like) {
                    $w->where('p.modelo', 'LIKE', $like)
                      ->orWhere('p.codigo', 'LIKE', $like)
                      ->orWhere('m.nombre', 'LIKE', $like)
                      ->orWhere('s.nombre', 'LIKE', $like)
                      ->orWhere('p.capacidades', 'LIKE', $like);
                });
            }
        }
        if (! empty($data['categoria'])) {
            $query->where('c.nombre', 'LIKE', $this->like($data['categoria']));
        }
        if (! empty($data['subcategoria'])) {
            $query->where('s.nombre', 'LIKE', $this->like($data['subcategoria']));
        }
        if (! empty($data['marca'])) {
            $query->where('m.nombre', 'LIKE', $this->like($data['marca']));
        }

        $total = (clone $query)->count();

        $filas = $query->orderBy('c.nombre')->orderBy('m.nombre')->orderBy('p.modelo')
            ->forPage($pagina, $limite)
            ->get([
                'p.id', 'c.nombre as categoria', 's.nombre as subcategoria', 'm.nombre as marca', 'p.modelo',
                'p.medida', 'p.moneda', 'p.precio_list', 'p.precio_min', 'p.precio_max', 'p.stock', 'p.codigo',
                'p.capacidades', 'p.especificaciones', 'p.garantia', 'p.fecha_act',
            ])
            ->map(fn (\stdClass $p) => [
                'id'               => $p->id,
                'categoria'        => $p->categoria,
                'subcategoria'     => $p->subcategoria,
                'marca'            => $p->marca,
                'modelo'           => $p->modelo,
                'codigo'           => $p->codigo,
                'medida'           => $p->medida,
                'moneda'           => $p->moneda,
                'precio_lista'     => $this->numero($p->precio_list),
                'precio_min'       => $this->numero($p->precio_min),
                'precio_max'       => $this->numero($p->precio_max),
                'stock'            => (int) $p->stock,
                'garantia'         => $p->garantia,
                'capacidades'      => (string) $p->capacidades,
                'especificaciones' => Str::limit((string) $p->especificaciones, 300),
                'precio_actualizado' => $p->fecha_act,
            ])->values();

        $this->auditar($request, 'buscar_productos', $data);

        return response()->json([
            'pagina'  => $pagina,
            'limite'  => $limite,
            'total'   => $total,
            'hay_mas' => ($pagina * $limite) < $total,
            'data'    => $filas,
        ]);
    }

    // GET /api/mcp/ventas/cotizaciones/plantillas
    public function plantillas(Request $request)
    {
        $this->acceso($request);

        $filas = DB::table('plantillas')->orderBy('instrumento')->orderBy('nombre')->limit(100)
            ->get(['id', 'instrumento', 'nombre', 'descripcion'])
            ->map(fn ($p) => [
                'id'          => $p->id,
                'instrumento' => $p->instrumento,
                'nombre'      => $p->nombre,
                'descripcion' => Str::limit((string) $p->descripcion, 250),
            ])->values();

        return response()->json(['data' => $filas]);
    }

    // ───────────────────────── Registrar cotizaciones ─────────────────────────

    private function sinAcentos(string $texto): string
    {
        return mb_strtolower(Str::ascii(trim($texto)));
    }

    /** Monedas ya usadas en la tabla, de la más frecuente a la menos (para escribir igual que el sistema). */
    private function monedasUsadas(string $tabla): array
    {
        return Cache::remember("mcp.cotizaciones.monedas.{$tabla}", 600, fn () => DB::table($tabla)
            ->whereNotNull('moneda')->where('moneda', '!=', '')
            ->select('moneda', DB::raw('COUNT(*) as n'))->groupBy('moneda')->orderByDesc('n')->limit(10)
            ->pluck('moneda')->all());
    }

    /** Devuelve [valor a guardar, 'soles'|'dolares'] respetando la forma en que ya se guarda en la tabla. */
    private function monedaCotizacion(string $entrada, string $tabla): array
    {
        $n = $this->sinAcentos($entrada);

        if (str_contains($n, 'dol') || str_contains($n, 'usd') || str_contains($n, '$')) {
            $tipo = 'dolares';
            $clave = 'dol';
        } elseif (str_contains($n, 'sol') || str_contains($n, 's/') || $n === 'pen') {
            $tipo = 'soles';
            $clave = 'sol';
        } else {
            $this->fallo('moneda', 'Moneda no válida: usa "Soles" o "Dólares".');
        }

        foreach ($this->monedasUsadas($tabla) as $existente) {
            if (str_contains($this->sinAcentos((string) $existente), $clave)) {
                return [$existente, $tipo];
            }
        }

        return [$tipo === 'soles' ? 'Soles' : 'Dolares', $tipo];
    }

    /** Moneda de un producto del catálogo: el sistema usa "$" o "s/". */
    private function monedaProducto(?string $moneda, int $productoId): string
    {
        $n = $this->sinAcentos((string) $moneda);

        if (str_contains($n, '$') || str_contains($n, 'dol') || str_contains($n, 'usd')) {
            return '$';
        }
        if (str_contains($n, 's/') || str_contains($n, 'sol') || $n === 'pen') {
            return 's/';
        }

        $this->fallo('productos', "No reconozco la moneda del producto {$productoId} (\"{$moneda}\"). Avisa al área de TI.");
    }

    private function fechaCotizacion(?string $fecha): string
    {
        $hoy = now()->startOfDay();
        $f = $fecha ? Carbon::createFromFormat('Y-m-d', $fecha)->startOfDay() : $hoy;
        $maxAtras = (int) config('mcp.cotizacion_dias_atras', 7);

        if ($f->gt($hoy)) {
            $this->fallo('fecha', 'La fecha de la cotización no puede ser futura.');
        }
        if ($f->lt($hoy->copy()->subDays($maxAtras))) {
            $this->fallo('fecha', "La fecha de la cotización no puede ser anterior a {$maxAtras} días.");
        }

        return $f->toDateString();
    }

    /**
     * Bloquea la fila del cliente y aplica las reglas de CventaController::guardarCotizacion
     * y CServicioController::store: cliente activo y no asignado a otro asesor (salvo vencido, 2 meses).
     */
    private function clienteParaCotizar(int $clienteId, $user, string $tipo): object
    {
        $c = DB::table('clientes')->where('id', $clienteId)->lockForUpdate()
            ->first(['id', 'razonSocial', 'numeroDocumento', 'direccion', 'asesor', 'fechafactura', 'estado_eliminado']);

        if (! $c) {
            $this->fallo('cliente_id', 'Cliente no encontrado.');
        }

        $activo = config('mcp.clientes_estado_eliminado_activo');
        if ($activo !== null && (string) $c->estado_eliminado !== (string) $activo) {
            $this->fallo('cliente_id', "El cliente {$c->razonSocial} está desactivado: no se puede cotizar.");
        }

        $limite = now()->subMonths((int) config('mcp.cliente_inactivo_meses', 2))->toDateString();
        $vencido = $c->fechafactura && $c->fechafactura < $limite;

        if ($c->asesor && (int) $c->asesor !== (int) $user->id && ! $vencido && ! $this->accesoTotal($user->roles->pluck('name')->all())) {
            $asesor = DB::table('users')->where('id', $c->asesor)->value('name');
            $this->fallo('cliente_id', "No se puede crear una cotización de {$tipo} para {$c->razonSocial} porque ya está asignado al asesor {$asesor}.");
        }

        return $c;
    }

    /** n_cotizacion se calcula al crear: si dos usuarios crean a la vez, reintenta ante clave duplicada. */
    private function conReintento(callable $accion, int $intentos = 3)
    {
        for ($i = 1; ; $i++) {
            try {
                return $accion();
            } catch (QueryException $e) {
                if ($i >= $intentos || ($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
                usleep(random_int(50, 250) * 1000);
            }
        }
    }

    // POST /api/mcp/ventas/cotizaciones/venta
    /** Una opción única se preselecciona; varias requieren selección explícita, como en Create.vue. */
    private function seleccionVenta(array $linea, string $campo, ?string $opciones): string
    {
        $normalizar = fn ($texto) => collect(preg_split('/\r?\n/', (string) $texto))
            ->map(fn ($valor) => trim($valor))->filter(fn ($valor) => $valor !== '')->unique()->values();
        if (array_key_exists($campo, $linea)) {
            return $normalizar($linea[$campo])->implode("\n");
        }
        $valores = $normalizar($opciones);
        if ($valores->count() > 1) {
            $this->fallo('productos', "Selecciona {$campo} del producto {$linea['producto_id']} o envía un texto vacío para no incluirlas.");
        }
        return $valores->implode("\n");
    }

    public function registrarVenta(Request $request)
    {
        [$user, , $puedeEditarRol] = $this->acceso($request);
        abort_unless($puedeEditarRol, 403, 'Tu rol no puede crear cotizaciones desde el asistente (solo Asesor de Ventas y Jefe de Ventas).');

        $data = $request->validate([
            'cliente_id'                   => ['required', 'integer', 'exists:clientes,id'],
            'fecha'                        => ['nullable', 'date_format:Y-m-d'],
            'moneda'                       => ['required', 'string', 'max:20'],
            'tipo_cambio'                  => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'forma_pago'                   => ['required', 'string', 'max:255'],
            'dias_entrega'                 => ['required', 'string', 'max:255'],
            'validez'                      => ['required', 'string', 'max:255'],
            'productos'                    => ['required', 'array', 'min:1', 'max:50'],
            'productos.*.producto_id'      => ['required', 'integer'],
            'productos.*.cantidad'         => ['required', 'numeric', 'gt:0', 'max:100000'],
            'productos.*.importe'          => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'productos.*.capacidades'      => ['nullable', 'string', 'max:255'],
            'productos.*.medidas'          => ['nullable', 'string', 'max:2000'],
            'productos.*.garantia'         => ['nullable', 'string', 'max:255'],
            'productos.*.dias_entrega'     => ['nullable', 'string', 'max:255'],
            'productos.*.forma_pago'       => ['nullable', 'string', 'max:255'],
            'productos.*.requerimientos'   => ['nullable', 'string', 'max:255'],
        ]);

        $fecha = $this->fechaCotizacion($data['fecha'] ?? null);
        [$monedaCot, $tipoMoneda] = $this->monedaCotizacion($data['moneda'], 'cventas');

        // Productos del catálogo
        $ids = collect($data['productos'])->pluck('producto_id')->map(fn ($v) => (int) $v);

        $catalogo = DB::table('tbproductos as p')
            ->leftJoin('tbmarcas as m', 'm.id', '=', 'p.tbmarca_id')
            ->whereIn('p.id', $ids->all())
            ->get(['p.*', 'm.nombre as marca_nombre'])
            ->keyBy('id');

        $faltan = $ids->reject(fn ($id) => $catalogo->has($id));
        if ($faltan->isNotEmpty()) {
            $this->fallo('productos', 'No existen estos productos del catálogo: ' . $faltan->implode(', ') . '.');
        }

        $lineas = [];
        $advertencias = [];
        $necesitaTc = false;

        foreach ($data['productos'] as $linea) {
            $p = $catalogo->get((int) $linea['producto_id']);
            $importe = $linea['importe'] ?? $p->precio_min;

            if ($importe === null || $importe === '') {
                $this->fallo('productos', "El producto {$p->id} ({$p->modelo}) no tiene precio mínimo: indica el importe.");
            }
            $importe = round((float) $importe, 2);
            $monedaProd = $this->monedaProducto($p->moneda, (int) $p->id);

            if (($monedaProd === '$') !== ($tipoMoneda === 'dolares')) {
                $necesitaTc = true;
            }
            if (is_numeric($p->precio_min) && (float) $p->precio_min > 0 && $importe < (float) $p->precio_min) {
                $advertencias[] = "Producto {$p->id} ({$p->modelo}): {$importe} está por debajo del precio mínimo {$p->precio_min}.";
            }
            if (is_numeric($p->precio_max) && (float) $p->precio_max > 0 && $importe > (float) $p->precio_max) {
                $advertencias[] = "Producto {$p->id} ({$p->modelo}): {$importe} supera el precio máximo {$p->precio_max}.";
            }

            $lineas[] = [
                'p'              => $p,
                'cantidad'       => (float) $linea['cantidad'],
                'capacidades'    => $this->seleccionVenta($linea, 'capacidades', $p->capacidades),
                'medidas'        => $this->seleccionVenta($linea, 'medidas', $p->medida),
                'garantia'       => $linea['garantia'] ?? ($p->garantia ?: 'Sin Garantia'),
                'dias_entrega'   => $linea['dias_entrega'] ?? '1 dia',
                'forma_pago'     => $linea['forma_pago'] ?? 'Al contado',
                'importe'        => $importe,
                'moneda'         => $monedaProd,
                'requerimientos' => $linea['requerimientos'] ?? null,
            ];
        }

        if ($necesitaTc && empty($data['tipo_cambio'])) {
            $this->fallo('tipo_cambio', 'Hay productos en otra moneda que la de la cotización: indica el tipo de cambio.');
        }
        $tipoCambio = isset($data['tipo_cambio']) ? (string) $data['tipo_cambio'] : null;

        $tecnico = $user->name . ' ' . $user->apellidopat . ' ' . $user->apellidomat; // igual que guardarCotizacion

        $cotizacion = $this->conReintento(fn () => DB::transaction(function () use ($data, $user, $fecha, $monedaCot, $tipoCambio, $lineas, $tecnico) {
            $c = $this->clienteParaCotizar((int) $data['cliente_id'], $user, 'venta');

            // Una sola cotización pendiente por cliente en los últimos 14 días (igual que la pantalla)
            $pendiente = Cventa::where('cliente_id', $c->id)
                ->where('estado', 'Pendiente')
                ->where('estado_cot', 1)
                ->where('fecha', '>=', now()->subDays(14))
                ->first(['n_cotizacion', 'tecnico']);

            if ($pendiente) {
                $this->fallo('cliente_id', "No se puede registrar una nueva cotización para este cliente porque ya tiene una pendiente (N° {$pendiente->n_cotizacion}, de {$pendiente->tecnico}). Finalízala antes de crear una nueva.");
            }

            $cv = new Cventa;
            $cv->cliente_id = $c->id;
            $cv->numeroDocumento = $c->numeroDocumento;
            $cv->direccion = $c->direccion;
            $cv->fecha = $fecha;
            $cv->fecha_registro = date('Y-m-d');
            $cv->moneda = $monedaCot;
            $cv->validez_cot = $data['validez'];
            $cv->forma_pago = $data['forma_pago'];
            $cv->dias_entrega = $data['dias_entrega'];
            $cv->tipoCambio = $tipoCambio;
            $cv->tecnico = $tecnico;
            $cv->estado = 'Pendiente';
            $cv->estado_cot = 1;
            $cv->subtotal = 0;
            $cv->igv = 0;
            $cv->total = 0;
            $cv->save(); // el modelo asigna n_cotizacion

            $agregados = collect();
            foreach ($lineas as $l) {
                $p = $l['p'];
                $m = new TbproductosAgregados;
                $m->forceFill([
                    'idCotizacion'     => (string) $cv->id,
                    'subcategoria_id'  => $p->tbsubcategoria_id,
                    'categoria_id'     => $p->tbcategoria_id,
                    'modelo'           => $p->modelo,
                    'especificaciones' => $p->especificaciones,
                    'marca'            => $p->marca_nombre,
                    'capacidades'      => $l['capacidades'],
                    'medidas'          => $l['medidas'],
                    'precio_list'      => $p->precio_list,
                    'precio_min'       => $p->precio_min,
                    'precio_max'       => $p->precio_max,
                    'cantidad'         => $l['cantidad'],
                    'importe'          => number_format($l['importe'], 2, '.', ''),
                    'garantia'         => $l['garantia'],
                    'dias_entrega'     => $l['dias_entrega'],
                    'forma_pago'       => $l['forma_pago'],
                    'moneda'           => $l['moneda'],
                    'foto'             => $p->foto,
                    'requerimientos'   => $l['requerimientos'],
                ])->save();
                $agregados->push($m);
            }

            [$subtotal, $igv, $total] = $this->totalesVenta($agregados, $cv->moneda, $cv->tipoCambio);
            $cv->subtotal = $subtotal;
            $cv->igv = $igv;
            $cv->total = $total;
            $cv->save();

            return [$cv, $c, $agregados];
        }));

        [$cv, $c, $agregados] = $cotizacion;

        $this->auditar($request, 'registrar_cotizacion_venta', [
            'id' => $cv->id, 'n_cotizacion' => $cv->n_cotizacion, 'cliente_id' => $c->id, 'productos' => $agregados->count(),
        ]);

        return response()->json([
            'registrado'   => true,
            'id'           => $cv->id,
            'n_cotizacion' => $cv->n_cotizacion,
            'estado'       => 'Pendiente',
            'cliente'      => $c->razonSocial,
            'fecha'        => $cv->fecha,
            'moneda'       => $cv->moneda,
            'subtotal'     => $this->numero($cv->subtotal),
            'igv'          => $this->numero($cv->igv),
            'total'        => $this->numero($cv->total),
            'total_con_igv_calculado' => round((float) $cv->subtotal + (float) $cv->igv, 2),
            'productos'    => $agregados->map(fn ($m) => [
                'id' => $m->id, 'modelo' => $m->modelo, 'marca' => $m->marca, 'cantidad' => (float) $m->cantidad, 'importe_unitario' => (float) $m->importe,
            ])->values(),
            'advertencias' => $advertencias,
        ], 201);
    }

    // POST /api/mcp/ventas/cotizaciones/servicio  (cotización DIRECTA, sin informe técnico)
    public function registrarServicio(Request $request)
    {
        return $this->guardarServicio($request);
    }

    private function guardarServicio(Request $request, ?int $reporteId = null)
    {
        [$user, , $puedeEditarRol] = $this->acceso($request);
        abort_unless($puedeEditarRol, 403, 'Tu rol no puede crear cotizaciones desde el asistente (solo Asesor de Ventas y Jefe de Ventas).');

        $data = $request->validate([
            'cliente_id'                         => ['required', 'integer', 'exists:clientes,id'],
            'plantilla_id'                       => ['required', 'integer', 'exists:plantillas,id'],
            'fecha'                              => ['nullable', 'date_format:Y-m-d'],
            'descripcion'                        => ['nullable', 'string', 'max:255'],
            'moneda'                             => ['required', 'string', 'max:20'],
            'forma_pago'                         => ['required', 'string', 'max:255'],
            'garantia'                           => ['nullable', 'string', 'max:255'],
            'plazo_entrega'                      => ['nullable', 'string', 'max:255'],
            'validez'                            => ['nullable', 'string', 'max:255'],
            'condiciones'                        => ['nullable', 'array', 'min:1', 'max:30'],
            'condiciones.*'                      => ['required', 'string', 'max:1000'],
            'instrumentos'                       => ['required', 'array', 'min:1', 'max:50'],
            'instrumentos.*.tipo'                => ['required', 'string', 'max:30'],
            'instrumentos.*.precio'              => ['required', 'numeric', 'min:0', 'max:99999999'],
            'instrumentos.*.titulo_servicio'     => ['nullable', 'string', 'max:255'],
            'instrumentos.*.trabajos'            => ['nullable', 'string', 'max:2000'],
            'instrumentos.*.marca'               => ['nullable', 'string', 'max:255'],
            'instrumentos.*.modelo'              => ['nullable', 'string', 'max:255'],
            'instrumentos.*.numero_serie'        => ['nullable', 'string', 'max:255'],
            'instrumentos.*.capacidad_rango'     => ['nullable', 'string', 'max:255'],
            'instrumentos.*.resolucion_division' => ['nullable', 'string', 'max:255'],
            'instrumentos.*.clase_tipo_metrologico' => ['nullable', 'string', 'max:255'],
            'instrumentos.*.material'            => ['nullable', 'string', 'max:255'],
            'instrumentos.*.plataforma'          => ['nullable', 'string', 'max:255'],
            'instrumentos.*.clase_exactitud'     => ['nullable', 'string', 'max:255'],
            'instrumentos.*.bastago'             => ['nullable', 'string', 'max:255'],
        ]);

        $fecha = $this->fechaCotizacion($data['fecha'] ?? null);
        [$monedaCot] = $this->monedaCotizacion($data['moneda'], 'cservicios');
        $plantilla = DB::table('plantillas')->where('id', (int) $data['plantilla_id'])->first(['id', 'nombre']);
        $titulo = $data['descripcion'] ?? $plantilla->nombre;

        $tipos = ['balanza' => 1, 'termometro' => 2, 'pesa' => 3];
        $instrumentos = [];
        foreach ($data['instrumentos'] as $idx => $i) {
            $clave = $this->sinAcentos($i['tipo']);
            $clave = rtrim($clave, 's');
            if (! isset($tipos[$clave])) {
                $this->fallo('instrumentos', 'El tipo del instrumento #' . ($idx + 1) . ' no es válido: usa Balanza, Termómetro o Pesa.');
            }
            $instrumentos[] = $i + ['instrumento_id' => $tipos[$clave]];
        }

        $subtotal = array_sum(array_map(fn ($i) => round((float) $i['precio'], 2), $instrumentos));
        $igv = config('mcp.cotizacion_igv', 0.18);

        $condiciones = $data['condiciones'] ?? config('mcp.cotizacion_condiciones_servicio_generales', []);
        $condiciones = array_values(array_filter(array_map('trim', $condiciones), fn ($c) => $c !== ''));
        if (! $condiciones) {
            $this->fallo('condiciones', 'Debe existir al menos una condición del servicio.');
        }

        $nombre = trim("{$user->name} {$user->apellidopat} {$user->apellidomat}");

        $resultado = $this->conReintento(fn () => DB::transaction(function () use ($data, $user, $fecha, $monedaCot, $plantilla, $titulo, $instrumentos, $subtotal, $igv, $condiciones, $nombre, $reporteId) {
            $c = $this->clienteParaCotizar((int) $data['cliente_id'], $user, 'servicio');

            // Una sola cotización de servicio pendiente por cliente en los últimos 14 días (igual que la pantalla)
            $pendiente = C_Servicio::where('cliente_id', $c->id)
                ->where('estado_servicio', 'Pendiente')
                ->where('estado_cotserv', 1)
                ->where('fecha', '>=', now()->subDays(14))
                ->first(['n_cotizacion', 'asesor']);

            if ($pendiente) {
                $this->fallo('cliente_id', "No se puede registrar una nueva cotización para este cliente porque ya tiene una pendiente (N° {$pendiente->n_cotizacion}, de {$pendiente->asesor}). Finalízala antes de crear una nueva.");
            }

            $precios = array_map(fn ($i) => $this->normalizarPrecio($i['precio']), $instrumentos);

            $servicio = C_Servicio::create([
                'descripcion'          => $titulo,
                'estado_servicio'      => 'Pendiente',
                'estado_cotserv'       => 1,
                'fecha'                => $fecha,
                'n_informe'            => $reporteId === null ? 'DIRECTA' : (string) $reporteId,
                'razonSocial'          => $c->razonSocial,
                'cliente_id'           => $c->id,
                'moneda'               => $monedaCot,
                'fpago'                => $data['forma_pago'],
                'garantia'             => $data['garantia'] ?? null,
                'plazo_entrega'        => $data['plazo_entrega'] ?? '3 días hábiles',
                'validez'              => $data['validez'] ?? null,
                'condiciones_servicio' => $condiciones,
                'subtotal'             => number_format(round($subtotal, 2), 2, '.', ''),
                'igv'                  => number_format(round($subtotal * $igv, 2), 2, '.', ''),
                'total'                => number_format(round($subtotal * (1 + $igv), 2), 2, '.', ''),
                'id_plantilla'         => $plantilla->id,
                'id_asesor'            => $user->id,
                'asesor'               => $nombre,
                'precio_unitario'      => json_encode($precios),
            ]); // el modelo asigna n_cotizacion

            $ahora = now();
            $filas = array_map(fn ($i) => [
                'id_cservicios'          => $servicio->id,
                'instrumento_id'         => $i['instrumento_id'],
                'instrumento_nombre'     => $i['titulo_servicio'] ?? $titulo,
                'trabajos'               => $i['trabajos'] ?? '',
                'precio_unitario'        => $this->normalizarPrecio($i['precio']),
                'marca'                  => $i['marca'] ?? '',
                'modelo'                 => $i['modelo'] ?? '',
                'numero_serie'           => $i['numero_serie'] ?? '',
                'capacidad_rango'        => $i['capacidad_rango'] ?? '',
                'resolucion_division'    => $i['resolucion_division'] ?? '',
                'clase_tipo_metrologico' => $i['clase_tipo_metrologico'] ?? '',
                'material'               => $i['material'] ?? '',
                'plataforma'             => $i['plataforma'] ?? '',
                'clase_exactitud'        => $i['clase_exactitud'] ?? '',
                'bastago'                => $i['bastago'] ?? '',
                'created_at'             => $ahora,
                'updated_at'             => $ahora,
            ], $instrumentos);

            DB::table('instru_cotserv_directa')->insert($filas);

            $ids = DB::table('instru_cotserv_directa')->where('id_cservicios', $servicio->id)->orderBy('id')->pluck('id')->all();

            return [$servicio, $c, $ids];
        }));

        [$servicio, $c, $idsInstrumentos] = $resultado;

        $this->auditar($request, $reporteId === null ? 'registrar_cotizacion_servicio' : 'registrar_cotizacion_servicio_desde_informe', [
            'reporte_id' => $reporteId,
            'id' => $servicio->id, 'n_cotizacion' => $servicio->n_cotizacion, 'cliente_id' => $c->id, 'instrumentos' => count($idsInstrumentos),
        ]);

        return response()->json([
            'registrado'   => true,
            'id'           => $servicio->id,
            'n_cotizacion' => $servicio->n_cotizacion,
            'estado'       => 'Pendiente',
            'origen'       => $reporteId === null ? 'directa' : 'desde_informe',
            'reporte_id'   => $reporteId,
            'cliente'      => $c->razonSocial,
            'fecha'        => $servicio->fecha,
            'moneda'       => $servicio->moneda,
            'subtotal'     => $this->numero($servicio->subtotal),
            'igv'          => $this->numero($servicio->igv),
            'total'        => $this->numero($servicio->total),
            'instrumentos' => $idsInstrumentos,
            'nota'         => 'Los datos técnicos que falten (marca, modelo, capacidad) se completan con ventas_editar_caracteristicas_cotizacion_servicio; son obligatorios para pasar a Aceptado.',
        ], 201);
    }
}

