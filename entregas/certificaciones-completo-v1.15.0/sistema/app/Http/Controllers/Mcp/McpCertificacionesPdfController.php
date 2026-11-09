<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\SolicitudCertificacionController;
use App\Services\CertificacionSolicitudPdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class McpCertificacionesPdfController extends McpCertificacionesArchivosController
{
    public function prepararPdf(Request $r, int $id)
    {
        $this->registro($r, $id);
        $data = $r->validate(['modo' => ['nullable', Rule::in(['ver', 'descargar'])]]);
        $this->auditar($r, 'enlace_pdf_solicitud');

        return response()->json($this->enlace('mcp.certificaciones.solicitud_pdf', ['id' => $id, 'u' => $r->user()->id, 'd' => ($data['modo'] ?? 'ver') === 'descargar' ? 1 : 0]) +
            ['nota' => 'Solicitud de calibración, no certificado emitido. Se genera al abrir el enlace.']);
    }

    public function abrirPdf(Request $r, int $id)
    {
        $this->firma($r);
        $this->registro($r, $id);
        // Reutiliza el proveedor de datos de pantalla DESPUÉS de comprobar acceso.
        $datos = app(SolicitudCertificacionController::class)->detalleSolicitudCertificacion($r, $id)->getData(true);
        abort_unless($datos['general'] ?? null, 404, 'Solicitud no encontrada.');
        $vista = app(CertificacionSolicitudPdf::class)->preparar($datos);
        $pdf = app('dompdf.wrapper')->loadView('mcp.certificacion-solicitud-pdf', $vista)->setPaper('a4');
        $pdf->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $nombre = 'SOLICITUD '.preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u', '', $datos['general']['razonSocial']).'.pdf';
        $this->auditar($r, 'abrir_pdf_solicitud');
        $response = $r->query('d') === '1' ? $pdf->download($nombre) : $pdf->stream($nombre);

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff');
    }
}
