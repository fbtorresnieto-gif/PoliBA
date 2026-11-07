<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/UbicacionEntidades.php';
redirect_if_not_logged_in(['Gestor']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo json_encode(['error'=>'Método no permitido.']);exit;}
$provincia=filter_var($_GET['provincia_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
if(!$provincia){http_response_code(422);echo json_encode(['error'=>'Seleccioná una provincia válida.']);exit;}
try {
    $s=$pdo->prepare('SELECT * FROM localidades WHERE fk_provincia=? ORDER BY nombre,id');$s->execute([$provincia]);
    $datos=[];foreach($s->fetchAll(PDO::FETCH_ASSOC) as $fila)$datos[]=['id'=>(int)$fila['id'],'nombre'=>UbicacionEntidades::etiqueta($fila),'oficial'=>($fila['codigo_georef']??null)!==null];
    echo json_encode(['data'=>$datos],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e){error_log('Catálogo de ubicación: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'No se pudieron cargar las localidades.']);}
