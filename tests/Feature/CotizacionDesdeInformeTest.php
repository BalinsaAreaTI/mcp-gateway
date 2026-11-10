<?php

namespace Tests\Feature;

use App\Http\Controllers\Mcp\McpCotizacionesController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CotizacionDesdeInformeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('entregas/cotizacion-desde-informe-v1.18.0/sistema/app/Http/Controllers/Mcp/CotizacionesDesdeInforme.php');
        require_once base_path('entregas/cotizacion-desde-informe-v1.18.0/sistema/app/Http/Controllers/Mcp/McpCotizacionesController.php');
        if (! class_exists(\App\Models\C_Servicio::class)) {
            class_alias(\Tests\Fixtures\CotizacionServicioModel::class, \App\Models\C_Servicio::class);
        }
        config([
            'database.default' => 'cotizacion_test',
            'database.connections.cotizacion_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'mcp.cotizaciones_roles' => ['Asesor de Ventas', 'Administracion', 'Jefe de Ventas'],
            'mcp.cotizaciones_roles_ve_todo' => ['Administracion', 'Jefe de Ventas'],
            'mcp.cotizaciones_roles_edicion' => ['Asesor de Ventas', 'Jefe de Ventas'],
            'mcp.roles_acceso_total' => ['Gerencia'],
            'mcp.cotizaciones_permiso_ver_todo' => 'ver todo',
            'mcp.cotizacion_condiciones_servicio_generales' => ['Condición general'],
        ]);
        DB::purge('cotizacion_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('razonSocial'); $t->integer('asesor')->nullable();
            foreach (['numeroDocumento', 'direccion', 'fechafactura', 'estado_eliminado'] as $c) { $t->string($c)->nullable(); }
        });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('reportes', function (Blueprint $t) { $t->id(); $t->integer('cliente_id'); $t->string('n_servicio'); $t->string('fecha'); });
        Schema::create('reportes_instrumentos', function (Blueprint $t) {
            $t->id(); $t->integer('reporte_id');
            foreach (['tipo_instrumento', 'marca', 'modelo', 'serie', 'capacidad_rango', 'resolucion_division', 'clase_tipo_metrologico', 'material'] as $c) { $t->string($c)->nullable(); }
        });
        Schema::create('diagnosticos', function (Blueprint $t) { $t->id(); $t->integer('reporte_instrumento_id'); $t->text('fallas_detectadas'); $t->text('trabajos_sugeridos'); });
        Schema::create('plantillas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('cservicios', function (Blueprint $t) {
            $t->id(); $t->timestamps();
            foreach (['descripcion', 'estado_servicio', 'estado_cotserv', 'fecha', 'n_informe', 'razonSocial', 'cliente_id', 'moneda', 'fpago', 'garantia', 'plazo_entrega', 'validez', 'condiciones_servicio', 'subtotal', 'igv', 'total', 'id_plantilla', 'id_asesor', 'asesor', 'precio_unitario', 'n_cotizacion'] as $c) { $t->text($c)->nullable(); }
        });
        Schema::create('instru_cotserv_directa', function (Blueprint $t) {
            $t->id(); $t->timestamps();
            foreach (['id_cservicios', 'instrumento_id', 'instrumento_nombre', 'trabajos', 'precio_unitario', 'marca', 'modelo', 'numero_serie', 'capacidad_rango', 'resolucion_division', 'clase_tipo_metrologico', 'material', 'plataforma', 'clase_exactitud', 'bastago'] as $c) { $t->text($c)->nullable(); }
        });
        DB::table('clientes')->insert(['id' => 1, 'razonSocial' => 'Cliente A', 'asesor' => 7]);
        DB::table('plantillas')->insert(['id' => 1, 'nombre' => 'Mantenimiento']);
        DB::table('reportes')->insert(['id' => 9, 'cliente_id' => 1, 'n_servicio' => 'RPT-9', 'fecha' => now()->toDateString()]);
        DB::table('reportes_instrumentos')->insert(['id' => 21, 'reporte_id' => 9, 'tipo_instrumento' => 'Balanza', 'marca' => 'Marca real', 'modelo' => 'M1', 'serie' => 'S123']);
        DB::table('diagnosticos')->insert(['id' => 30, 'reporte_instrumento_id' => 21, 'fallas_detectadas' => 'Desajuste', 'trabajos_sugeridos' => 'Ajustar']);
    }

    private function request(array $extra = [], string $role = 'Asesor de Ventas'): Request
    {
        $request = Request::create('/api/mcp/ventas/cotizaciones/servicio/desde-informe', 'POST', array_replace([
            'reporte_id' => 9, 'plantilla_id' => 1, 'moneda' => 'Soles', 'forma_pago' => 'Contado',
            'instrumentos' => [['id' => 21, 'precio' => 100]],
        ], $extra));
        $user = new class {
            public $id = 7;
            public $name = 'Ana';
            public $apellidopat = 'Perez';
            public $apellidomat = '';
            public $roles;
            public function can($permission) { return false; }
        };
        $user->roles = collect([(object) ['name' => $role]]);
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    public function test_creates_linked_quote_with_server_owned_data_and_calculated_totals(): void
    {
        $result = (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request([
            'cliente_id' => 999, 'id_asesor' => 99, 'total' => 1,
            'instrumentos' => [['id' => 21, 'precio' => 123.456, 'marca' => 'Falsa']],
        ]));
        $this->assertSame(201, $result->getStatusCode());
        $body = $result->getData(true);
        $this->assertSame('desde_informe', $body['origen']);
        $this->assertSame(9, $body['reporte_id']);
        $this->assertEquals(145.68, $body['total']);
        $quote = DB::table('cservicios')->first();
        $this->assertSame('9', $quote->n_informe);
        $this->assertSame('1', $quote->cliente_id);
        $this->assertSame('7', $quote->id_asesor);
        $line = DB::table('instru_cotserv_directa')->first();
        $this->assertSame('Marca real', $line->marca);
        $this->assertSame('S123', $line->numero_serie);
        $this->assertSame('Ajustar', $line->trabajos);
        $this->assertSame('Desajuste', DB::table('diagnosticos')->value('fallas_detectadas'));
    }

    public function test_direct_registration_remains_direct(): void
    {
        $result = (new McpCotizacionesController)->registrarServicio($this->request([
            'cliente_id' => 1, 'instrumentos' => [['tipo' => 'Pesa', 'precio' => 10]],
        ]));
        $this->assertSame('directa', $result->getData(true)['origen']);
        $this->assertSame('DIRECTA', DB::table('cservicios')->value('n_informe'));
    }

    public function test_rejects_invalid_selection_and_rolls_back(): void
    {
        DB::table('reportes_instrumentos')->insert(['id' => 22, 'reporte_id' => 99, 'tipo_instrumento' => 'Pesa']);
        foreach ([ [['id' => 999, 'precio' => 10]], [['id' => 22, 'precio' => 10]], [['id' => 21, 'precio' => -1]], [['id' => 21, 'precio' => 10], ['id' => 21, 'precio' => 10]] ] as $lines) {
            try {
                (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request(['instrumentos' => $lines]));
                $this->fail('Expected validation error');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        DB::table('diagnosticos')->delete();
        try {
            (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request());
            $this->fail('Expected missing diagnosis error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('instrumentos', $e->errors());
        }
        $this->assertSame(0, DB::table('cservicios')->count());
        $this->assertSame(0, DB::table('instru_cotserv_directa')->count());
    }

    public function test_global_role_can_quote_other_advisors_client_and_override_commercial_work(): void
    {
        DB::table('clientes')->update(['asesor' => 88]);
        $result = (new McpCotizacionesController)->registrarServicioDesdeInforme($this->request([
            'instrumentos' => [['id' => 21, 'precio' => 0, 'titulo_servicio' => 'Servicio especial', 'trabajos' => 'Trabajo confirmado']],
        ], 'Gerencia'));
        $this->assertSame(201, $result->getStatusCode());
        $line = DB::table('instru_cotserv_directa')->first();
        $this->assertSame('Servicio especial', $line->instrumento_nombre);
        $this->assertSame('Trabajo confirmado', $line->trabajos);
        $this->assertEquals(0, $result->getData(true)['total']);
    }

    public function test_permissions_visibility_and_duplicate_pending_quote(): void
    {
        $controller = new McpCotizacionesController;
        try {
            $controller->registrarServicioDesdeInforme($this->request([], 'Administracion'));
            $this->fail();
        } catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        DB::table('clientes')->update(['asesor' => 88]);
        $this->assertSame(0, $controller->buscarInformesCotizables($this->request())->getData(true)['total']);
        try {
            $controller->verInformeCotizable($this->request(), 9);
            $this->fail();
        } catch (HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        DB::table('clientes')->update(['asesor' => 7]);
        $this->assertSame(1, $controller->buscarInformesCotizables($this->request())->getData(true)['total']);
        $this->assertSame('Ajustar', $controller->verInformeCotizable($this->request(), 9)->getData(true)['instrumentos']['data'][0]['trabajos_sugeridos']);
        $controller->registrarServicioDesdeInforme($this->request());
        try {
            $controller->registrarServicioDesdeInforme($this->request());
            $this->fail();
        } catch (ValidationException $e) { $this->assertArrayHasKey('cliente_id', $e->errors()); }
        $this->assertSame(1, DB::table('cservicios')->count());
    }
}
