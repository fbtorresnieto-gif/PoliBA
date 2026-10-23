<?php
// Helpers de vigencia de novedades — MultiPro

/**
 * Fecha de hoy (Y-m-d) en horario de Argentina.
 * Se calcula en PHP y no con CURRENT_DATE de la base, porque en Railway
 * el servidor de base de datos usa UTC y el día cambiaría a las 21 hs.
 */
function hoy_ar(): string {
    return (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
}

/**
 * Desactiva en la base las novedades cuya fecha límite ya pasó.
 * Se invoca antes de listar novedades (home, API y ABM) para que el estado
 * guardado en la base coincida con lo que se muestra.
 */
function expirar_novedades(PDO $pdo): void {
    try {
        $stmt = $pdo->prepare("UPDATE novedades SET estado = FALSE WHERE estado = TRUE AND fecha_fin < ?");
        $stmt->execute([hoy_ar()]);
    } catch (PDOException $e) {
        error_log('No se pudieron expirar las novedades: ' . $e->getMessage());
    }
}
