<?php

namespace Tests\Unit;

use App\Mcp\Tools\ServicioTecnicoAgregarInstrumentoExtraTool;
use App\Mcp\Tools\ServicioTecnicoEditarCaracteristicasReporteTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PHPUnit\Framework\TestCase;

class InstrumentoExtraToolTest extends TestCase
{
    public function test_extra_exige_tipo_y_servicio_y_envia_solo_el_equipo(): void
    {
        $tool = new class extends ServicioTecnicoAgregarInstrumentoExtraTool {
            public array $sent = [];
            protected function enviar(Request $r, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            { $this->sent = [$ruta, $cuerpo]; return Response::text('{}'); }
        };
        $schema=$tool->schema(new JsonSchemaTypeFactory);
        $this->assertArrayNotHasKey('fallas_detectadas',$schema);
        $this->assertArrayNotHasKey('trabajos_realizados',$schema);
        foreach ([null, [], ['tipo_instrumento'=>'Balanza'], ['tipo_instrumento'=>'Balanza','tipo_servicio'=>' '],
            ['tipo_instrumento'=>'Balanza','tipo_servicio'=>'Mantenimiento','tarea_id'=>9]] as $extra) {
            $tool->handle(new Request(['instrumento_extra'=>$extra]));
            $this->assertSame([], $tool->sent);
        }
        $extra=['tipo_instrumento'=>'Balanza','tipo_servicio'=>'Mantenimiento','serie'=>'EX-1'];
        $tool->handle(new Request(['tarea_id'=>7,'confirmado'=>true,'version_actual'=>'v','instrumento_extra'=>$extra]));
        $this->assertSame(['servicio-tecnico/reportes/7/instrumentos-extra',[
            'version_actual'=>'v','confirmado'=>true,'instrumento_extra'=>$extra]],$tool->sent);
    }

    public function test_caracteristicas_envia_id_de_tarea_y_fase_sin_exigir_reporte(): void
    {
        $tool = new class extends ServicioTecnicoEditarCaracteristicasReporteTool {
            public array $sent = [];
            protected function enviar(Request $r, string $ruta, array $cuerpo, string $prefijo422 = 'No se pudo registrar: '): Response
            { $this->sent = [$ruta, $cuerpo]; return Response::text('{}'); }
        };
        $tool->handle(new Request(['cambios'=>[]]));$this->assertSame([],$tool->sent);
        $tool->handle(new Request(['tarea_id'=>7,'instrumento_tarea_id'=>3,'fase'=>'resultado',
            'confirmado'=>true,'version_actual'=>'v','cambios'=>['marca'=>'Nueva']]));
        $this->assertSame('servicio-tecnico/reportes/7/caracteristicas',$tool->sent[0]);
        $this->assertNull($tool->sent[1]['reporte_instrumento_id']);
        $this->assertSame(3,$tool->sent[1]['instrumento_tarea_id']);
        $this->assertSame('resultado',$tool->sent[1]['fase']);
    }
}
