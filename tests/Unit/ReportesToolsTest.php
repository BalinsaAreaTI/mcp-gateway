<?php

namespace Tests\Unit;

use App\Mcp\Tools\ServicioTecnicoRegistrarInformeTool;
use App\Mcp\Tools\ServicioTecnicoRegistrarResultadoTool;
use App\Mcp\Tools\ServicioTecnicoEditarCaracteristicasReporteTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

class ReportesToolsTest extends TestCase
{
    public function test_schemas_no_admiten_identidad_ni_estados_y_limitan_listas(): void
    {
        $f = new JsonSchemaTypeFactory;
        foreach ([new ServicioTecnicoRegistrarInformeTool, new ServicioTecnicoRegistrarResultadoTool,
            new ServicioTecnicoEditarCaracteristicasReporteTool] as $tool) {
            $schema = $tool->schema($f);
            $this->assertArrayNotHasKey('tecnico_id', $schema);
            $this->assertArrayNotHasKey('fecha', $schema);
            $this->assertArrayHasKey('confirmado', $schema);
            $this->assertArrayHasKey('version_actual', $schema);
            foreach (['trabajos_sugeridos', 'observaciones_finales', 'n_cotizacion'] as $removed) {
                $this->assertArrayNotHasKey($removed, $schema);
            }
        }
        $s = (new \App\Mcp\Tools\ServicioTecnicoVerReporteTool)->schema($f);
        $this->assertSame(20, $s['limite']->toArray()['maximum']);
        $s = (new \App\Mcp\Tools\ServicioTecnicoBuscarReportesTool)->schema($f);
        $this->assertSame(['informe','resultado'], $s['fase']->toArray()['enum']);
    }

    public function test_registro_envia_ruta_y_campos_permitidos(): void
    {
        $tool = new class extends ServicioTecnicoRegistrarResultadoTool {
            public array $sent = [];
            protected function enviar(Request $r, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            { $this->sent = [$ruta,$cuerpo]; return Response::text('{}'); }
        };
        $extra=['tipo_instrumento'=>'Balanza','tipo_servicio'=>'Reparación','material'=>'Acero','clase_tipo_metrologico'=>'II'];
        $tool->handle(new Request(['tarea_id'=>7,'instrumento_extra'=>$extra,'trabajos_realizados'=>'Ajustado',
            'confirmado'=>true,'version_actual'=>'version','tecnico_id'=>999,'estado_informe'=>'aprobado']));
        $this->assertSame('servicio-tecnico/reportes/7/resultados',$tool->sent[0]);
        $this->assertSame($extra,$tool->sent[1]['instrumento_extra']);
        $this->assertArrayNotHasKey('tecnico_id',$tool->sent[1]);
        $this->assertArrayNotHasKey('estado_informe',$tool->sent[1]);
        $this->assertArrayNotHasKey('observaciones_finales',$tool->sent[1]);
        $this->assertArrayNotHasKey('n_cotizacion',$tool->sent[1]);
    }

    public function test_extra_rechaza_claves_no_permitidas_antes_del_http(): void
    {
        $tool = new class extends ServicioTecnicoRegistrarInformeTool {
            public bool $called = false;
            protected function enviar(Request $r, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            { $this->called = true; return Response::text('{}'); }
        };
        $tool->handle(new Request(['instrumento_extra'=>['tipo_instrumento'=>'Balanza','reporte_id'=>99]]));
        $this->assertFalse($tool->called);
    }

    public function test_edicion_conserva_campo_vacio_y_omite_otros(): void
    {
        $tool = new class extends ServicioTecnicoEditarCaracteristicasReporteTool {
            public array $sent = [];
            protected function enviar(Request $r, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            { $this->sent = [$ruta,$cuerpo]; return Response::text('{}'); }
        };
        $tool->handle(new Request(['tarea_id'=>5,'reporte_instrumento_id'=>8,'confirmado'=>true,'version_actual'=>'v','cambios'=>['serie'=>'']]));
        $this->assertSame('servicio-tecnico/reportes/5/caracteristicas',$tool->sent[0]);
        $this->assertSame(['serie'=>''],$tool->sent[1]['cambios']);
    }

    public function test_informe_conserva_endpoint_y_fase_del_sistema(): void
    {
        $tool = new class extends ServicioTecnicoRegistrarInformeTool {
            public array $sent = [];
            protected function enviar(Request $r, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            { $this->sent = [$ruta, $cuerpo]; return Response::text('{}'); }
        };
        $tool->handle(new Request(['tarea_id'=>7, 'instrumento_tarea_id'=>3,
            'fallas_detectadas'=>'No enciende', 'trabajos_sugeridos'=>'Revisar fuente',
            'confirmado'=>true, 'version_actual'=>'v']));
        $this->assertSame('servicio_tecnico_registrar_informe', $tool->name());
        $this->assertSame('servicio-tecnico/reportes/7/diagnosticos', $tool->sent[0]);
        $this->assertSame('No enciende', $tool->sent[1]['fallas_detectadas']);
        $this->assertArrayNotHasKey('trabajos_sugeridos', $tool->sent[1]);
        $buscar = new class extends \App\Mcp\Tools\ServicioTecnicoBuscarReportesTool {
            public array $sent = [];
            protected function consultar(Request $r, string $ruta, array $query = []): Response
            { $this->sent = [$ruta, $query]; return Response::text('{}'); }
        };
        $buscar->handle(new Request(['fase'=>'informe']));
        $this->assertSame('diagnostico', $buscar->sent[1]['fase']);
        $buscar->handle(new Request(['fase'=>'resultado']));
        $this->assertSame('resultado', $buscar->sent[1]['fase']);
    }
}
