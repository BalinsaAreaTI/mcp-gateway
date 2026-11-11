<?php
namespace Tests\Feature;

use App\Http\Controllers\Mcp\McpActasController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ActasConformidadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('entregas/actas-conformidad-v1.19.0/sistema/app/Http/Controllers/Mcp/McpActasController.php');
        config(['database.default' => 'actas_test', 'database.connections.actas_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'mcp.actas_roles' => ['Operaciones', 'Servicio Tecnico'], 'mcp.actas_roles_carga' => ['Operaciones', 'Servicio Tecnico', 'Gerencia'], 'mcp.roles_acceso_total' => ['Gerencia'], 'app.key' => 'test-secret', 'app.url' => 'https://sistema.test']);
        DB::purge('actas_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('acta_conformidad', function (Blueprint $t) {
            $t->id(); $t->integer('tarea_id')->nullable();
            foreach (['idActa','tipo_acta_conformidad','razon_social_acta','ruc_acta','fecha_acta','fecha_final_acta','estado','fecha_firma','foto_firmada','archivo_orden'] as $c) { $t->text($c)->nullable(); }
        });
        Schema::create('reportes', function (Blueprint $t) { $t->id(); $t->integer('tarea_id'); $t->string('estado_global'); });
        Schema::create('tb_tareas_tecnicos', function (Blueprint $t) { $t->id(); $t->integer('cliente_id'); $t->string('lugar_ejecucion'); });
        Schema::create('tb_instrumento_tarea', function (Blueprint $t) {
            $t->id(); $t->integer('acta_id'); $t->integer('tarea_id');
            foreach (['tipo_instrumento','marca','modelo','serie','capacidad_rango','codigo_int'] as $c) { $t->text($c)->nullable(); }
        });
        DB::table('acta_conformidad')->insert(['id'=>4,'tarea_id'=>27,'idActa'=>'OC-800','razon_social_acta'=>'Cliente A','estado'=>'no_firmada','fecha_acta'=>'2026-11-01']);
        DB::table('tb_tareas_tecnicos')->insert(['id'=>27,'cliente_id'=>3,'lugar_ejecucion'=>'Taller']);
        DB::table('tb_instrumento_tarea')->insert(['id'=>12,'acta_id'=>4,'tarea_id'=>27,'tipo_instrumento'=>'Balanza']);
        require base_path('entregas/actas-conformidad-v1.19.0/sistema/routes/mcp-actas-web.php');
        Route::getRoutes()->refreshNameLookups();
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag);
        app('session')->driver()->start();
        app('view')->addLocation(base_path('entregas/actas-conformidad-v1.19.0/sistema/resources/views'));
    }

    private function req(array $data = [], string $role = 'Operaciones', string $url = '/', int $userId = 7): Request
    {
        $r = Request::create($url, 'GET', $data);
        $user = (object) ['id'=>$userId,'roles'=>collect([(object)['name'=>$role]])];
        $r->setUserResolver(fn () => $user);
        return $r;
    }

    private function denied(callable $action, int $status): void
    {
        try { $action(); $this->fail('Expected denial'); }
        catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }

    public function test_list_filters_pagination_and_correct_task_relation(): void
    {
        $c = new McpActasController;
        $result = $c->buscar($this->req(['tarea_id'=>27,'limite'=>1]))->getData(true);
        $this->assertSame(1, $result['total']);
        $this->assertFalse($result['hay_mas']);
        $this->assertSame(27, $result['data'][0]['tarea_id']);
        $this->assertSame(0, $c->buscar($this->req(['estado'=>'firmada']))->getData(true)['total']);
        $this->assertSame(0, $c->buscar($this->req(['desde'=>'2026-11-02']))->getData(true)['total']);
        $detail = $c->ver($this->req(), 4)->getData(true);
        $this->assertSame(27, $detail['hoja_salida']['id']);
        $this->assertSame(12, $detail['instrumentos'][0]['id']);
        $this->assertArrayNotHasKey('foto_firmada', $detail);
        $this->assertTrue($detail['puede_subir_firmada']);
    }

    public function test_roles_missing_records_and_missing_attachment(): void
    {
        $c = new McpActasController;
        $this->denied(fn () => $c->buscar($this->req([], 'Asesor de Ventas')), 403);
        $this->denied(fn () => $c->ver($this->req(), 999), 404);
        $this->denied(fn () => $c->enlaceFirmada($this->req(), 4), 404);
        $this->assertSame(4, $c->ver($this->req([], 'Gerencia'), 4)->getData(true)['id']);
    }

    public function test_links_use_authenticated_user_and_validate_tracking_tampering_expiration(): void
    {
        $c = new McpActasController;
        $link = $c->enlaceCarga($this->req(['u'=>999]), 4)->getData(true)['url'];
        $this->assertStringContainsString('u=7', $link);
        $this->assertStringStartsWith('https://sistema.test/', $link);
        $this->assertSame(200, $c->abrirCarga($this->req([], 'Operaciones', $link.'&utm_source=chatgpt.com'), 4)->getStatusCode());
        $this->denied(fn () => $c->abrirCarga($this->req([], 'Operaciones', $link, 8), 4), 403);
        $this->denied(fn () => $c->abrirCarga($this->req([], 'Operaciones', str_replace('d=0','d=1',$link)), 4), 403);
        $this->denied(fn () => $c->abrirCarga($this->req([], 'Operaciones', $link.'&evil=1'), 4), 403);
        $this->travel(11)->minutes();
        $this->denied(fn () => $c->abrirCarga($this->req([], 'Operaciones', $link), 4), 403);
        $this->travelBack();
    }

    public function test_closed_report_blocks_issue_open_and_save_but_allows_pdf(): void
    {
        $c = new McpActasController;
        $link = $c->enlaceCarga($this->req(), 4)->getData(true)['url'];
        DB::table('reportes')->insert(['tarea_id'=>27,'estado_global'=>'cerrado']);
        $this->denied(fn () => $c->enlaceCarga($this->req(), 4), 403);
        $this->denied(fn () => $c->abrirCarga($this->req([], 'Operaciones', $link), 4), 403);
        $this->denied(fn () => $c->guardar($this->req([], 'Operaciones', $link), 4), 403);
        $this->assertFalse($c->ver($this->req(),4)->getData(true)['puede_subir_firmada']);
        $this->assertStringContainsString('/pdf?', $c->enlacePdf($this->req(['modo'=>'descargar']),4)->getData(true)['url']);
    }

    public function test_permissions_are_rechecked_on_open(): void
    {
        $c = new McpActasController;
        $link = $c->enlaceCarga($this->req(), 4)->getData(true)['url'];
        $this->denied(fn () => $c->abrirCarga($this->req([], 'Ventas', $link), 4), 403);
    }

    public function test_attachment_traversal_is_rejected(): void
    {
        DB::table('acta_conformidad')->where('id',4)->update(['foto_firmada'=>'actas_firmadas/../secret.pdf']);
        $this->denied(fn () => (new McpActasController)->enlaceFirmada($this->req(),4),404);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new McpActasController)->buscar($this->req(['desde'=>'2026-11-10','hasta'=>'2026-11-01','limite'=>999]));
    }

    public function test_save_requires_explicit_confirmation_before_delegating(): void
    {
        $c = new McpActasController;
        $link = $c->enlaceCarga($this->req(),4)->getData(true)['url'];
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $c->guardar($this->req([], 'Operaciones', $link),4);
    }
    public function test_signed_image_is_served_privately_and_download_mode_is_honored(): void
    {
        $dir = public_path('actas_firmadas');
        $created = !is_dir($dir);
        if ($created) { mkdir($dir); }
        $file = $dir.'/test-'.bin2hex(random_bytes(8)).'.png';
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5WQAAAAASUVORK5CYII='));
        try {
            DB::table('acta_conformidad')->where('id',4)->update(['foto_firmada'=>'actas_firmadas/'.basename($file)]);
            $c = new McpActasController;
            $url = $c->enlaceFirmada($this->req(['modo'=>'descargar']),4)->getData(true)['url'];
            $response = $c->abrirFirmada($this->req([], 'Operaciones', $url),4);
            $this->assertSame(realpath($file), $response->getFile()->getRealPath());
            $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        } finally {
            unlink($file);
            if ($created) { rmdir($dir); }
        }
    }

    public function test_pdf_preserves_blank_characteristics_and_escapes_customer_text(): void
    {
        require_once base_path('entregas/actas-conformidad-v1.19.0/sistema/app/Services/ActaConformidadPdf.php');
        $name='test-logo-'.bin2hex(random_bytes(8)).'.png';
        file_put_contents(public_path($name),'test');
        config(['acta_pdf.logo'=>$name,'acta_pdf.proveedor'=>'INDUSTRIAS BALINSA E.I.R.L']);
        try {
            foreach (['Pesa'=>'Clase de exactitud','Termometro'=>'Rango','Balanza'=>'División'] as $tipo=>$label) {
                $view=(new \App\Services\ActaConformidadPdf)->preparar(['id'=>4,'razon_social_acta'=>'<script>cliente</script>', 'fecha_acta'=>'2026-11-01 00:00:00','fecha_final_acta'=>'2026-11-10','instrumento'=>['tipo_instrumento'=>$tipo,'serie'=>'NO_IMPRIMIR']]);
                $this->assertStringContainsString($label.': ___________', $view['texto']);
                $this->assertStringNotContainsString('NO_IMPRIMIR',$view['texto']);
                $this->assertSame('01/11/2026',$view['inicio']);
                $html=view('mcp.acta-pdf',$view)->render();
                $this->assertStringNotContainsString('<script>cliente</script>',$html);
                $this->assertStringContainsString('&lt;script&gt;cliente&lt;/script&gt;',$html);
            }
        } finally { unlink(public_path($name)); }
    }

}
