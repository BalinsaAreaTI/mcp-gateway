<?php

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Flujo de CServicioController::cotiza / Servicio.vue, bajo permisos MCP. */
trait CotizacionesDesdeInforme
{
    private function informesVisibles(Request $request)
    {
        [$user, $veTodo] = $this->acceso($request);
        $query = DB::table('reportes as r')->join('clientes as c', 'c.id', '=', 'r.cliente_id');
        if (! $veTodo) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('c.asesor')->orWhere('c.asesor', 0)->orWhere('c.asesor', $user->id)
                    ->orWhere('c.fechafactura', '<', now()->subMonths((int) config('mcp.cliente_inactivo_meses', 2))->toDateString());
            });
        }
        return $query;
    }

    public function buscarInformesCotizables(Request $request)
    {
        $this->acceso($request);
        $data = $request->validate([
            'buscar' => ['nullable', 'string', 'max:150'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ]);
        [$desde, $hasta] = $this->rango($data['desde'] ?? null, $data['hasta'] ?? null, 90, 366);
        $query = $this->informesVisibles($request)->whereBetween('r.fecha', [$desde, $hasta])
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('reportes_instrumentos as ri')
                    ->join('diagnosticos as d', 'd.reporte_instrumento_id', '=', 'ri.id')
                    ->whereColumn('ri.reporte_id', 'r.id');
            });
        if (! empty($data['buscar'])) {
            $query->where(function ($q) use ($data) {
                $q->where('r.n_servicio', 'like', '%'.$data['buscar'].'%')
                    ->orWhere('c.razonSocial', 'like', '%'.$data['buscar'].'%');
            });
        }
        $pagina = $query->select('r.id as reporte_id', 'r.n_servicio', 'r.fecha', 'c.razonSocial as cliente')
            ->orderByDesc('r.id')->paginate(20, ['*'], 'pagina', $data['pagina'] ?? 1);
        return response()->json($pagina);
    }

    public function verInformeCotizable(Request $request, int $id)
    {
        $data = $request->validate(['pagina' => ['nullable', 'integer', 'min:1']]);
        $reporte = $this->informesVisibles($request)->where('r.id', $id)
            ->first(['r.id as reporte_id', 'r.n_servicio', 'r.fecha', 'c.id as cliente_id', 'c.razonSocial as cliente']);
        abort_unless($reporte, 404, 'Informe no encontrado.');
        $instrumentos = DB::table('reportes_instrumentos')->where('reporte_id', $id)->orderBy('id')
            ->paginate(20, ['id', 'tipo_instrumento', 'marca', 'modelo', 'serie', 'capacidad_rango', 'resolucion_division', 'clase_tipo_metrologico', 'material'], 'pagina', $data['pagina'] ?? 1);
        $instrumentos->getCollection()->transform(function ($i) {
            $d = DB::table('diagnosticos')->where('reporte_instrumento_id', $i->id)->orderByDesc('id')
                ->first(['id', 'fallas_detectadas', 'trabajos_sugeridos']);
            $i->tiene_diagnostico = $d !== null;
            $i->diagnostico = $d ? Str::limit((string) $d->fallas_detectadas, 4000) : null;
            $i->trabajos_sugeridos = $d ? Str::limit((string) $d->trabajos_sugeridos, 2000) : null;
            return $i;
        });
        return response()->json(['reporte' => $reporte, 'instrumentos' => $instrumentos,
            'nota' => 'Solo se cotizan instrumentos con diagnóstico registrado. El registro vuelve a validar cliente, permisos y cotizaciones pendientes.']);
    }

    public function registrarServicioDesdeInforme(Request $request)
    {
        [, , $puedeEditar] = $this->acceso($request);
        abort_unless($puedeEditar, 403, 'Tu rol no puede crear cotizaciones.');
        $data = $request->validate([
            'reporte_id' => ['required', 'integer', 'min:1'],
            'instrumentos' => ['required', 'array', 'min:1', 'max:50'],
            'instrumentos.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'instrumentos.*.precio' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'instrumentos.*.titulo_servicio' => ['nullable', 'string', 'max:255'],
            'instrumentos.*.trabajos' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            $reporte = $this->informesVisibles($request)->where('r.id', $data['reporte_id'])
                ->lockForUpdate()->first(['r.id', 'r.cliente_id']);
            abort_unless($reporte, 404, 'Informe no encontrado.');
            $instrumentos = [];
            foreach ($data['instrumentos'] as $linea) {
                $i = DB::table('reportes_instrumentos')->where('reporte_id', $reporte->id)
                    ->where('id', $linea['id'])->lockForUpdate()->first();
                if (! $i) {
                    $this->fallo('instrumentos', 'Un instrumento no pertenece al reporte seleccionado.');
                }
                $d = DB::table('diagnosticos')->where('reporte_instrumento_id', $i->id)
                    ->orderByDesc('id')->lockForUpdate()->first();
                if (! $d) {
                    $this->fallo('instrumentos', "El instrumento {$i->id} no tiene diagnóstico registrado.");
                }
                $tipo = $this->sinAcentos((string) $i->tipo_instrumento);
                $tipo = match (true) {
                    $tipo === '1', str_contains($tipo, 'balanza') => 'Balanza',
                    $tipo === '2', str_contains($tipo, 'term'), str_contains($tipo, 'temp') => 'Termómetro',
                    $tipo === '3', str_contains($tipo, 'pesa'), str_contains($tipo, 'masas') => 'Pesa',
                    default => null,
                };
                if ($tipo === null) {
                    $this->fallo('instrumentos', "Tipo de instrumento no compatible en el instrumento {$i->id}.");
                }
                $instrumentos[] = [
                    'tipo' => $tipo,
                    'precio' => $linea['precio'],
                    'titulo_servicio' => $linea['titulo_servicio'] ?? null,
                    'trabajos' => $linea['trabajos'] ?? $d->trabajos_sugeridos ?? '',
                    'marca' => $i->marca,
                    'modelo' => $i->modelo,
                    'numero_serie' => $i->serie,
                    'capacidad_rango' => $i->capacidad_rango,
                    'resolucion_division' => $i->resolucion_division,
                    'clase_tipo_metrologico' => $i->clase_tipo_metrologico,
                    'material' => $i->material,
                ];
            }
            // Copiar solo condiciones comerciales; nunca aceptar cliente, asesor, totales o ficha del agente.
            $interno = Request::create($request->path(), 'POST', array_merge($request->only([
                'plantilla_id', 'fecha', 'descripcion', 'moneda', 'forma_pago', 'garantia',
                'plazo_entrega', 'validez', 'condiciones',
            ]), ['cliente_id' => $reporte->cliente_id, 'instrumentos' => $instrumentos]));
            $interno->setUserResolver($request->getUserResolver());
            return $this->guardarServicio($interno, (int) $reporte->id);
        });
    }
}
