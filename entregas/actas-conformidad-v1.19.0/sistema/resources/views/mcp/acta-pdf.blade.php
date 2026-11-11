<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<style>
@page { margin: 10mm; }
body { font-family: Helvetica, sans-serif; font-size: 9.5pt; color: #000; }
table { width: 190mm; border-collapse: collapse; table-layout: fixed; }
td { padding: 2mm; vertical-align: top; overflow-wrap: break-word; }
tr { page-break-inside: avoid; }
.cabecera { font-size: 9pt; text-align: right; height: 23mm; }
.logo { width: 60mm; height: 20mm; }
h1 { text-align: center; font-size: 12pt; margin: 5mm 0 3mm; }
h2 { font-size: 12pt; margin: 0 0 3mm; }
.general { font-size: 8pt; }
.general td { border: .3mm solid black; }
.datos { margin-top: 5mm; }
.datos td { padding-bottom: 4mm; }
.etiqueta { width: 71mm; }
.texto { text-align: justify; }
.firmas { margin-top: 2mm; text-align: center; font-size: 11pt; font-weight: bold; }
.firmas td { padding: 40mm 10mm 5mm; }
.linea { border-top: .3mm solid black; padding-top: 4.5mm; }
</style></head><body>
<table class="cabecera"><tr><td style="text-align:left"><img class="logo" src="{{ $logo }}" alt="Balinsa"></td><td>{{ config('acta_pdf.direccion') }}<br><br>Telf: {{ config('acta_pdf.telefonos') }}<br><br>Correo: {{ config('acta_pdf.correo') }}<br><br>RUC: {{ config('acta_pdf.ruc') }}</td></tr></table>
<h1>{{ $titulo }}</h1><h2>N° DE ACTA: {{ $a['id'] }}</h2>
<table class="general"><tr><td colspan="6"><b>DATOS GENERALES</b></td></tr><tr>
<td><b>{{ $a['tipo_conformidad'] ?? '' }}</b></td><td>{{ $a['idActa'] ?? '' }}</td><td><b>Fecha</b></td><td>{{ $inicio }}</td><td><b>Lugar de Atencion</b></td><td>{{ $a['lugar_atencion'] ?? '' }}</td>
</tr><tr><td colspan="6"><b>SEÑORES: {{ $a['razon_social_acta'] ?? '' }}<br><br>RUC: {{ $a['ruc_acta'] ?? '' }}<br><br>DIRECCION: {{ $a['direccion_acta'] ?? '' }}</b></td></tr></table>
<table class="datos">
<tr><td class="etiqueta">{{ $a['tipo_conformidad'] ?? '' }}</td><td>: {{ $a['idActa'] ?? '' }}</td></tr>
<tr><td>Nombre del Proveedor</td><td>: {{ config('acta_pdf.proveedor') }}.</td></tr>
<tr><td>Cliente</td><td>: {{ $a['razon_social_acta'] ?? '' }}</td></tr>
<tr><td>Fecha de Inicio del Servicio</td><td>: {{ $inicio }}</td></tr>
<tr><td>Fecha de Fin del Servicio</td><td>: {{ $fin }}</td></tr>
<tr><td>Conformidad del Servicio</td><td class="texto">: {{ $texto }}</td></tr>
<tr><td>Fecha de conformidad</td><td>: {{ $fin }}</td></tr>
</table>
<p>En señal de conformidad se expide la presente constancia, firman a continuación las personas que intervinieron.</p>
<table class="firmas"><tr><td><div class="linea">{{ $a['razon_social_acta'] ?? '' }}<br>FIRMA</div></td><td><div class="linea">{{ mb_strtoupper($a['tecnico'] ?? '') }}<br>FIRMA</div></td></tr></table>
</body></html>
