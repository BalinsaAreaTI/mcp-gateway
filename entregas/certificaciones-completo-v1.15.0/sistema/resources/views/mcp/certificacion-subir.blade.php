<!doctype html>
<html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Subir certificados</title><style>body{font-family:sans-serif;max-width:680px;margin:40px auto;padding:20px}label,p{display:block;margin:20px 0}button{padding:12px}</style>
<h1>Subir certificados</h1><p>Solicitud {{ $solicitud->id }} ? {{ $solicitud->cliente }}</p>
<p>Instrumento {{ $ticket->instrumento_id }} ? Serie: {{ $ticket->serie ?: 'Sin serie' }}</p>
<p>Selecciona hasta 5 PDF de 50 MB cada uno. Al guardar, este enlace dejará de funcionar.</p>
@if ($errors->any()) <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form method="post" enctype="multipart/form-data" action="{{ $action }}">@csrf
<label>Archivos PDF <input type="file" name="files[]" accept="application/pdf,.pdf" multiple required></label>
<label><input type="checkbox" name="confirmado" value="1" required> Confirmo que estos archivos corresponden a la solicitud, instrumento y serie indicados.</label>
<button type="submit">Guardar certificados</button></form></html>
