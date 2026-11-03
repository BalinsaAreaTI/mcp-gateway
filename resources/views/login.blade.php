<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Iniciar sesión</title></head>
<body>
  <form method="POST" action="{{ route('login.attempt') }}">
    @csrf
    <input type="email" name="email" value="{{ old('email') }}" placeholder="Correo" required>
    <input type="password" name="password" placeholder="Contraseña" required>
    @error('email') <p>{{ $message }}</p> @enderror
    <button type="submit">Entrar</button>
  </form>
</body>
</html>
