<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>Subir acta firmada</title></head>
<body><main><h1>Subir acta firmada N° {{ $acta->id }}</h1>
<p>{{ $acta->razon_social_acta }} · Hoja de salida: {{ $acta->tarea_id ?? 'Sin tarea' }}</p>
@if($acta->foto_firmada)<p>Ya existe un archivo firmado. Al guardar será reemplazado.</p>@endif
<p>Selecciona JPG, PNG o PDF, máximo 10 MB. El enlace vence a los 10 minutos; si vence solicita uno nuevo al asistente.</p>
@if($errors->any())<div role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="POST" enctype="multipart/form-data" action="{{ request()->fullUrl() }}">
@csrf
<label for="foto">Archivo del acta firmada</label>
<input id="foto" type="file" name="foto_firmada" accept="image/jpeg,image/png,application/pdf" required>
<p><label><input type="checkbox" name="confirmar" value="1" required>Confirmo que el archivo corresponde al acta N° {{ $acta->id }} y deseo guardarlo.</label></p>
<button type="submit">Guardar acta firmada</button>
</form></main></body></html>
