<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/web/includes/FiltroEntidades.php';

function comprobar(bool $ok,string $caso): void {
    if (!$ok) throw new RuntimeException('FALLO: '.$caso);
}
comprobar(in_array('sqlite',PDO::getAvailableDrivers(),true),'requiere PDO SQLite para fixture aislado');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE entidades(id INTEGER PRIMARY KEY,nombre TEXT,estado INTEGER);
CREATE TABLE actividades(id INTEGER PRIMARY KEY,nombre TEXT,fk_entidad INTEGER,estado INTEGER);
CREATE TABLE espacios(id INTEGER PRIMARY KEY,nombre TEXT,fk_entidad INTEGER,estado INTEGER);
CREATE TABLE novedades(id INTEGER PRIMARY KEY,nombre TEXT,fk_entidad INTEGER,estado INTEGER,fecha_inicio TEXT,fecha_fin TEXT);
INSERT INTO entidades VALUES(1,'Entidad Norte',1),(2,'Entidad Sur',1),(3,'Entidad cerrada',0),(4,'Sin oferta',1),(5,'<script>alert(1)</script>',1);
INSERT INTO actividades VALUES(11,'A',1,1),(12,'B',2,1),(13,'C',3,1),(14,'D',1,0);
INSERT INTO espacios SELECT id,nombre,fk_entidad,estado FROM actividades;
INSERT INTO novedades VALUES(21,'Norte',1,1,'2099-01-01','2099-12-31'),(22,'Sur',2,1,'2099-01-01','2099-12-31'),
(23,'Cerrada',3,1,'2099-01-01','2099-12-31'),(24,'General',NULL,1,'2099-01-01','2099-12-31'),
(25,'Vencida',1,1,'1999-01-01','1999-12-31'),(26,'Inactiva',1,0,'2099-01-01','2099-12-31');");
$db->exec("CREATE TABLE provincias(id INTEGER PRIMARY KEY,nombre TEXT); CREATE TABLE localidades(id INTEGER PRIMARY KEY,nombre TEXT,fk_provincia INTEGER);
INSERT INTO provincias VALUES(1,'Buenos Aires'),(2,'Córdoba');
INSERT INTO localidades VALUES(1,'La Plata',1),(2,'Capital',2),(3,'Capital',1);
ALTER TABLE localidades ADD COLUMN departamento TEXT; ALTER TABLE localidades ADD COLUMN categoria TEXT;
ALTER TABLE entidades ADD COLUMN fk_localidad INTEGER;
UPDATE entidades SET fk_localidad=CASE id WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 3 THEN 1 WHEN 4 THEN 3 END;");
$db->exec('ALTER TABLE novedades ADD COLUMN descripcion TEXT; ALTER TABLE novedades ADD COLUMN imagenurl TEXT;');
$queries=[];
foreach(['index','actividades','espacios'] as $pagina){
    $source=file_get_contents(dirname(__DIR__,2).'/web/'.$pagina.'.php');
    comprobar((bool)preg_match('/\$stmt = \$pdo->prepare\("(.*?)"\);/s',$source,$m),'consulta real '.$pagina);
    $queries[$pagina]=$m[1];
}
$casos=[
    [[],[11,12],[21,22,24]],
    [['provincias'=>['1']],[11],[21]],
    [['provincias'=>['1','2']],[11,12],[21,22]],
    [['localidades'=>['1']],[11],[21]],
    [['localidades'=>['2','1']],[11,12],[21,22]],
    [['provincias'=>['1'],'localidades'=>['2']],[],[]],
    [['provincias'=>['1'],'entidades'=>['2']],[],[]],
    [['provincias'=>['1'],'localidades'=>['1'],'entidades'=>['1']],[11],[21]],
    [['provincias'=>['999']],[],[]],
    [['localidades'=>['999']],[],[]],
    [['provincias'=>'1'],[],[]],
    [['localidades'=>[['1']]],[],[]],
    [['provincias'=>['1) OR 1=1']],[],[]],
    [['provincias'=>[],'localidades'=>[]],[11,12],[21,22,24]],
    [['entidades'=>[]],[11,12],[21,22,24]],
    [['entidades'=>['1']],[11],[21]],
    [['entidades'=>['2','1','1']],[11,12],[21,22]],
    [['entidades'=>['4']],[],[]],
    [['entidades'=>['3']],[],[]],
    [['entidades'=>['999']],[],[]],
    [['entidades'=>['1','999']],[11],[21]],
    [['entidades'=>'1'],[],[]],
    [['entidades'=>null],[],[]],
    [['entidades'=>[['1']]],[],[]],
    [['entidades'=>['1) OR 1=1 --']],[],[]],
    [['entidades'=>['2147483648']],[],[]],
    [['entidades'=>array_fill(0,101,'1')],[],[]],
];
$ejecutadas=0;
foreach($casos as [$consulta,$esperadoCatalogo,$esperadoNovedades]){
    $filtro=FiltroEntidades::cargar($db,$consulta);
    foreach($queries as $pagina=>$original){
        $columna=match($pagina){'index'=>'n.fk_entidad','actividades'=>'d.fk_entidad','espacios'=>'esp.fk_entidad'};
        [$clausula,$valores]=$filtro->condicion($columna);
        $sql=str_replace('$filtroSql',$clausula,$original);
        $stmt=$db->prepare($sql);
        $stmt->execute($pagina==='index'?array_merge(['2099-06-01'],$valores):$valores);
        $ids=array_map('intval',array_column($stmt->fetchAll(PDO::FETCH_ASSOC),'id'));sort($ids);
        comprobar($ids===($pagina==='index'?$esperadoNovedades:$esperadoCatalogo),'aislamiento '.$pagina.' caso '.$ejecutadas);
        $ejecutadas++;
    }
    parse_str((string)parse_url($filtro->url('espacios.php'),PHP_URL_QUERY),$roundtrip);
    $vuelta=FiltroEntidades::cargar($db,$roundtrip);
    comprobar($filtro->todas===$vuelta->todas && $filtro->seleccionadas===$vuelta->seleccionadas && $filtro->provinciasSeleccionadas===$vuelta->provinciasSeleccionadas && $filtro->localidadesSeleccionadas===$vuelta->localidadesSeleccionadas,'persistencia URL');
}
$filtroEntidades=FiltroEntidades::cargar($db,['entidades'=>['1','2']]);$filtroCantidad=2;
$_SERVER['SCRIPT_NAME']='/actividades.php';
ob_start();require dirname(__DIR__,2).'/web/includes/filtro_entidades_formulario.php';$html=ob_get_clean();
$dom=new DOMDocument();@$dom->loadHTML('<html><body>'.$html.'</body></html>');$xp=new DOMXPath($dom);
comprobar($xp->query('//input[@type="checkbox" and @checked]')->length===2,'dos selecciones visibles');
comprobar($xp->query('//input[@name="entidades[]" and @value="3"]')->length===0,'inactiva no ofrecida');
comprobar($xp->query('//script')->length===0,'nombres escapados');
comprobar($xp->query('//label[@for]')->length===9,'controles etiquetados');
comprobar($xp->query('//form[@method="get"]')->length===1,'formulario de consulta');
comprobar($xp->query('//details[not(@open)]/summary')->length===3,'tres desplegables cerrados al entrar');
comprobar($xp->query('//summary/span/span[contains(@class,"catalogo-filtro-etiqueta")]')->length===3,'campos Provincia Localidad Entidad');
try{$filtroEntidades->condicion('id; DROP TABLE entidades');throw new RuntimeException('columna no rechazada');}
catch(InvalidArgumentException $e){}
$api=file_get_contents(dirname(__DIR__,2).'/web/api/v1/novedades.php');
preg_match('/\$stmt = \$pdo->prepare\("(.*?)"\);/s',$api,$apiQuery);
$stmt=$db->prepare($apiQuery[1]);$stmt->execute(['2099-06-01']);$apiIds=array_map('intval',array_column($stmt->fetchAll(PDO::FETCH_ASSOC),'id'));sort($apiIds);
comprobar($apiIds===[21,22,24],'API novedades excluye entidad inactiva y conserva generales');
echo 'FILTROS_OK consultas_aisladas='.$ejecutadas." API_y_formulario_OK\n";
