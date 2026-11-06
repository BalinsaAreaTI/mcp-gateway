<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
@page { margin: 10mm 10mm 15mm; }
body { font-family: Helvetica, sans-serif; color: #000; font-size: 8pt; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
td, th { vertical-align: top; word-wrap: break-word; }
.grid td,.grid th { border: .3mm solid #000; padding: 2mm; }
.header { height: 22mm; } .header td { border: 0; }
.logo { width: 60mm; height: 20mm; } .empresa { text-align:right; font-size:10pt; line-height: 4mm; }
h1 { text-align:right; font-size:12pt; margin: 6mm 0 3mm; }
.label { font-weight:bold; } .equipo { margin-top:6mm; }
.section { margin-top:5mm; }
.section thead { display:table-header-group; }
.section th { border:.3mm solid #000; border-bottom:0; text-align:left; }
.section td { border-left:.3mm solid #000; border-right:.3mm solid #000; }
.section .last { border-bottom:.3mm solid #000; }
.section-title { font-weight:bold; padding:2mm 2mm 1mm; page-break-after:avoid; }
.text { font-weight:bold; padding:1mm 8mm 2mm; white-space:pre-wrap; word-wrap:break-word; }
.photos { margin-top:11mm; page-break-inside:avoid; }
.photos h2 { font-size:10pt; margin:0 0 2mm; }
.photos td { height:61.3mm; padding:1mm; vertical-align:middle; text-align:center; }
.photos th { text-align:center; background:#2980b9; color:#fff; }
.photo { max-width:60mm; max-height:60mm; }
tr { page-break-inside:avoid; }
</style></head><body>
<table class="header"><tr><td>@if($logo)<img class="logo" src="{{ $logo }}">@endif</td><td class="empresa">{{ $empresa['direccion'] }}<br>{{ $empresa['telefonos'] }}<br>{{ $empresa['correo'] }}<br>{{ $empresa['ruc'] }}</td></tr></table>
<h1>REPORTE TECNICO: N° {{ $numero }}</h1>
<table class="grid"><tr><td class="label">DATOS GENERALES</td></tr></table>
<table class="grid"><colgroup><col style="width:40mm"><col style="width:55mm"><col style="width:30mm"><col></colgroup><tr><td class="label">Técnico Responsable</td><td>{{ $tecnicos }}</td><td class="label">Fecha de Registro</td><td>{{ $fechas }}</td></tr></table>
<table class="grid"><tr><td class="label" style="width:36mm">Cliente</td><td>{{ $cliente }}</td></tr></table>
@if($titulo_equipo)
<table class="grid equipo"><tr><td class="label">{{ $titulo_equipo }}</td></tr></table>
<table class="grid"><colgroup><col style="width:40mm"><col style="width:50mm"><col style="width:40mm"><col></colgroup>@foreach($filas_equipo as $fila)<tr>@foreach($fila as $columna=>$valor)<td @if($columna%2===0) class="label" @endif>{{ $valor }}</td>@endforeach</tr>@endforeach</table>
<table class="grid" style="width:90mm"><tr><td class="label" style="width:36mm">{{ $ultima_fila[0] }}</td><td>{{ $ultima_fila[1] }}</td></tr></table>
@endif
<table class="section"><thead><tr><th class="section-title">INFORME TECNICO :</th></tr></thead><tbody>@foreach($informe_bloques as $bloque)<tr><td class="text {{ $loop->last ? 'last' : '' }}">{{ $bloque }}</td></tr>@endforeach</tbody></table>
<table class="section"><thead><tr><th class="section-title">RESULTADO :</th></tr></thead><tbody>@foreach($resultado_bloques as $bloque)<tr><td class="text {{ $loop->last ? 'last' : '' }}">{{ $bloque }}</td></tr>@endforeach</tbody></table>
@foreach($fotos as $grupo)
<div class="photos"><h2>{{ $grupo['titulo'] }}</h2><table class="grid"><thead><tr><th>FOTO 1</th><th>FOTO 2</th><th>FOTO 3</th></tr></thead><tbody><tr>@foreach($grupo['imagenes'] as $imagen)<td>@if($imagen)<img class="photo" src="{{ $imagen }}">@else Sin Imagen @endif</td>@endforeach</tr></tbody></table></div>
@endforeach
</body></html>
