<?php

namespace Tests\Feature;

use App\Http\Controllers\Mcp\McpHojasSalidaController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class McpEditarInstrumentoHojaSalidaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'hojas_edicion_test', 'database.connections.hojas_edicion_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'logging.default' => 'stderr', 'mcp.roles_acceso_total' => ['Gerencia'],
            'mcp.hojas_salida_roles' => ['Operaciones', 'Servicio Tecnico', 'Area de TI'],
            'mcp.hojas_salida_roles_ve_todo' => ['Operaciones', 'Area de TI'],
            'mcp.hojas_salida_roles_edicion' => ['Operaciones', 'Servicio Tecnico', 'Area de TI']]);
        DB::purge('hojas_edicion_test');
        Schema::create('tb_tareas_tecnicos', function (Blueprint $t) { $t->id(); $t->string('estado'); });
        Schema::create('tb_tecnicos_existentes', function (Blueprint $t) { $t->id(); $t->integer('user_id'); });
        Schema::create('tb_detalles_tarea', function (Blueprint $t) { $t->id(); $t->integer('id_tarea'); $t->integer('id_tecnico'); });
        Schema::create('tb_instrumento_tarea', function (Blueprint $t) {
            $t->id(); $t->integer('tarea_id'); $t->integer('acta_id')->nullable();
            foreach (['tipo_instrumento', 'tipo_servicio', 'marca', 'modelo', 'serie', 'codigo_int', 'capacidad_rango',
                'resolucion_division', 'clase_tipo_metrologico', 'clase_exactitud', 'material', 'observaciones',
                'puntos_calibracion', 'fecha_calibracion', 'hora_servicio', 'n_cotizacion'] as $campo) $t->text($campo)->nullable();
            $t->timestamps();
        });
        Schema::create('tb_instrumento_pendiente', function (Blueprint $t) { $t->id(); $t->string('marca'); });
        Schema::create('reportes', function (Blueprint $t) { $t->id(); $t->integer('tarea_id'); });
        DB::table('tb_tareas_tecnicos')->insert([['id' => 1, 'estado' => 'pendiente'], ['id' => 2, 'estado' => 'pendiente']]);
        DB::table('tb_tecnicos_existentes')->insert(['id' => 7, 'user_id' => 9]);
        DB::table('tb_detalles_tarea')->insert(['id_tarea' => 1, 'id_tecnico' => 7]);
        foreach ([1, 2] as $id) DB::table('tb_instrumento_tarea')->insert(['id' => $id, 'tarea_id' => $id,
            'tipo_instrumento' => 'Balanza', 'tipo_servicio' => 'Calibracion', 'marca' => 'Anterior', 'modelo' => 'M1', 'serie' => 'S1', 'observaciones' => str_repeat('a', 600)]);
        DB::table('tb_instrumento_pendiente')->insert(['id' => 1, 'marca' => 'Original']);
    }

    private function request(array $data = [], string $rol = 'Servicio Tecnico', int $usuario = 9): Request
    {
        $request = Request::create('/', 'POST', $data);
        $request->setUserResolver(fn () => (object) ['id' => $usuario, 'roles' => collect([(object) ['name' => $rol]])]);
        return $request;
    }

    private function detalle(string $rol = 'Servicio Tecnico', int $hoja = 1, int $instrumento = 1): array
    {
        return (new McpHojasSalidaController)->instrumentos($this->request(['instrumento_id' => $instrumento], $rol), $hoja)->getData(true)['data'][0];
    }

    private function payload(array $cambios = ['marca' => 'Nueva']): array
    {
        return ['confirmado' => true, 'version_actual' => $this->detalle()['version_actual'], 'cambios' => $cambios];
    }

    public function test_partial_update_changes_only_the_copy_and_keeps_types_links_and_omitted_fields(): void
    {
        $detalle = $this->detalle();
        $this->assertSame(str_repeat('a', 600), $detalle['observaciones']);
        $this->assertContains('editar_datos', $detalle['acciones_permitidas']);
        $result = (new McpHojasSalidaController)->editarInstrumento($this->request($this->payload(['marca' => 'Nueva', 'observaciones' => '', 'fecha_calibracion' => '2026-10-28', 'hora_servicio' => '09:30'])), 1, 1)->getData(true);
        $this->assertTrue($result['actualizado']);
        $this->assertSame('Anterior', $result['cambios']['marca']['anterior']);
        $this->assertSame('Nueva', $result['instrumento']['marca']);
        $this->assertNull($result['instrumento']['observaciones']);
        $this->assertSame('M1', $result['instrumento']['modelo']);
        $this->assertSame('Balanza', $result['instrumento']['tipo_instrumento']);
        $this->assertSame('Calibracion', $result['instrumento']['tipo_servicio']);
        $this->assertNotSame($detalle['version_actual'], $result['instrumento']['version_actual']);
        $this->assertSame('Original', DB::table('tb_instrumento_pendiente')->value('marca'));
        $this->assertSame('Anterior', DB::table('tb_instrumento_tarea')->where('id', 2)->value('marca'));
    }

    public function test_all_confirmed_roles_can_edit_but_technician_cannot_access_another_sheet(): void
    {
        foreach (['Servicio Tecnico', 'Operaciones', 'Gerencia', 'Area de TI'] as $rol) {
            $result = (new McpHojasSalidaController)->editarInstrumento($this->request($this->payload(['marca' => $rol]), $rol), 1, 1)->getData(true);
            $this->assertTrue($result['actualizado']);
        }
        foreach (['Operaciones', 'Gerencia', 'Area de TI'] as $rol) {
            $datos = ['confirmado' => true, 'version_actual' => $this->detalle($rol, 2, 2)['version_actual'], 'cambios' => ['marca' => $rol]];
            $this->assertTrue((new McpHojasSalidaController)->editarInstrumento($this->request($datos, $rol), 2, 2)->getData(true)['actualizado']);
        }
        $this->expectException(HttpException::class);
        try { (new McpHojasSalidaController)->editarInstrumento($this->request($this->payload()), 2, 2); }
        catch (HttpException $e) { $this->assertSame(404, $e->getStatusCode()); throw $e; }
    }

    public function test_completed_is_the_only_blocking_state_even_with_actas_or_reports(): void
    {
        DB::table('tb_instrumento_tarea')->where('id', 1)->update(['acta_id' => 45]);
        DB::table('reportes')->insert(['id' => 50, 'tarea_id' => 1]);
        foreach (['pendiente', 'en_proceso', 'pausada', 'cancelada'] as $estado) {
            DB::table('tb_tareas_tecnicos')->where('id', 1)->update(['estado' => $estado]);
            $this->assertTrue((new McpHojasSalidaController)->editarInstrumento($this->request($this->payload(['marca' => $estado])), 1, 1)->getData(true)['actualizado']);
        }
        $payload = $this->payload(); // Task closes after the reading.
        DB::table('tb_tareas_tecnicos')->where('id', 1)->update(['estado' => 'completada']);
        foreach (['Servicio Tecnico', 'Operaciones', 'Gerencia', 'Area de TI'] as $rol) {
            $this->assertSame([], $this->detalle($rol)['acciones_permitidas']);
            try { (new McpHojasSalidaController)->editarInstrumento($this->request($payload, $rol), 1, 1); $this->fail('Must block completed'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('hoja', $e->errors()); }
        }
        $this->assertSame(45, DB::table('tb_instrumento_tarea')->where('id', 1)->value('acta_id'));
        $this->assertSame(1, DB::table('reportes')->count());
    }

    public function test_foreign_instrument_and_unauthorized_roles_are_rejected(): void
    {
        $payload = $this->payload();
        foreach ([['Gerencia', 2, 404], ['Ventas', 1, 403]] as [$rol, $instrumento, $status]) {
            try { (new McpHojasSalidaController)->editarInstrumento($this->request($payload, $rol), 1, $instrumento); $this->fail('Must reject'); }
            catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
        }
        config(['mcp.hojas_salida_roles_edicion' => []]);
        $this->assertSame([], $this->detalle()['acciones_permitidas']);
        try { (new McpHojasSalidaController)->editarInstrumento($this->request($payload), 1, 1); $this->fail('Role not allowed to edit'); }
        catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertTrue((new McpHojasSalidaController)->editarInstrumento($this->request($payload, 'Gerencia'), 1, 1)->getData(true)['actualizado']);
    }

    public function test_validation_rejects_protected_fields_and_invalid_changes_without_partial_writes(): void
    {
        $invalid = [['tipo_instrumento' => 'Otro'], ['tipo_servicio' => 'Venta'], ['tarea_id' => 2], ['acta_id' => 9], ['id' => 2], ['estado_operativo' => 'baja'], ['modelo' => str_repeat('x', 101)], ['fecha_calibracion' => '2026-02-30'], ['hora_servicio' => '25:00'], ['marca' => ['mal']], []];
        foreach ($invalid as $changes) {
            $payload = $this->payload($changes);
            try { (new McpHojasSalidaController)->editarInstrumento($this->request($payload), 1, 1); $this->fail('Must reject invalid data'); }
            catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
            $this->assertSame('Anterior', DB::table('tb_instrumento_tarea')->where('id', 1)->value('marca'));
        }
    }

    public function test_confirmation_and_fresh_version_are_required(): void
    {
        $payload = $this->payload();
        try { (new McpHojasSalidaController)->editarInstrumento($this->request(array_replace($payload, ['confirmado' => false])), 1, 1); $this->fail('Must confirm'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('confirmado', $e->errors()); }
        DB::table('tb_instrumento_tarea')->where('id', 1)->update(['modelo' => 'Cambio simultaneo']);
        try { (new McpHojasSalidaController)->editarInstrumento($this->request($payload), 1, 1); $this->fail('Must detect stale version'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('version_actual', $e->errors()); }
        $this->assertSame('Anterior', DB::table('tb_instrumento_tarea')->where('id', 1)->value('marca'));
    }

    public function test_noop_preserves_version_and_write_failure_rolls_back(): void
    {
        $payload = $this->payload(['marca' => 'Anterior']);
        $result = (new McpHojasSalidaController)->editarInstrumento($this->request($payload), 1, 1)->getData(true);
        $this->assertFalse($result['actualizado']);
        $this->assertSame($payload['version_actual'], $result['instrumento']['version_actual']);
        DB::unprepared("CREATE TRIGGER fail_edit BEFORE UPDATE ON tb_instrumento_tarea BEGIN SELECT RAISE(ABORT, 'blocked update'); END");
        try { (new McpHojasSalidaController)->editarInstrumento($this->request($this->payload()), 1, 1); $this->fail('Expected database failure'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('blocked update', $e->getMessage()); }
        $this->assertSame('Anterior', DB::table('tb_instrumento_tarea')->where('id', 1)->value('marca'));
    }
}
