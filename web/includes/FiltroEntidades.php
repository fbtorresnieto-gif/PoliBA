<?php
declare(strict_types=1);

/** Filtro del catálogo público. No modifica permisos ni afiliación del usuario. */
final class FiltroEntidades
{
    public array $opciones;
    public array $seleccionadas = [];
    public bool $invalido = false;
    public bool $todas;
    public array $provincias = [];
    public array $localidades = [];
    public array $provinciasSeleccionadas = [];
    public array $localidadesSeleccionadas = [];
    private array $consulta = [];
    private array $coincidentes = [];

    public function __construct(array $opciones, array $consulta)
    {
        foreach ($opciones as $e) {
            if (!empty($e['provincia_id'])) $this->provincias[(int)$e['provincia_id']] = ['id'=>(int)$e['provincia_id'],'nombre'=>$e['provincia']];
        }
        $this->provincias = array_values($this->provincias);
        usort($this->provincias,static fn($a,$b)=>strnatcasecmp($a['nombre'],$b['nombre']));
        $this->provinciasSeleccionadas = $this->seleccion('provincias',$consulta,$this->provincias);
        $porProvincia = array_values(array_filter($opciones,fn($e)=>!isset($this->consulta['provincias']) || in_array((int)($e['provincia_id']??0),$this->provinciasSeleccionadas,true)));
        foreach ($porProvincia as $e) {
            if (!empty($e['localidad_id'])) $this->localidades[(int)$e['localidad_id']] = ['id'=>(int)$e['localidad_id'],'nombre'=>$e['localidad'].(!empty($e['departamento'])?' — '.$e['departamento']:'').(!empty($e['categoria'])?' · '.$e['categoria']:'').' · '.$e['provincia']];
        }
        $this->localidades = array_values($this->localidades);
        usort($this->localidades,static fn($a,$b)=>strnatcasecmp($a['nombre'],$b['nombre']));
        $this->localidadesSeleccionadas = $this->seleccion('localidades',$consulta,$this->localidades);
        $this->opciones = array_values(array_filter($porProvincia,fn($e)=>!isset($this->consulta['localidades']) || in_array((int)($e['localidad_id']??0),$this->localidadesSeleccionadas,true)));
        $this->seleccionadas = $this->seleccion('entidades',$consulta,$this->opciones);
        $this->todas = !$this->consulta;
        $this->coincidentes = array_values(array_map(static fn($e)=>(int)$e['id'],array_filter($this->opciones,fn($e)=>!isset($this->consulta['entidades']) || in_array((int)$e['id'],$this->seleccionadas,true))));
    }

    private function seleccion(string $clave,array $consulta,array $opciones): array
    {
        if (!array_key_exists($clave,$consulta) || $consulta[$clave]===[]) return [];
        $this->consulta[$clave]=['0'];
        $raw=$consulta[$clave];
        if (!is_array($raw) || count($raw) > 100) {
            $this->invalido = true;
            return [];
        }
        $ids=[];
        $disponibles = array_fill_keys(array_map(static fn(array $e): int => (int)$e['id'], $opciones), true);
        foreach ($raw as $valor) {
            if ((!is_string($valor) && !is_int($valor))
                || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$valor)
                || (int)$valor > 2147483647 || !isset($disponibles[(int)$valor])) {
                $this->invalido = true;
                continue;
            }
            $ids[(int)$valor] = (int)$valor;
        }
        $ids=array_values($ids);sort($ids,SORT_NUMERIC);
        $this->consulta[$clave]=$ids ?: ['0'];
        return $ids;
    }

    public static function cargar(PDO $pdo, array $consulta): self
    {
        $metadatos='';
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql')$metadatos=",to_jsonb(l)->>'departamento' departamento,to_jsonb(l)->>'categoria' categoria";
        $opciones = $pdo->query('SELECT e.id,e.nombre,l.id localidad_id,l.nombre localidad,p.id provincia_id,p.nombre provincia'.$metadatos.'
            FROM entidades e LEFT JOIN localidades l ON l.id=e.fk_localidad
            LEFT JOIN provincias p ON p.id=l.fk_provincia WHERE e.estado=TRUE ORDER BY e.nombre,e.id')->fetchAll(PDO::FETCH_ASSOC);
        return new self($opciones, $consulta);
    }

    /** @return array{string,array} */
    public function condicion(string $columna): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D', $columna)) {
            throw new InvalidArgumentException('Columna de filtro no permitida.');
        }
        if ($this->todas) return ['', []];
        if (!$this->coincidentes) return [' AND 1=0', []];
        return [' AND '.$columna.' IN ('.implode(',', array_fill(0, count($this->coincidentes), '?')).')', $this->coincidentes];
    }

    public function url(string $ruta): string
    {
        if ($this->todas) return $ruta;
        // Conservar una selección explícita no disponible sin ampliarla a todas.
        $consulta = http_build_query($this->consulta, '', '&', PHP_QUERY_RFC3986);
        return $ruta.(str_contains($ruta, '?') ? '&' : '?').$consulta;
    }
}
