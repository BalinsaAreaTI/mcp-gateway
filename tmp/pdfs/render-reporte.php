<?php
$central=$argv[1];$stage=$argv[2];$output=$argv[3];
require $central.'/vendor/autoload.php';$app=require $central.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once $stage.'/app/Services/ReporteTecnicoPdfService.php';
config(['view.paths'=>[$stage.'/resources/views'],'view.compiled'=>$output.'/compiled',
 'reporte_tecnico_pdf'=>require $stage.'/config/reporte_tecnico_pdf.php',
 'dompdf.options.temp_dir'=>$output,'dompdf.options.font_cache'=>$output]);
app('view.finder')->setPaths([$stage.'/resources/views']);
$datos=['reporte'=>['id'=>101,'created_at'=>'2026-10-29','cliente_nombre'=>'EMPRESA DE PRUEBA - VALIDACIÓN'],
 'instrumento'=>['id'=>21,'orden_pdf'=>1,'tipo_instrumento'=>'Balanza','marca'=>'Marca Prueba','modelo'=>'X-100',
 'serie'=>'ABC-001','resolucion_division'=>'0.01 kg','capacidad_rango'=>'150 kg','d_tecnico'=>'Técnico Uno','inf_tecnico'=>'Técnico Dos',
 'd_fecha'=>'2026-10-29','inf_fecha'=>'2026-10-30','fallas_detectadas'=>'El equipo presenta inestabilidad en la lectura. Se detectó desgaste en el cableado.',
 'trabajos_realizados'=>'Se corrigieron las conexiones y se realizaron pruebas de funcionamiento. El equipo quedó operativo.']];
$servicio=new App\Services\ReporteTecnicoPdfService;
foreach(['corto','largo','pesa'] as $caso){$d=$datos;if($caso==='largo'){$d['instrumento']['fallas_detectadas']=str_repeat('Observación técnica extensa para comprobar saltos de página y legibilidad del documento. ',95);$d['instrumento']['trabajos_realizados']=str_repeat('Trabajo realizado y verificado con pruebas funcionales. ',95);}
if($caso==='pesa'){$d['instrumento']['tipo_instrumento']='Pesa Patrón';$d['instrumento']['material']='Acero inoxidable';$d['instrumento']['codigo_int']='P-001';$d['instrumento']['clase_tipo_metrologico']='M1';}
$r=$servicio->generar($d);file_put_contents($output.'/'.$caso.'.pdf',$r['contenido']);echo $caso.': '.strlen($r['contenido']).' bytes'.PHP_EOL;}
