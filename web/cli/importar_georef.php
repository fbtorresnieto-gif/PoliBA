<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/CatalogoGeoref.php';
if(getenv('PGHOST')!=='127.0.0.1' || getenv('PGPORT')!=='5433' || !preg_match('/^multipro_(staging_|test_)/D',(string)getenv('PGDATABASE')))throw new RuntimeException('Este importador está limitado al entorno local de pruebas.');
$resultado=CatalogoGeoref::importar($pdo,dirname(__DIR__,2).'/database/catalogos/georef');
echo json_encode($resultado,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
