<?php

namespace App\Http\Controllers\Mcp;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class McpCertificacionesArchivosController extends McpCertificacionesEscrituraController
{
    protected function enlace(string $ruta, array $parametros): array
    {
        $vence = now()->addMinutes(10);
        $relativa = URL::temporarySignedRoute($ruta, $vence, $parametros, false);

        return ['url' => rtrim(config('app.url'), '/').$relativa, 'vence_en' => $vence->toIso8601String(),
            'vence_en_minutos' => 10, 'requiere_sesion_web' => true];
    }

    protected function firma(Request $r): void
    {
        abort_unless($r->hasValidSignatureWhileIgnoring(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'], false), 403, 'El enlace venció o fue modificado. Solicita uno nuevo.');
        abort_unless($r->user() && (string) $r->user()->id === (string) $r->query('u'), 403, 'Abre el enlace con la cuenta del mismo empleado.');
        $this->acceso($r);
    }

    private function destino(int $id, int $instrumento, ?string $serie): void
    {
        $i = DB::table('tb_instrumento')->where('id_certificado', $id)->where('id_Instrumento', $instrumento)->first();
        abort_unless($i, 404, 'Instrumento no encontrado.');
        $series = json_decode($i->serie ?? '[]', true);
        if (! is_array($series)) {
            $this->fallo('serie', 'Las series guardadas no son válidas; revísalas en el sistema.');
        }
        $nombres = array_map(fn ($s) => is_string($s) ? $s : ($s['num_serie'] ?? $s['num'] ?? $s['serie'] ?? $s['identificacion'] ?? ''), $series);
        if ($nombres) {
            if (count(array_filter($nombres, fn ($s) => (string) $s === (string) $serie)) !== 1) {
                $this->fallo('serie', 'Selecciona una serie única del instrumento. Las series ambiguas requieren revisión en el sistema.');
            }
        } elseif ($serie !== null && $serie !== '') {
            $this->fallo('serie', 'Este instrumento no tiene series registradas.');
        }
        $q = DB::table('tb_certificado_archivo')->where('id_certificado', $id)->where('id_instrumento', $instrumento);
        $q->where(function ($q) use ($serie) {
            $q->where('serie', $serie ?? '');
            if ($serie === null || $serie === '') {
                $q->orWhereNull('serie');
            }
        });
        if ($q->exists()) {
            $this->fallo('serie', 'Ya hay archivos para esta serie. Consulta los archivos existentes.');
        }
    }

    public function preparar(Request $r, int $id)
    {
        $this->escritura($r, 'subir');
        $v = $this->confirmar($r);
        $data = $r->validate(['instrumento_id' => ['required', 'integer', 'min:1'], 'serie' => ['nullable', 'string', 'max:255']]);
        $token = bin2hex(random_bytes(32));
        $link = $this->enlace('mcp.certificaciones.subir', ['id' => $id, 'u' => $r->user()->id, 'token' => $token]);
        DB::transaction(function () use ($r, $id, $v, $data, $token, $link) {
            $this->bloquear($id, $v['version_actual']);
            $this->destino($id, (int) $data['instrumento_id'], $data['serie'] ?? null);
            DB::table('mcp_certificacion_subidas')->insert(['solicitud_id' => $id, 'instrumento_id' => $data['instrumento_id'],
                'user_id' => $r->user()->id, 'serie' => $data['serie'] ?? null, 'token_hash' => hash('sha256', $token), 'version' => $v['version_actual'],
                'expires_at' => Carbon::parse($link['vence_en']), 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->auditar($r, 'preparar_subida', ['id' => $id]);

        return response()->json(['id' => $id, 'url_subida' => $link['url']] + Arr::except($link, ['url']) +
            ['nota' => 'Enlace de un solo uso. Abre tú el navegador, inicia sesión, selecciona los PDF y confirma. Hasta 5 archivos de 50 MB cada uno.']);
    }

    private function ticket(Request $r, int $id, bool $lock = false)
    {
        $token = $r->query('token');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token), 403, 'Enlace inválido.');
        $q = DB::table('mcp_certificacion_subidas')->where('token_hash', hash('sha256', $token))->where('solicitud_id', $id)->where('user_id', $r->user()->id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $t = $q->first();
        abort_unless($t, 404, 'Enlace no encontrado.');
        abort_if($t->used_at !== null, 410, 'El enlace ya fue utilizado.');
        abort_if(now()->greaterThanOrEqualTo($t->expires_at), 410, 'El enlace venció.');

        return $t;
    }

    private function cabeceras($response)
    {
        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function formulario(Request $r, int $id)
    {
        $this->firma($r);
        $this->escritura($r, 'subir');
        $row = $this->registro($r, $id);
        $t = $this->ticket($r, $id);
        if (! hash_equals($this->version($id), $t->version)) {
            $this->fallo('version_actual', 'La solicitud cambió. Pide un nuevo enlace.');
        }
        $this->destino($id, $t->instrumento_id, $t->serie);

        return $this->cabeceras(response()->view('mcp.certificacion-subir', ['solicitud' => $row, 'ticket' => $t, 'action' => $r->fullUrl()]));
    }

    public function guardar(Request $r, int $id)
    {
        $this->firma($r);
        $this->escritura($r, 'subir');
        $this->ticket($r, $id);
        $data = $r->validate(['confirmado' => ['required', 'accepted'], 'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:51200']]);
        foreach ($data['files'] as $file) {
            if (strtolower($file->getClientOriginalExtension()) !== 'pdf' || file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-') {
                $this->fallo('files', 'Selecciona archivos PDF válidos.');
            }
        }
        $paths = [];
        try {
            DB::transaction(function () use ($r, $id, $data, &$paths) {
                $t = $this->ticket($r, $id, true);
                $this->bloquear($id, $t->version);
                $this->destino($id, $t->instrumento_id, $t->serie);
                foreach ($data['files'] as $file) {
                    $path = $file->storeAs($id.'/'.$t->instrumento_id, bin2hex(random_bytes(24)).'.pdf', 'certificados');
                    if (! $path) {
                        $this->fallo('files', 'No se pudo almacenar el archivo. Intenta de nuevo.');
                    }
                    $paths[] = $path;
                    DB::table('tb_certificado_archivo')->insert(['id_certificado' => $id, 'id_instrumento' => $t->instrumento_id,
                        'serie' => $t->serie, 'nombre_original' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                        'ruta' => $path, 'mime' => 'application/pdf', 'size' => $file->getSize(), 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('tb_certificado')->where('idCertificado', $id)->update(['estadoRenovacion' => 'Recibido', 'updated_at' => now()]);
                DB::table('mcp_certificacion_subidas')->where('id', $t->id)->update(['used_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            foreach ($paths as $path) {
                Storage::disk('certificados')->delete($path);
            }
            throw $e;
        }
        $this->auditar($r, 'archivos_guardados', ['id' => $id]);

        return $this->cabeceras(response()->view('mcp.certificacion-subida-exito', ['id' => $id, 'cantidad' => count($paths)]));
    }

    private function archivo(Request $r, int $id, int $archivoId)
    {
        $this->registro($r, $id);
        $f = DB::table('tb_certificado_archivo')->where('id_certificado', $id)->where('id_archivo', $archivoId)->first();
        abort_unless($f, 404, 'Archivo no encontrado.');

        return $f;
    }

    public function enlaceArchivo(Request $r, int $id, int $archivoId)
    {
        $this->archivo($r, $id, $archivoId);
        $data = $r->validate(['modo' => ['nullable', Rule::in(['ver', 'descargar'])]]);
        $this->auditar($r, 'enlace_archivo', ['id' => $id]);

        return response()->json($this->enlace('mcp.certificaciones.archivo', ['id' => $id, 'archivoId' => $archivoId, 'u' => $r->user()->id, 'd' => ($data['modo'] ?? 'ver') === 'descargar' ? 1 : 0]));
    }

    public function abrirArchivo(Request $r, int $id, int $archivoId)
    {
        $this->firma($r);
        $f = $this->archivo($r, $id, $archivoId);
        $root = realpath(Storage::disk('certificados')->path(''));
        $path = realpath(Storage::disk('certificados')->path($f->ruta));
        abort_unless($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path), 404, 'Archivo no encontrado.');
        abort_unless(file_get_contents($path, false, null, 0, 5) === '%PDF-', 422, 'El archivo guardado no es un PDF válido.');
        $this->auditar($r, 'abrir_archivo');
        $headers = ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];

        return $r->query('d') === '1' ? response()->download($path, 'certificado-'.$archivoId.'.pdf', $headers)
            : response()->file($path, $headers + ['Content-Disposition' => 'inline; filename="certificado-'.$archivoId.'.pdf"']);
    }
}
