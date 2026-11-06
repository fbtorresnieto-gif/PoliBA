<?php
declare(strict_types=1);
final class UbicacionEntidades
{
    public static function seleccionar(PDO $db,mixed $provincia,mixed $localidad): ?int
    {
        if(($provincia==='' || $provincia===null) && ($localidad==='' || $localidad===null))return null;
        foreach([$provincia,$localidad] as $v)if((!is_string($v)&&!is_int($v)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$v) || (int)$v>2147483647)throw new InvalidArgumentException('Seleccioná una provincia y una localidad válidas.');
        $s=$db->prepare('SELECT id FROM localidades WHERE id=? AND fk_provincia=?');$s->execute([(int)$localidad,(int)$provincia]);
        if(!$s->fetchColumn())throw new InvalidArgumentException('La localidad no pertenece a la provincia seleccionada.');
        return (int)$localidad;
    }
    public static function etiqueta(array $fila): string
    {
        $nombre=(string)$fila['nombre'];
        if(!empty($fila['departamento']))$nombre.=' — '.$fila['departamento'];
        if(!empty($fila['categoria']))$nombre.=' · '.$fila['categoria'];
        return $nombre;
    }
    public static function normalizar(mixed $valor): string
    {
        if (!is_string($valor)) throw new InvalidArgumentException('La ubicación debe ser texto.');
        $valor = preg_replace('/\s+/u', ' ', trim($valor));
        if ($valor === null || mb_strlen($valor)>100) throw new InvalidArgumentException('La ubicación admite hasta 100 caracteres.');
        return $valor;
    }
    public static function guardar(PDO $db,string $provincia,string $localidad): ?int
    {
        if (!$db->inTransaction()) throw new LogicException('La ubicación requiere una transacción.');
        if ($provincia==='' && $localidad==='') return null;
        if ($provincia==='' || $localidad==='') throw new InvalidArgumentException('Completá provincia y localidad juntas.');
        $s=$db->prepare('INSERT INTO provincias(nombre) VALUES (?) ON CONFLICT (lower(nombre)) DO UPDATE SET nombre=provincias.nombre RETURNING id');
        $s->execute([$provincia]);$p=$s->fetchColumn();
        $s=$db->prepare('SELECT id FROM localidades WHERE fk_provincia=? AND lower(nombre)=lower(?)');$s->execute([$p,$localidad]);$coincidencias=$s->fetchAll(PDO::FETCH_COLUMN);
        if(count($coincidencias)===1)return (int)$coincidencias[0];
        if(count($coincidencias)>1)throw new InvalidArgumentException('Hay varias localidades con ese nombre. Elegí la correcta en el catálogo.');
        $oficial=$db->query("SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='localidades' AND column_name='codigo_georef')")->fetchColumn();
        $s=$db->prepare('INSERT INTO localidades(fk_provincia,nombre) VALUES (?,?) ON CONFLICT (fk_provincia,lower(nombre))'.($oficial?' WHERE codigo_georef IS NULL':'').' DO UPDATE SET nombre=localidades.nombre RETURNING id');
        $s->execute([$p,$localidad]);return (int)$s->fetchColumn();
    }
}
