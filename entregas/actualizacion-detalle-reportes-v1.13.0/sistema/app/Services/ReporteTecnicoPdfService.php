<?php
namespace App\Services;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReporteTecnicoPdfService
{
    private function verdadero($v): bool { return $v!==null && $v!==false && $v!=='' && $v!==0 && $v!==0.0; }
    private function texto($v): string { return is_array($v)||is_object($v)||$v===null?'':trim(is_bool($v)?($v?'true':'false'):(string)$v); }
    private function respaldo($v,string $otro='N/A'): string { return $this->verdadero($v)?(string)$v:$otro; }
    private function fecha($v): string
    {
        if(!$this->verdadero($v)) { return '-'; }
        if(!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/',(string)$v,$m)) { return 'Invalid Date'; }
        $a=(int)$m[1]; if($a>=0 && $a<=99) { $a+=1900; }
        $date=(new \DateTimeImmutable(sprintf('%04d-01-01',$a)))->modify(((int)$m[2]-1).' months')->modify(((int)$m[3]-1).' days');
        $dias=['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
        $meses=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return $dias[(int)$date->format('w')].', '.$date->format('j').' de '.$meses[(int)$date->format('n')-1].' de '.$date->format('Y');
    }
    public function calcular(array $datos): array
    {
        $r=$datos['reporte']; $i=$datos['instrumento'];
        $fecha=$this->verdadero($r['created_at']??null)?$r['created_at']:($r['fecha']??'');
        $year=substr((string)$fecha,0,4);
        if(!preg_match('/^\d{4}$/',$year)||!$this->verdadero($r['id']??null)||!$this->verdadero($i['orden_pdf']??null)) {
            throw new InvalidArgumentException('El instrumento no tiene numeración registrada válida. Regularízala en el sistema; pedir el PDF no la crea.');
        }
        $numero=$year.'-'.str_pad((string)$r['id'],6,'0',STR_PAD_LEFT).'-'.str_pad((string)$i['orden_pdf'],3,'0',STR_PAD_LEFT);
        $tecnicos=array_values(array_unique(array_filter([$i['d_tecnico']??null,$i['inf_tecnico']??null],fn($v)=>$this->verdadero($v)),SORT_REGULAR));
        $fechas=array_values(array_unique(array_filter([$i['d_fecha']??null,$i['inf_fecha']??null],fn($v)=>$this->verdadero($v)),SORT_REGULAR));
        $tipo=$i['tipo_instrumento']??''; $titulo=null; $filas=[]; $ultima=[];
        if($tipo==='Balanza'||$tipo==='Termómetro') {
            $titulo=$tipo==='Balanza'?'DATOS DEL EQUIPO : BALANZA':'DATOS DEL EQUIPO : TERMOMETRO';
            $filas=[['MARCA',$this->respaldo($i['marca']??null),'MODELO',$this->respaldo($i['modelo']??null)],
                ['SERIE',$i['serie']??'','DIVISION',$i['resolucion_division']??'']];
            $ultima=[$tipo==='Balanza'?'CAPACIDAD':'RANGO',$i['capacidad_rango']??''];
        } elseif(str_contains(mb_strtolower($tipo),'pesa')) {
            $titulo='DATOS DEL EQUIPO : PESA PATRON';
            $filas=[['MATERIAL',$i['material']??'','MODELO',$i['modelo']??''],['CAPACIDAD',$i['capacidad_rango']??'','CODIGO',$i['codigo_int']??'']];
            $ultima=['C. EXACTITUD',$i['clase_tipo_metrologico']??''];
        }
        $fotos=[];
        foreach([['d','fallas_detectadas','INFORME FOTOGRÁFIAS'],['inf','trabajos_realizados','RESULTADOS FOTOGRÁFIAS']] as [$pref,$campo,$label]) {
            $paths=[$i[$pref.'_foto1']??null,$i[$pref.'_foto2']??null,$i[$pref.'_foto3']??null];
            if(($i[$campo]??null)!==null || count(array_filter($paths,fn($v)=>$this->verdadero($v)))) {
                $fotos[]=['titulo'=>$label,'rutas'=>$paths];
            }
        }
        return ['numero'=>$numero,'archivo'=>'Reporte_'.$numero.'.pdf','cliente'=>$r['cliente_nombre']??'',
            'tecnicos'=>$tecnicos?implode(', ',$tecnicos):'-','fechas'=>$fechas?implode(' - ',array_map(fn($v)=>$this->fecha($v),$fechas)):'-',
            'titulo_equipo'=>$titulo,'filas_equipo'=>$filas,'ultima_fila'=>$ultima,
            'informe'=>$this->texto($i['fallas_detectadas']??null),'resultado'=>$this->texto($i['trabajos_realizados']??null),
            'fotos'=>$fotos];
    }
    public function resumen(array $datos): array
    {
        $c=$this->calcular($datos);
        return ['reporte_id'=>(int)$datos['reporte']['id'],'instrumento_id'=>(int)$datos['instrumento']['id'],
            'numero'=>$c['numero'],'archivo'=>$c['archivo'],'cliente'=>Str::limit($c['cliente'],120)];
    }
    public function imagenLocal(?string $valor,bool $logo=false): ?string
    {
        if(!$valor) { return null; }
        $base=realpath(public_path($logo?'img':config('reporte_tecnico_pdf.carpeta_fotos','imagenes_reportes')));
        if(!$base) { return null; }
        $relative=ltrim(str_replace('\\','/',$valor),'/');
        $prefix=$logo?'img/':trim(config('reporte_tecnico_pdf.carpeta_fotos','imagenes_reportes'),'/').'/';
        // Misma resolución de fotoReporteUrl: nombres sueltos bajo imagenes_reportes.
        if(str_starts_with($relative,$prefix)) { $relative=substr($relative,strlen($prefix)); }
        $path=realpath($base.DIRECTORY_SEPARATOR.$relative);
        if(!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)||!is_file($path)||filesize($path)>10*1024*1024) { return null; }
        $size=@getimagesize($path);
        if(!$size||!in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_GIF],true)||$size[0]*$size[1]>20000000) { return null; }
        if(!function_exists('imagecreatefromstring')) { throw new InvalidArgumentException('Activa la extensión PHP gd para generar el PDF con imágenes.'); }
        $src=@imagecreatefromstring(file_get_contents($path)); if(!$src) { return null; }
        $ratio=min(1,1000/max($size[0],$size[1]));$w=max(1,(int)round($size[0]*$ratio));$h=max(1,(int)round($size[1]*$ratio));
        $dst=imagecreatetruecolor($w,$h);imagefill($dst,0,0,imagecolorallocate($dst,255,255,255));
        imagecopyresampled($dst,$src,0,0,0,0,$w,$h,$size[0],$size[1]);ob_start();imagejpeg($dst,null,90);$bytes=ob_get_clean();
        imagedestroy($src);imagedestroy($dst);return 'data:image/jpeg;base64,'.base64_encode($bytes);
    }
    private function bloques(string $texto): array
    {
        $out=[];
        while(mb_strlen($texto)>600) {
            $parte=mb_substr($texto,0,600);$corte=mb_strrpos($parte,' ');
            $n=$corte===false?600:$corte+1;$out[]=mb_substr($texto,0,$n);$texto=mb_substr($texto,$n);
        }
        $out[]=$texto;return $out;
    }
    public function generar(array $datos): array
    {
        @set_time_limit(60);
        $d=$this->calcular($datos);$d['informe_bloques']=$this->bloques($d['informe']);$d['resultado_bloques']=$this->bloques($d['resultado']);$d['empresa']=config('reporte_tecnico_pdf');
        $d['logo']=$this->imagenLocal(config('reporte_tecnico_pdf.logo'),true);
        foreach($d['fotos'] as &$grupo) { $grupo['imagenes']=array_map(fn($p)=>$this->imagenLocal($p),$grupo['rutas']); } unset($grupo);
        $pdf=app('dompdf.wrapper');
        $pdf->setOptions(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false,'defaultFont'=>'Helvetica']);
        $pdf->loadView('pdf.reporte_tecnico_mcp',$d)->setPaper('a4','portrait');
        return ['archivo'=>$d['archivo'],'contenido'=>$pdf->output()];
    }
}
