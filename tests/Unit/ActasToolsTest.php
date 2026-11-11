<?php
namespace Tests\Unit;
use App\Mcp\Tools\OperacionesPrepararCargaActaFirmadaTool;
use App\Mcp\Tools\OperacionesGenerarPdfActaConformidadTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;
class ActasToolsTest extends TestCase
{
    public function test_upload_link_does_not_forward_identity_file_or_arbitrary_path(): void
    {
        $tool = new class extends OperacionesPrepararCargaActaFirmadaTool {
            public array $payload;
            protected function consultar(Request $request, string $ruta, array $query = []): Response {
                $this->payload = [$ruta,$query]; return Response::text('ok');
            }
        };
        $tool->handle(new Request(['id'=>4,'u'=>999,'foto_firmada'=>'secret','ruta'=>'/etc/passwd']));
        $this->assertSame(['operaciones/actas/4/carga',[]],$tool->payload);
    }
    public function test_pdf_passes_download_mode_and_record_id(): void
    {
        $tool = new class extends OperacionesGenerarPdfActaConformidadTool {
            public array $payload;
            protected function consultar(Request $request, string $ruta, array $query = []): Response {
                $this->payload = [$ruta,$query]; return Response::text('ok');
            }
        };
        $tool->handle(new Request(['id'=>4,'modo'=>'descargar','u'=>999]));
        $this->assertSame(['operaciones/actas/4/pdf',['modo'=>'descargar']],$tool->payload);
    }
    public function test_all_five_tools_are_registered_with_valid_schemas(): void
    {
        $registered=(new \ReflectionClass(\App\Mcp\Servers\BalinsaServer::class))->getDefaultProperties()['tools'];
        foreach (['BuscarActasConformidad','VerActaConformidad','GenerarPdfActaConformidad','PrepararCargaActaFirmada','VerArchivoActaFirmada'] as $suffix) {
            $class='App\\Mcp\\Tools\\Operaciones'.$suffix.'Tool';
            $this->assertContains($class,$registered);
            $schema=(new $class)->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);
            $this->assertSame(1,$schema['id']->toArray()['minimum']);
            $this->assertArrayNotHasKey('u',$schema);
        }
    }

}
