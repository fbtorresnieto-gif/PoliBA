<?php
declare(strict_types=1);
require_once __DIR__.'/NotificacionesEmail.php';

final class ServicioPromociones
{
    public function __construct(private PDO $pdo){}

    public function promocionar(int $inscripcionId,int $nuevoModuloId,int $actorId): array
    {
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("SELECT i.*,m.fk_usuario_profesor,m.fk_actividad,m.fk_entidad,m.nombre modulo_anterior,
                COALESCE(u.id,tutor.id) destinatario_id,COALESCE(u.nombre,men.nombre) alumno_nombre,
                COALESCE(u.fecha_nacimiento,men.fecha_nacimiento) fecha_nacimiento
                FROM inscripcion i JOIN modulos m ON m.id=i.fk_modulo
                LEFT JOIN usuarios u ON u.id=i.fk_usuario LEFT JOIN menores men ON men.id=i.fk_menor
                LEFT JOIN usuarios tutor ON tutor.id=men.fk_usuario WHERE i.id=? AND i.estado='activo' FOR UPDATE OF i");
            $stmt->execute([$inscripcionId]);$i=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$i || (int)$i['fk_usuario_profesor']!==$actorId)throw new RuntimeException('La inscripción no está disponible para este profesor.');
            $stmt=$this->pdo->prepare("SELECT m.id,m.nombre,COALESCE(s.edad_minima,c.edad_minima,0) edad_min,
                COALESCE(s.edad_maxima,c.edad_maxima,99) edad_max FROM modulos m
                LEFT JOIN categoria c ON c.id=m.fk_categoria LEFT JOIN subcategorias s ON s.id=m.fk_subcategoria
                WHERE m.id=? AND m.estado AND m.fk_actividad=? AND m.fk_entidad=? FOR SHARE OF m");
            $stmt->execute([$nuevoModuloId,$i['fk_actividad'],$i['fk_entidad']]);$nuevo=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$nuevo || $nuevoModuloId===(int)$i['fk_modulo'])throw new RuntimeException('El módulo de destino no está disponible.');
            $edad=(new DateTimeImmutable((string)$i['fecha_nacimiento']))->diff(new DateTimeImmutable('today'))->y;
            if($edad<(int)$nuevo['edad_min']||$edad>(int)$nuevo['edad_max'])throw new RuntimeException('La edad no cumple con el rango del módulo seleccionado.');
            $this->pdo->prepare('UPDATE inscripcion SET fk_modulo=?,lista_espera=FALSE WHERE id=?')->execute([$nuevoModuloId,$inscripcionId]);
            $audit=$this->pdo->prepare('INSERT INTO inscripcion_promociones(fk_inscripcion,fk_modulo_anterior,fk_modulo_nuevo,fk_actor) VALUES(?,?,?,?) RETURNING id');
            $audit->execute([$inscripcionId,$i['fk_modulo'],$nuevoModuloId,$actorId]);$auditId=(int)$audit->fetchColumn();
            NotificacionesEmail::encolar($this->pdo,'promocion:'.$auditId,'promocion_modulo',(int)$i['destinatario_id'],[
                'modulo_anterior'=>$i['modulo_anterior'],'modulo_nuevo'=>$nuevo['nombre']]);
            $this->pdo->commit();
            return ['alumno_nombre'=>$i['alumno_nombre'],'modulo_nuevo'=>$nuevo['nombre']];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
