<?php
namespace Tests\Unit;
use App\Mcp\Tools\ServicioTecnicoGenerarPdfReporteTool;
use App\Mcp\Tools\ServicioTecnicoEditarInformeReporteTool;
use App\Mcp\Tools\ServicioTecnicoFinalizarReporteTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;
class DetalleReportesToolsTest extends TestCase
{
    public function test_pdf_envia_ids_y_modo_sin_usuario_del_agente(): void
    {
        $tool=new class extends ServicioTecnicoGenerarPdfReporteTool {
            public array $sent=[];
            protected function consultar(Request $r,string $ruta,array $query=[]): Response{$this->sent=[$ruta,$query];return Response::text('{}');}
        };
        $s=$tool->schema(new JsonSchemaTypeFactory);$this->assertSame(['ver','descargar'],$s['modo']->toArray()['enum']);
        $tool->handle(new Request(['reporte_id'=>101,'instrumento_id'=>21,'modo'=>'descargar','u'=>999]));
        $this->assertSame(['servicio-tecnico/detalle-reportes/101/instrumentos/21/pdf',['modo'=>'descargar']],$tool->sent);
    }
    public function test_edicion_rechaza_claves_ajenas_y_texto_vacio(): void
    {
        $tool=new class extends ServicioTecnicoEditarInformeReporteTool {
            public array $sent=[];
            protected function enviar(Request $r,string $ruta,array $cuerpo,string $prefijo422='No se pudo registrar: '): Response{$this->sent=[$ruta,$cuerpo];return Response::text('{}');}
        };
        $tool->handle(new Request(['cambios'=>['tecnico_id'=>999]]));$this->assertSame([],$tool->sent);
        foreach ([['trabajos_sugeridos'=>'Anterior'], ['repuestos_sugeridos'=>'Anterior'], ['fallas_detectadas'=>''], ['fallas_detectadas'=>'   '], ['fallas_detectadas'=>null]] as $cambios) {
            $tool->handle(new Request(['cambios'=>$cambios]));
            $this->assertSame([], $tool->sent);
        }
        $tool->handle(new Request(['reporte_id'=>101,'instrumento_id'=>21,'confirmado'=>true,'version_actual'=>'v','cambios'=>['fallas_detectadas'=>'Informe actualizado']]));
        $this->assertSame('servicio-tecnico/detalle-reportes/101/instrumentos/21/informe',$tool->sent[0]);
        $this->assertSame(['fallas_detectadas'=>'Informe actualizado'],$tool->sent[1]['cambios']);
    }
    public function test_resultado_solo_admite_texto_completo_no_vacio(): void
    {
        $tool=new class extends \App\Mcp\Tools\ServicioTecnicoEditarResultadoReporteTool {
            public array $sent=[];
            protected function enviar(Request $r,string $ruta,array $cuerpo,string $prefijo422='No se pudo registrar: '): Response{$this->sent=[$ruta,$cuerpo];return Response::text('{}');}
        };
        foreach (['observaciones_finales','repuestos_instalados','n_cotizacion'] as $campo) {
            $tool->handle(new Request(['cambios'=>[$campo=>'Anterior']]));
            $this->assertSame([], $tool->sent);
        }
        foreach (['', '   ', null] as $value) {
            $tool->handle(new Request(['cambios'=>['trabajos_realizados'=>$value]]));
            $this->assertSame([], $tool->sent);
        }
        $tool->handle(new Request(['reporte_id'=>101,'instrumento_id'=>21,'confirmado'=>true,'version_actual'=>'v','cambios'=>['trabajos_realizados'=>'Resultado actualizado']]));
        $this->assertSame('servicio-tecnico/detalle-reportes/101/instrumentos/21/resultado', $tool->sent[0]);
        $this->assertSame(['trabajos_realizados'=>'Resultado actualizado'], $tool->sent[1]['cambios']);
    }
    public function test_cierre_usa_reporte_no_tarea_y_solo_campos_de_confirmacion(): void
    {
        $tool=new class extends ServicioTecnicoFinalizarReporteTool {
            public array $sent=[];
            protected function enviar(Request $r,string $ruta,array $cuerpo,string $prefijo422='No se pudo registrar: '): Response{$this->sent=[$ruta,$cuerpo];return Response::text('{}');}
        };
        $tool->handle(new Request(['reporte_id'=>101,'tarea_id'=>11,'confirmado'=>true,'version_actual'=>'v','estado'=>'Otro']));
        $this->assertSame(['servicio-tecnico/detalle-reportes/101/finalizar',['confirmado'=>true,'version_actual'=>'v']],$tool->sent);
    }
}
