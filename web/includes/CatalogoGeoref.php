<?php
declare(strict_types=1);

/** Importación explícita, sin red ni borrados. La aplicación pública utiliza solo la copia local. */
final class CatalogoGeoref
{
    private static function q(PDO $db,string $sql,array $v=[]): PDOStatement {$s=$db->prepare($sql);$s->execute($v);return $s;}
    public static function leer(string $directorio): array {
        $manifest=json_decode(file_get_contents($directorio.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        $datos=[];
        foreach(['provincias','localidades'] as $tipo) {
            $contenido=file_get_contents($directorio.'/'.$tipo.'.json');
            if(!hash_equals($manifest['recursos'][$tipo]['sha256_archivo']??'',hash('sha256',$contenido)))throw new RuntimeException('El archivo del catálogo no coincide con su manifiesto.');
            $datos[$tipo]=json_decode($contenido,true,512,JSON_THROW_ON_ERROR);
            if(count($datos[$tipo])!==$manifest['recursos'][$tipo]['cantidad'])throw new RuntimeException('El catálogo está incompleto.');
        }
        if(count($datos['provincias'])!==24 || !$datos['localidades'])throw new RuntimeException('Cantidad de jurisdicciones no esperada.');
        $provincias=[];$localidades=[];
        foreach($datos['provincias'] as $p) {
            if(!is_string($p['id']) || !preg_match('/^[0-9]{2}$/D',$p['id']) || isset($provincias[$p['id']]) || !is_string($p['nombre']) || mb_strlen($p['nombre'])>100)throw new RuntimeException('Provincia inválida o duplicada.');
            $provincias[$p['id']]=true;
        }
        foreach($datos['localidades'] as $l) {
            if(!is_string($l['id']) || !preg_match('/^[0-9]{8,20}$/D',$l['id']) || isset($localidades[$l['id']]) || !isset($provincias[$l['provincia_id']]) || !is_string($l['nombre']) || mb_strlen($l['nombre'])>100 || mb_strlen($l['departamento'])>100 || mb_strlen($l['categoria'])>100)throw new RuntimeException('Localidad inválida, duplicada o sin provincia.');
            $localidades[$l['id']]=true;
        }
        return $datos;
    }
    public static function importar(PDO $db,string $directorio): array {
        $datos=self::leer($directorio);
        if($db->inTransaction())throw new RuntimeException('La importación requiere una transacción independiente.');
        $db->beginTransaction();
        try {
            self::q($db,"SET LOCAL lock_timeout='5s'");self::q($db,'SELECT pg_advisory_xact_lock(76730009)');
            $provincias=json_encode($datos['provincias'],JSON_THROW_ON_ERROR);
            $localidades=json_encode($datos['localidades'],JSON_THROW_ON_ERROR);
            self::q($db,'UPDATE provincias p SET codigo_georef=d.id,nombre=d.nombre
                FROM jsonb_to_recordset(?::jsonb) AS d(id TEXT,nombre TEXT)
                WHERE p.codigo_georef IS NULL AND lower(p.nombre)=lower(d.nombre)
                AND NOT EXISTS(SELECT 1 FROM provincias oficial WHERE oficial.codigo_georef=d.id)',[$provincias]);
            self::q($db,'INSERT INTO provincias(codigo_georef,nombre)
                SELECT d.id,d.nombre FROM jsonb_to_recordset(?::jsonb) AS d(id TEXT,nombre TEXT)
                ON CONFLICT(codigo_georef) DO UPDATE SET nombre=EXCLUDED.nombre',[$provincias]);
            // Asociación exacta única: no fusionar localidades por nombre ni mover entidades.
            self::q($db,'WITH datos AS (
                SELECT d.*,p.id provincia_local,count(*) OVER(PARTITION BY d.provincia_id,lower(d.nombre)) frecuencia
                FROM jsonb_to_recordset(?::jsonb) AS d(id TEXT,nombre TEXT,provincia_id TEXT,departamento_id TEXT,departamento TEXT,categoria TEXT)
                JOIN provincias p ON p.codigo_georef=d.provincia_id
            ) UPDATE localidades l SET codigo_georef=d.id,nombre=d.nombre,departamento_id=d.departamento_id,departamento=d.departamento,categoria=d.categoria
                FROM datos d WHERE l.codigo_georef IS NULL AND l.fk_provincia=d.provincia_local
                AND lower(l.nombre)=lower(d.nombre) AND d.frecuencia=1
                AND NOT EXISTS(SELECT 1 FROM localidades oficial WHERE oficial.codigo_georef=d.id)',[$localidades]);
            self::q($db,'INSERT INTO localidades(codigo_georef,nombre,fk_provincia,departamento_id,departamento,categoria)
                SELECT d.id,d.nombre,p.id,d.departamento_id,d.departamento,d.categoria
                FROM jsonb_to_recordset(?::jsonb) AS d(id TEXT,nombre TEXT,provincia_id TEXT,departamento_id TEXT,departamento TEXT,categoria TEXT)
                JOIN provincias p ON p.codigo_georef=d.provincia_id
                ON CONFLICT(codigo_georef) DO UPDATE SET nombre=EXCLUDED.nombre,fk_provincia=EXCLUDED.fk_provincia,
                    departamento_id=EXCLUDED.departamento_id,departamento=EXCLUDED.departamento,categoria=EXCLUDED.categoria',[$localidades]);
            $db->commit();return ['provincias'=>count($datos['provincias']),'localidades'=>count($datos['localidades'])];
        } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
