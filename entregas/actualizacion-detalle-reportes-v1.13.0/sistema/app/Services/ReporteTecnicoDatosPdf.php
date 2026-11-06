<?php
namespace App\Services;
use App\Http\Controllers\ReportesController;
use InvalidArgumentException;

class ReporteTecnicoDatosPdf
{
    /** Reutiliza la API de detalle de la pantalla, sin ejecutar verReporte (que asigna numeración). */
    public function cargar(int $reporteId,int $instrumentoId): array
    {
        $respuesta=app(ReportesController::class)->apiDetalleReporte($reporteId);
        if($respuesta->getStatusCode()!==200) { abort(404,'Reporte no encontrado.'); }
        $datos=$respuesta->getData(true);
        $items=array_values(array_filter($datos['instrumentos']??[],fn($i)=>(int)$i['id']===$instrumentoId));
        if(count($items)===0) { abort(404,'Instrumento no encontrado en el reporte.'); }
        if(count($items)!==1) { throw new InvalidArgumentException('Hay registros duplicados; revisa el instrumento en el sistema.'); }
        return ['reporte'=>$datos['reporte'],'instrumento'=>$items[0]];
    }
}
