<?php

namespace Tests\Unit;

use App\Mcp\Tools\ServicioTecnicoBuscarHistorialReportesTool;
use App\Mcp\Tools\ServicioTecnicoResumenHistorialReportesTool;
use App\Mcp\Tools\ServicioTecnicoVerInstrumentosReporteTool;
use App\Mcp\Tools\ServicioTecnicoFinalizarServicioTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

class HistorialReportesToolsTest extends TestCase
{
    public function test_busqueda_y_resumen_comparten_filtros_con_paginacion_limitada(): void
    {
        $f = new JsonSchemaTypeFactory;
        $buscar=(new ServicioTecnicoBuscarHistorialReportesTool)->schema($f);
        $resumen=(new ServicioTecnicoResumenHistorialReportesTool)->schema($f);
        $this->assertSame(20,$buscar['limite']->toArray()['maximum']);
        unset($buscar['pagina'],$buscar['limite']);
        $this->assertSame(array_keys($buscar),array_keys($resumen));
        $tool=new class extends ServicioTecnicoResumenHistorialReportesTool {
            public array $sent=[];
            protected function consultar(Request $r,string $ruta,array $query=[]): Response
            { $this->sent=[$ruta,$query]; return Response::text('{}'); }
        };
        $p=['desde'=>'2026-10-01','hasta'=>'2026-10-29','cliente'=>'Empresa','numero_reporte'=>'RPT-000101',
            'numero_pendiente'=>'00007','pendiente_id'=>7,'estado'=>'cerrado'];
        $tool->handle(new Request($p));
        $this->assertSame('servicio-tecnico/historial-reportes/resumen',$tool->sent[0]);
        $this->assertSame($p,$tool->sent[1]);
    }

    public function test_detalle_enruta_por_reporte_y_filtra_instrumento(): void
    {
        $tool=new class extends ServicioTecnicoVerInstrumentosReporteTool {
            public array $sent=[];
            protected function consultar(Request $r,string $ruta,array $query=[]): Response
            { $this->sent=[$ruta,$query]; return Response::text('{}'); }
        };
        $tool->handle(new Request(['reporte_id'=>101,'tarea_id'=>11,'pendiente_id'=>7,'instrumento_id'=>21,'pagina'=>2,'limite'=>5]));
        $this->assertSame('servicio-tecnico/historial-reportes/101/instrumentos',$tool->sent[0]);
        $this->assertSame(['pagina'=>2,'limite'=>5,'instrumento_id'=>21],$tool->sent[1]);
    }

    public function test_finalizacion_envia_solo_confirmacion_y_version_por_pendiente(): void
    {
        $tool=new class extends ServicioTecnicoFinalizarServicioTool {
            public array $sent=[];
            protected function enviar(Request $r,string $ruta,array $cuerpo,string $prefijo422='No se pudo registrar: '): Response
            { $this->sent=[$ruta,$cuerpo]; return Response::text('{}'); }
        };
        $tool->handle(new Request(['pendiente_id'=>7,'reporte_id'=>101,'confirmado'=>true,'version_actual'=>'abc',
            'estado'=>'Borrado','user_id'=>999]));
        $this->assertSame('servicio-tecnico/historial-reportes/servicios/7/finalizar',$tool->sent[0]);
        $this->assertSame(['confirmado'=>true,'version_actual'=>'abc'],$tool->sent[1]);
    }
}
