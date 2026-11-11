<?php

namespace App\Services;

use Carbon\Carbon;

class ActaConformidadPdf
{
    public function preparar(array $acta): array
    {
        $tipo = $acta['instrumento']['tipo_instrumento'] ?? '';
        $campos = preg_match('/pesa/i', $tipo)
            ? ['Material', 'Modelo', 'Serie', 'Capacidad', 'Código interno', 'Clase de exactitud']
            : (preg_match('/term|temp/i', $tipo)
                ? ['Marca', 'Modelo', 'Serie', 'Rango', 'Código interno']
                : ['Marca', 'Modelo', 'Serie', 'Capacidad', 'División', 'Código interno']);
        $caracteristicas = '___________, '.implode(', ', array_map(fn ($c) => $c.': ___________', $campos));
        $venta = ($acta['tipo_acta_conformidad'] ?? '') === 'Acta de Venta';
        $empresa = config('acta_pdf.proveedor');
        $cliente = $acta['razon_social_acta'] ?? '';
        $descripcion = trim($acta['trabajo_conformidad'] ?? '');
        $texto = $venta
            ? "Mediante la presente, dejamos constancia de la venta ejecutada por la empresa {$empresa}, así como las pruebas del producto(os) a entera satisfacción del cliente {$cliente}. En la venta de {$descripcion} para {$caracteristicas}."
            : "Mediante la presente, dejamos constancia que el servicio ejecutado por la empresa {$empresa}, así como las pruebas se ejecutaron a entera satisfacción del cliente {$cliente}. En el servicio de {$descripcion} para {$caracteristicas}, en óptimo estado.";
        $fecha = fn ($valor) => $valor ? Carbon::parse($valor)->format('d/m/Y') : '';
        $logo = public_path(config('acta_pdf.logo'));
        abort_unless(is_file($logo) && is_readable($logo), 422, 'Falta el logo configurado para el PDF del acta.');
        return [
            'a' => $acta, 'titulo' => $venta ? 'ACTA DE CONFORMIDAD DE VENTA' : 'ACTA DE CONFORMIDAD DEL SERVICIO',
            'texto' => $texto, 'inicio' => $fecha($acta['fecha_acta'] ?? null), 'fin' => $fecha($acta['fecha_final_acta'] ?? null),
            'logo' => 'data:image/png;base64,'.base64_encode(file_get_contents($logo)),
        ];
    }
}
