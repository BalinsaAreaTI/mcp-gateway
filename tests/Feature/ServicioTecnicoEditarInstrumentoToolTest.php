<?php

namespace Tests\Feature;

use App\Mcp\Servers\BalinsaServer;
use App\Mcp\Tools\ServicioTecnicoEditarInstrumentoHojaSalidaTool;
use App\Mcp\Tools\ServicioTecnicoVerInstrumentosHojaSalidaTool;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Tests\TestCase;

class ServicioTecnicoEditarInstrumentoToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'tools_test', 'database.connections.tools_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'sistema.api_url' => 'https://sistema.test/api']);
        DB::purge('tools_test');
        Schema::create('agent_actions', function (Blueprint $t) {
            $t->id();
            foreach (['user_id', 'remote_user_id', 'http_status', 'duracion_ms'] as $key) $t->integer($key)->nullable();
            foreach (['tool', 'metodo', 'ruta', 'params', 'estado', 'detalle', 'user_agent', 'created_at'] as $key) $t->text($key)->nullable();
        });
        $user = new User(['name' => 'Prueba', 'remote_user_id' => 9, 'remote_token' => 'token-ficticio']);
        $user->id = 1;
        $this->actingAs($user);
        Http::preventStrayRequests();
    }

    public function test_registered_schema_has_only_editable_fields(): void
    {
        $schema = (new ServicioTecnicoEditarInstrumentoHojaSalidaTool)->toArray();
        $this->assertLessThanOrEqual(64, strlen($schema['name']));
        $fields = $schema['inputSchema']['properties']['cambios'];
        $this->assertSame('object', $fields['type']);
        $this->assertFalse($fields['additionalProperties']);
        $this->assertArrayHasKey('marca', $fields['properties']);
        $this->assertArrayNotHasKey('tipo_instrumento', $fields['properties']);
        $this->assertArrayNotHasKey('tipo_servicio', $fields['properties']);
        $this->assertArrayNotHasKey('tarea_id', $fields['properties']);
        $defaults = (new \ReflectionClass(BalinsaServer::class))->getDefaultProperties();
        $this->assertContains(ServicioTecnicoEditarInstrumentoHojaSalidaTool::class, $defaults['tools']);
    }

    public function test_partial_payload_preserves_explicit_clears_and_is_audited_with_employee_token(): void
    {
        Http::fake(['https://sistema.test/*' => Http::response(['actualizado' => true, 'id' => 3], 200)]);
        $tool = new ServicioTecnicoEditarInstrumentoHojaSalidaTool;
        $result = $tool->handle(new Request(['id' => 3, 'instrumento_id' => 12,
            'version_actual' => str_repeat('a', 64), 'confirmado' => true,
            'cambios' => ['marca' => 'Nueva', 'observaciones' => '']]));
        $this->assertFalse($result->isError());
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://sistema.test/api/mcp/servicio-tecnico/hojas-salida/3/instrumentos/12/datos'
            && $r->hasHeader('Authorization', 'Bearer token-ficticio') && $r['cambios'] === ['marca' => 'Nueva', 'observaciones' => '']);
        $audit = DB::table('agent_actions')->first();
        $this->assertSame('ok', $audit->estado);
        $this->assertSame(9, $audit->remote_user_id);
        $this->assertSame('POST', $audit->metodo);
    }

    public function test_protected_fields_are_rejected_before_any_request(): void
    {
        Http::fake();
        foreach (['tipo_instrumento', 'tipo_servicio', 'tarea_id'] as $field) {
            $result = (new ServicioTecnicoEditarInstrumentoHojaSalidaTool)->handle(new Request(['id' => 3, 'instrumento_id' => 12, 'cambios' => ['marca' => 'Nueva', $field => 'otro']]));
            $this->assertTrue($result->isError());
        }
        Http::assertNothingSent();
    }

    public function test_detail_filter_is_forwarded_and_validation_failures_are_audited(): void
    {
        Http::fake(['https://sistema.test/*' => Http::sequence()->push(['data' => []])->push(['errors' => ['version_actual' => ['El instrumento cambió.']]], 422)]);
        (new ServicioTecnicoVerInstrumentosHojaSalidaTool)->handle(new Request(['id' => 3, 'instrumento_id' => 12]));
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r['instrumento_id'] === 12);
        $result = (new ServicioTecnicoEditarInstrumentoHojaSalidaTool)->handle(new Request(['id' => 3, 'instrumento_id' => 12, 'version_actual' => str_repeat('a', 64), 'confirmado' => true, 'cambios' => ['marca' => 'Nueva']]));
        $this->assertTrue($result->isError());
        $this->assertSame(422, DB::table('agent_actions')->orderByDesc('id')->value('http_status'));
    }
    public function test_pdf_number_and_group_are_forwarded_without_treating_them_as_an_id(): void
    {
        Http::fake(['https://sistema.test/*' => Http::response(['requiere_aclaracion' => false, 'data' => []])]);
        (new ServicioTecnicoVerInstrumentosHojaSalidaTool)->handle(new Request(['id' => 3, 'numero_pdf' => 2, 'grupo_pdf' => 'pesas_patron']));
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r['numero_pdf'] === 2 && $r['grupo_pdf'] === 'pesas_patron' && !isset($r['instrumento_id']));
    }

}
