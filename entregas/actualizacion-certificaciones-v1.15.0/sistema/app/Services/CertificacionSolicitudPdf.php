<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Proyección del PDF de Index.vue. No emite un certificado de calibración. */
class CertificacionSolicitudPdf
{
    public function preparar(array $datos): array
    {
        $general = $datos['general'];
        $filas = [];
        $conteo = [];
        foreach ($datos['instrumentos'] as $i) {
            $tipo = mb_strtoupper($i['instrumento'] ?? $i['tipoInstrumento'] ?? '');
            $series = $i['series'] ?? [];
            if (! $series) {
                $series = [null];
            }
            foreach ($series as $s) {
                $s = is_string($s) ? ['num_serie' => $s] : ($s ?? []);
                $campos = ['Instrumento' => $tipo];
                if (! empty($i['marca'])) {
                    $campos['Marca'] = $i['marca'];
                }
                $balanza = str_contains($tipo, 'BALANZA');
                $pesa = str_contains($tipo, 'PESA');
                $termo = str_contains($tipo, 'TERMOMETRO') || str_contains($tipo, 'TERMÓMETRO');
                $categoria = $balanza ? 'Balanza' : ($pesa ? 'Pesa' : ((str_contains($tipo, 'TERMÓMETRO') || $termo) ? 'Termómetro' : 'Instrumento'));
                $conteo[$categoria] = ($conteo[$categoria] ?? 0) + 1;
                if (($balanza || $termo) && ! empty($i['modelo'])) {
                    $campos['Modelo'] = $i['modelo'];
                }
                $numero = $s['num_serie'] ?? $s['num'] ?? '';
                $ident = $s['identificacion'] ?? $s['id'] ?? '';
                if ($balanza || $termo) {
                    $campos['Número de serie'] = $numero;
                    $campos['Identificación'] = $ident;
                    if ($balanza) {
                        $campos['Ubicación'] = $s['ubicacion'] ?? '';
                    }
                }
                if ($balanza || $pesa || $termo) {
                    $campos['Procedencia'] = $i['procedencia'] ?? '';
                }
                if ($balanza) {
                    $campos['Capacidad maxima'] = $i['cap_maxima'] ?? '';
                    $campos['División de escala'] = $i['escalas'] ?? '';
                } elseif ($pesa) {
                    foreach (['Valor nominal' => 'valor_nominal', 'Forma' => 'forma', 'Material' => 'material', 'Clase de exactitud' => 'clase_exactitud'] as $label => $key) {
                        $campos[$label] = $i[$key] ?? '';
                    }
                    $campos['Número de serie'] = $numero ?: 'NO INDICA';
                    $campos['Cod. de Identificación'] = $ident;
                } elseif ($termo) {
                    foreach (['Desde (°C)' => 'desde_medicion', 'Hasta (°C)' => 'hasta_medicion', 'Resolución' => 'resolucion'] as $label => $key) {
                        $v = trim((string) ($i[$key] ?? ''));
                        $campos[$label] = $v === '' ? '' : preg_replace('/\s*(?:\x{00B0}\s*[Cc]?|[Cc]\s*\x{00B0}?)\s*$/u', '', $v).' C°';
                    }
                    $campos['Elemento sensor'] = $i['elemento_sensor'] ?? '';
                    // El servicio Vue transforma strings a objetos temperatura; el PDF MCP acepta ambos.
                    foreach (($i['puntosCalibracion'] ?? $i['puntos_calibrar'] ?? []) as $n => $p) {
                        $valor = is_array($p) ? ($p['temperatura'] ?? '') : $p;
                        $valor = is_scalar($valor) ? trim((string) $valor) : '';
                        $campos['Punto a calibrar '.($n + 1)] = $valor === '' ? '' : preg_replace('/\\s*(?:\\x{00B0}\\s*[Cc]?|[Cc]\\s*\\x{00B0}?)\\s*$/u', '', $valor).' °C';
                    }
                }
                $filas[] = ['tipo' => $tipo, 'campos' => $campos];
            }
        }
        $partes = [];
        foreach (['Balanza' => 'Balanzas', 'Termómetro' => 'Termómetros', 'Pesa' => 'Pesas'] as $uno => $plural) {
            $n = $conteo[$uno] ?? 0;
            if ($n) {
                $partes[] = str_pad((string) $n, 2, '0', STR_PAD_LEFT).' '.($n === 1 ? $uno : $plural);
            }
        }
        if (! $partes) {
            $partes[] = str_pad((string) count($filas), 2, '0', STR_PAD_LEFT).' '.(count($filas) === 1 ? 'Instrumento' : 'Instrumentos');
        }
        $raw = $general['fechaSolicitud'] ?: $general['createdAt'];
        $fecha = str_contains($raw, '/') ? CarbonImmutable::createFromFormat('!d/m/Y', substr($raw, 0, 10), 'America/Lima') : CarbonImmutable::parse($raw, 'America/Lima');

        return ['general' => $general, 'instrumentos' => $filas, 'cantidad' => implode(', ', $partes),
            'fecha' => Str::ucfirst($fecha->locale('es')->isoFormat('dddd DD [de] MMMM [de] YYYY'))];
    }
}
