<?php
declare(strict_types=1);

final class ReporteAlumnosModulo
{
    public function __construct(private PDO $pdo)
    {
    }

    public function obtenerParaProfesor(int $moduloId, int $profesorId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.id, m.nombre, m.horario_inicio, m.horario_cierre,
                   a.nombre AS actividad_nombre, e.nombre AS entidad_nombre
            FROM modulos m
            JOIN actividades a ON a.id = m.fk_actividad
            JOIN entidades e ON e.id = m.fk_entidad
            WHERE m.id = ?
              AND m.fk_usuario_profesor = ?
              AND multipro_modulo_disponible(m.id)
        ");
        $stmt->execute([$moduloId, $profesorId]);
        $modulo = $stmt->fetch();
        if (!$modulo) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT i.id AS inscripcion_id, i.fecha AS fecha_inscripcion,
                   (menor.id IS NOT NULL) AS es_menor,
                   COALESCE(menor.apellido, alumno.apellido) AS alumno_apellido,
                   COALESCE(menor.nombre, alumno.nombre) AS alumno_nombre,
                   COALESCE(menor.dni, alumno.dni) AS alumno_dni,
                   COALESCE(menor.fecha_nacimiento, alumno.fecha_nacimiento) AS alumno_fecha_nacimiento,
                   COALESCE(menor.direccion, alumno.direccion) AS alumno_direccion,
                   alumno.email AS alumno_email,
                   alumno.telefono AS alumno_telefono,
                   menor.relacion AS responsable_relacion,
                   responsable.apellido AS responsable_apellido,
                   responsable.nombre AS responsable_nombre,
                   responsable.dni AS responsable_dni,
                   responsable.telefono AS responsable_telefono
            FROM inscripcion i
            LEFT JOIN usuarios alumno ON alumno.id = i.fk_usuario
            LEFT JOIN menores menor ON menor.id = i.fk_menor
            LEFT JOIN usuarios responsable ON responsable.id = menor.fk_usuario
            WHERE i.fk_modulo = ?
              AND i.estado = 'activo'
              AND i.lista_espera = FALSE
            ORDER BY COALESCE(menor.apellido, alumno.apellido),
                     COALESCE(menor.nombre, alumno.nombre), i.id
        ");
        $stmt->execute([$moduloId]);

        $alumnos = $stmt->fetchAll();
        $tieneMenores = false;
        foreach ($alumnos as $alumno) {
            if (filter_var($alumno['es_menor'], FILTER_VALIDATE_BOOL)) {
                $tieneMenores = true;
                break;
            }
        }

        return ['modulo' => $modulo, 'alumnos' => $alumnos, 'tiene_menores' => $tieneMenores];
    }

    public function darBaja(int $inscripcionId, int $moduloId, int $profesorId): bool
    {
        return $this->darBajaVarias([$inscripcionId], $moduloId, $profesorId) === 1;
    }

    public function darBajaVarias(array $inscripcionIds, int $moduloId, int $profesorId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inscripcionIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE inscripcion i
                   SET estado = 'baja'
                  FROM modulos m
                 WHERE i.id IN ($marcadores)
                   AND i.fk_modulo = ?
                   AND m.id = i.fk_modulo
                   AND m.fk_usuario_profesor = ?
                   AND i.estado = 'activo'
                   AND i.lista_espera = FALSE
                   AND multipro_modulo_disponible(m.id)
            ");
            $stmt->execute([...$ids, $moduloId, $profesorId]);
            $cantidad = $stmt->rowCount();
            if ($cantidad !== count($ids)) {
                $this->pdo->rollBack();
                return 0;
            }
            $this->pdo->commit();
            return $cantidad;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
