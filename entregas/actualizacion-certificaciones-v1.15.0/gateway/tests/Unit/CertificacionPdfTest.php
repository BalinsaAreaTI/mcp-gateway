<?php

namespace Tests\Unit;

use App\Services\CertificacionSolicitudPdf;
use PHPUnit\Framework\TestCase;

class CertificacionPdfTest extends TestCase
{
    public function test_puntos_string_objeto_y_cero_no_desaparecen_del_pdf(): void
    {
        require_once dirname(__DIR__, 2).'/entregas/actualizacion-certificaciones-v1.15.0/sistema/app/Services/CertificacionSolicitudPdf.php';
        $vista = (new CertificacionSolicitudPdf)->preparar([
            'general' => ['fechaSolicitud' => '31/01/2026', 'createdAt' => '2026-01-31'],
            'instrumentos' => [['instrumento' => 'TERMÓMETRO', 'series' => [['num_serie' => 'T1']],
                'puntos_calibrar' => ['0', '100 °C', ['temperatura' => '50'], ['temperatura' => 0]]]],
        ]);
        $campos = $vista['instrumentos'][0]['campos'];
        $this->assertSame('0 °C', $campos['Punto a calibrar 1']);
        $this->assertSame('100 °C', $campos['Punto a calibrar 2']);
        $this->assertSame('50 °C', $campos['Punto a calibrar 3']);
        $this->assertSame('0 °C', $campos['Punto a calibrar 4']);
        $this->assertSame('T1', $campos['Número de serie']);
    }
}
