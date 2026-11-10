<?php
declare(strict_types=1);

final class VisibilidadEntidades
{
    private const RUTAS_ADMIN_PRIVADA = [
        'perfil.php', 'estadisticas.php', 'espacios.php', 'novedades.php', 'reservas.php',
    ];

    /** @return array{string,array<int,int>} */
    public static function condicion(?array $usuario, string $alias = 'e'): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) {
            throw new InvalidArgumentException('Alias de entidad no permitido.');
        }

        $rol = strtolower((string) ($usuario['rol_nombre'] ?? ''));
        if ($rol === 'gestor') {
            return ['TRUE', []];
        }

        $entidadId = filter_var($usuario['fk_entidad'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($entidadId !== false) {
            return ["({$alias}.tipo_entidad = 'publica' OR {$alias}.id = ?)", [(int) $entidadId]];
        }

        return ["{$alias}.tipo_entidad = 'publica'", []];
    }

    public static function puedeVer(?array $usuario, int $entidadId, string $tipoEntidad): bool
    {
        if ($tipoEntidad === 'publica') return true;
        if (strtolower((string) ($usuario['rol_nombre'] ?? '')) === 'gestor') return true;
        return $entidadId > 0 && (int) ($usuario['fk_entidad'] ?? 0) === $entidadId;
    }

    public static function administradorPuedeAcceder(string $tipoEntidad, string $ruta): bool
    {
        return $tipoEntidad !== 'privada' || in_array($ruta, self::RUTAS_ADMIN_PRIVADA, true);
    }
}
