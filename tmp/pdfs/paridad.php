<?php
require $argv[1].'/vendor/autoload.php';require $argv[2].'/app/Services/ReporteTecnicoPdfService.php';
$casos=json_decode(file_get_contents($argv[3].'/casos.json'),true);$expected=json_decode(file_get_contents($argv[3].'/esperado.json'),true);
$s=new App\Services\ReporteTecnicoPdfService;$diff=[];
foreach($casos as $n=>$c){$actual=$s->calcular($c);unset($actual['fotos']);if($actual!==$expected[$n]){$diff[]=['n'=>$n,'actual'=>$actual,'expected'=>$expected[$n]];}}
echo count($casos).' casos, '.count($diff).' diferencias'.PHP_EOL;if($diff){echo json_encode(array_slice($diff,0,2),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit(1);}
