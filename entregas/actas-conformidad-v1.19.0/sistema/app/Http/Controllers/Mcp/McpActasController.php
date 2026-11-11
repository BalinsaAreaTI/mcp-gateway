<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ActaConformidadController;
use App\Http\Controllers\ActaFirmadaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class McpActasController extends Controller
{
    private function acceso(Request $r): void
    {
        abort_unless($r->user(), 401);
        $roles = $r->user()->roles->pluck('name')->all();
        abort_unless(array_intersect($roles, array_merge(config('mcp.actas_roles', []), config('mcp.roles_acceso_total', ['Gerencia']))), 403, 'Tu rol no tiene acceso a las actas.');
    }

    private function registro(Request $r, int $id, bool $editar = false): object
    {
        $this->acceso($r);
        // El listado de ActaConformidadController no limita por titular.
        $acta = DB::table('acta_conformidad')->where('id', $id)->first();
        abort_unless($acta, 404, 'Acta no encontrada.');
        if ($editar) {
            abort_unless(array_intersect($r->user()->roles->pluck('name')->all(), config('mcp.actas_roles_carga', [])), 403, 'Tu rol no puede subir actas firmadas.');
            abort_if($this->cerrado($acta), 403, 'No se puede modificar el acta de un reporte finalizado.');
        }
        return $acta;
    }

    private function cerrado(object $acta): bool
    {
        return $acta->tarea_id && DB::table('reportes')->where('tarea_id', $acta->tarea_id)->where('estado_global', 'cerrado')->exists();
    }

    private function auditar(Request $r, string $accion, ?int $id = null): void
    {
        Log::info('mcp.actas', ['user_id' => $r->user()->id, 'accion' => $accion, 'acta_id' => $id]);
    }

    public function buscar(Request $r)
    {
        $this->acceso($r);
        $d = $r->validate([
            'id' => 'nullable|integer|min:1', 'tarea_id' => 'nullable|integer|min:1',
            'cliente' => 'nullable|string|max:100', 'estado' => ['nullable', Rule::in(['firmada', 'no_firmada'])],
            'desde' => 'nullable|date_format:Y-m-d',
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($r->filled('desde') ? ['after_or_equal:desde'] : [])],
            'pagina' => 'nullable|integer|min:1', 'limite' => 'nullable|integer|min:1|max:20',
        ]);
        $q = DB::table('acta_conformidad');
        foreach (['id', 'tarea_id', 'estado'] as $campo) {
            if (isset($d[$campo])) { $q->where($campo, $d[$campo]); }
        }
        if (!empty($d['cliente'])) { $q->where('razon_social_acta', 'like', '%'.addcslashes($d['cliente'], '\\%_').'%'); }
        if (!empty($d['desde'])) { $q->whereDate('fecha_acta', '>=', $d['desde']); }
        if (!empty($d['hasta'])) { $q->whereDate('fecha_acta', '<=', $d['hasta']); }
        $pagina = (int) ($d['pagina'] ?? 1);
        $limite = (int) ($d['limite'] ?? 10);
        $total = (clone $q)->count();
        $filas = $q->orderByDesc('id')->forPage($pagina, $limite)->get(['id', 'idActa', 'tarea_id', 'tipo_acta_conformidad', 'razon_social_acta', 'ruc_acta', 'fecha_acta', 'fecha_final_acta', 'estado', 'fecha_firma']);
        $this->auditar($r, 'buscar');
        return response()->json(['alcance' => 'todos', 'pagina' => $pagina, 'limite' => $limite, 'total' => $total, 'hay_mas' => $pagina * $limite < $total, 'data' => $filas]);
    }

    public function ver(Request $r, int $id)
    {
        $a = $this->registro($r, $id);
        $datos = (array) $a;
        unset($datos['foto_firmada'], $datos['archivo_orden']);
        $datos['tiene_archivo_firmado'] = !empty($a->foto_firmada);
        $datos['hoja_salida'] = $a->tarea_id ? DB::table('tb_tareas_tecnicos')->where('id', $a->tarea_id)->first(['id', 'cliente_id', 'lugar_ejecucion']) : null;
        $datos['instrumentos'] = DB::table('tb_instrumento_tarea')->where('acta_id', $id)->get(['id', 'tarea_id', 'tipo_instrumento', 'marca', 'modelo', 'serie', 'capacidad_rango', 'codigo_int']);
        $datos['puede_subir_firmada'] = !$this->cerrado($a) && (bool) array_intersect($r->user()->roles->pluck('name')->all(), config('mcp.actas_roles_carga', []));
        $this->auditar($r, 'ver', $id);
        return response()->json($datos);
    }

    private function enlace(Request $r, int $id, string $tipo)
    {
        $a = $this->registro($r, $id, $tipo === 'carga');
        if ($tipo === 'firmada') { $this->archivo($a); }
        $d = $r->validate(['modo' => ['nullable', Rule::in(['ver', 'descargar'])]]);
        $vence = now()->addMinutes(10);
        $url = URL::temporarySignedRoute('mcp.actas.'.$tipo, $vence, ['id' => $id, 'u' => $r->user()->id, 'd' => ($d['modo'] ?? 'ver') === 'descargar' ? 1 : 0], false);
        $this->auditar($r, 'enlace_'.$tipo, $id);
        return response()->json(['id' => $id, 'tarea_id' => $a->tarea_id, 'url' => rtrim(config('app.url'), '/').$url, 'modo' => $d['modo'] ?? 'ver', 'cliente' => $a->razon_social_acta, 'vence_en_minutos' => 10, 'requiere_sesion_mismo_usuario' => true]);
    }

    public function enlacePdf(Request $r, int $id) { return $this->enlace($r, $id, 'pdf'); }
    public function enlaceCarga(Request $r, int $id) { return $this->enlace($r, $id, 'carga'); }
    public function enlaceFirmada(Request $r, int $id) { return $this->enlace($r, $id, 'firmada'); }

    private function firma(Request $r): void
    {
        abort_unless($r->hasValidSignatureWhileIgnoring(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'], false), 403, 'Enlace inválido o vencido. Solicita uno nuevo al asistente.');
        abort_unless($r->user() && (string) $r->user()->id === (string) $r->query('u'), 403, 'Inicia sesión con el mismo usuario que solicitó el enlace.');
    }

    public function abrirCarga(Request $r, int $id)
    {
        $this->firma($r);
        $acta = $this->registro($r, $id, true);
        $this->auditar($r, 'abrir_carga', $id);
        return response()->view('mcp.acta-carga', ['acta' => $acta])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function guardar(Request $r, int $id)
    {
        $this->firma($r);
        $this->registro($r, $id, true);
        $r->validate(['confirmar' => 'required|accepted']);
        // Reutiliza validación MIME/tamaño, bloqueo y limpieza de la pantalla.
        app(ActaFirmadaController::class)->guardar($r, $id);
        $this->auditar($r, 'guardar_firmada', $id);
        return response()->view('mcp.acta-cargada', ['id' => $id])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function archivo(object $a): string
    {
        $ruta = $a->foto_firmada ?? '';
        abort_unless(preg_match('~^actas_firmadas/[a-zA-Z0-9_.-]+$~D', $ruta), 404, 'El acta no tiene archivo firmado disponible.');
        $base = realpath(public_path('actas_firmadas'));
        $real = realpath(public_path($ruta));
        abort_unless($base && $real && str_starts_with($real, $base.DIRECTORY_SEPARATOR) && is_file($real), 404, 'Archivo firmado no encontrado.');
        abort_unless(in_array(mime_content_type($real), ['image/jpeg', 'image/png', 'application/pdf'], true), 422, 'Formato de archivo no permitido.');
        return $real;
    }

    public function abrirFirmada(Request $r, int $id)
    {
        $this->firma($r);
        $archivo = $this->archivo($this->registro($r, $id));
        $this->auditar($r, 'abrir_firmada', $id);
        $headers = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];
        return $r->query('d') === '1' ? response()->download($archivo, basename($archivo), $headers) : response()->file($archivo, $headers);
    }

    public function abrirPdf(Request $r, int $id)
    {
        $this->firma($r);
        $this->registro($r, $id);
        $consulta = Request::create('/', 'GET', ['id' => $id]);
        $consulta->setUserResolver(fn () => $r->user());
        $datos = app(ActaConformidadController::class)->obtenerListadeActaClientesPdf($consulta)->getData(true);
        abort_unless($datos[0] ?? null, 404, 'Acta no encontrada.');
        $vista = app(\App\Services\ActaConformidadPdf::class)->preparar($datos[0]);
        $pdf = app('dompdf.wrapper')->loadView('mcp.acta-pdf', $vista)->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $this->auditar($r, 'abrir_pdf', $id);
        $response = $r->query('d') === '1' ? $pdf->download('ACTA-'.$id.'.pdf') : $pdf->stream('ACTA-'.$id.'.pdf');
        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff');
    }
}
