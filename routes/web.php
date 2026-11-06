<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', fn () => 'Gateway MCP activo.');

// Passport redirige aquí cuando el usuario no tiene sesión en el gateway.
Route::get('/login', function (Request $request) {
    $state = Str::random(40);
    $request->session()->put('sistema_state', $state);

    return redirect()->away(
        config('sistema.front_url') . '/mcp/conectar?' . http_build_query(['state' => $state])
    );
})->name('login');

// El sistema devuelve aquí al empleado con un código de un solo uso.
Route::get('/auth/callback', function (Request $request) {
    $code     = (string) $request->query('code');
    $state    = (string) $request->query('state');
    $expected = (string) $request->session()->pull('sistema_state');

    if ($expected === '' || ! hash_equals($expected, $state) || strlen($code) !== 64) {
        abort(403, 'Solicitud de conexión inválida o vencida. Vuelve a conectar desde tu agente.');
    }

    $res = Http::acceptJson()->timeout(15)
        ->withHeaders(['X-Bridge-Secret' => (string) config('sistema.bridge_secret')])
        ->post(config('sistema.api_url') . '/mcp-bridge/exchange', [
            'code'  => $code,
            'state' => $state,
        ]);

    if (! $res->successful()) {
        abort(403, 'No se pudo validar la conexión con el sistema.');
    }

    $remote = $res->json('user');
    $user = User::updateOrCreate(
        ['remote_user_id' => $remote['id']],
        [
            'name'         => $remote['name'],
            'email'        => $remote['email'],
            'remote_token' => $res->json('token'),
        ]
    );

    Auth::login($user);
    $request->session()->regenerate();

    return redirect()->intended('/');
});
