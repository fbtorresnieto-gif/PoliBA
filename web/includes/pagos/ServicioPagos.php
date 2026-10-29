<?php
declare(strict_types=1);

namespace MultiPro\Pagos;

use PDO;
use Throwable;
use RuntimeException;

require_once __DIR__ . '/ProveedorPagos.php';

/** Servicio interno. El llamador autentica al actor; no publicar metodos como endpoint generico. */
final class ServicioPagos
{
    public function __construct(private PDO $db, private ProveedorPagos $proveedor)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    private function q(string $sql, array $params = []): \PDOStatement
    {
        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s;
    }

    private function tx(callable $fn): mixed
    {
        if ($this->db->inTransaction()) throw new RuntimeException('TRANSACCION_EXTERNA');
        $this->db->beginTransaction();
        try {
            $this->q("SET LOCAL lock_timeout = '5s'");
            $r = $fn();
            $this->db->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e; // El transporte debe mapear a error controlado, sin registrar payloads.
        }
    }

    private function reserva(int $id): array
    {
        $r = $this->q('SELECT *, pago_vence_en > clock_timestamp() AS vigente FROM reservas WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$r) throw new RuntimeException('RESERVA_NO_ENCONTRADA');
        return $r;
    }

    /** Dinero decimal canonico, sin floats. */
    public static function importe(string $valor): string
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $valor, $m)) {
            throw new RuntimeException('IMPORTE_INVALIDO');
        }
        return $m[1] . '.' . str_pad($m[2] ?? '', 2, '0');
    }

    private static function uuid(string $valor): void
    {
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $valor)) {
            throw new RuntimeException('CLAVE_INVALIDA');
        }
    }

    /** Consume una reserva online creada por el futuro flujo de reservas, sin extender su retencion.
     * No llama al proveedor: entrega identidad estable al futuro creador del checkout.
     */
    public function iniciarIntento(int $reservaId, int $usuarioId, string $clave): array
    {
        self::uuid($clave);
        return $this->tx(function () use ($reservaId, $usuarioId, $clave) {
            $r = $this->reserva($reservaId);
            if ((int)$r['fk_usuario'] !== $usuarioId) throw new RuntimeException('RESERVA_NO_ENCONTRADA');
            $anterior = $this->q('SELECT * FROM pago_transacciones WHERE clave_idempotencia=?', [$clave])->fetch();
            if ($anterior) {
                if ((int)$anterior['fk_reserva'] !== $reservaId) throw new RuntimeException('CLAVE_REUTILIZADA');
                return $anterior;
            }
            if ($r['modalidad_pago'] !== 'online' || $r['estado'] !== 'pendiente_pago' || !$r['vigente']) {
                throw new RuntimeException('RESERVA_NO_PAGABLE');
            }
            $e = $this->q('SELECT * FROM espacios WHERE id=? FOR SHARE', [$r['fk_espacio']])->fetch();
            if (!$e['estado'] || !$e['cobro_online_habilitado']) throw new RuntimeException('COBRO_NO_HABILITADO');
            $activa = $this->q('SELECT estado FROM entidades WHERE id=? FOR SHARE', [$e['fk_entidad']])->fetchColumn();
            if (!$activa) throw new RuntimeException('ENTIDAD_INACTIVA');
            // Este servicio es exclusivamente de pruebas; produccion requiere adaptador y revision propios.
            $c = $this->q("SELECT * FROM pago_conexiones WHERE fk_entidad=? AND ambiente='prueba' AND estado='activa'", [$e['fk_entidad']])->fetch();
            if (!$c) throw new RuntimeException('CONEXION_NO_CONFIGURADA');
            // Una respuesta incierta no habilita otro intento con clave nueva.
            if ($this->q("SELECT 1 FROM pago_transacciones WHERE fk_reserva=? AND estado IN ('creado','pendiente','error','aprobado','reembolsado','contracargo')", [$reservaId])->fetchColumn()) {
                throw new RuntimeException('INTENTO_EXISTENTE');
            }
            return $this->q('INSERT INTO pago_transacciones(fk_reserva,fk_entidad,fk_conexion,clave_idempotencia,importe_ars)
                VALUES(?,?,?,?,?) RETURNING *', [$reservaId,$e['fk_entidad'],$c['id'],$clave,$r['importe_total_ars']])->fetch();
        });
    }

    private function conexion(int $id): array
    {
        $c = $this->q('SELECT * FROM pago_conexiones WHERE id=?', [$id])->fetch();
        if (!$c || $c['ambiente'] !== 'prueba') throw new RuntimeException('CONEXION_NO_CONFIGURADA');
        // Las conexiones inactivas/revocadas pueden recibir notificaciones de operaciones anteriores.
        // El adaptador debe resolver credenciales aptas o rechazar la consulta sin confirmar nada.
        return $c;
    }

    public function recibirWebhook(int $conexionId, array $cabeceras, string $cuerpo, array $consulta): int
    {
        $e = $this->proveedor->autenticarEvento($this->conexion($conexionId), $cabeceras, $cuerpo, $consulta);
        foreach (['evento_id','recurso_id','tipo'] as $campo) {
            if (!isset($e[$campo]) || !is_string($e[$campo]) || trim($e[$campo]) === '' || strlen($e[$campo]) > ($campo === 'tipo' ? 80 : 150)) {
                throw new RuntimeException('EVENTO_INVALIDO');
            }
        }
        return $this->tx(function () use ($conexionId,$e) {
            $id = $this->q('INSERT INTO pago_webhook_eventos(fk_conexion,evento_proveedor_id,recurso_proveedor_id,tipo,firma_validada_en)
                VALUES(?,?,?,?,clock_timestamp()) ON CONFLICT(fk_conexion,evento_proveedor_id) DO NOTHING RETURNING id',
                [$conexionId,$e['evento_id'],$e['recurso_id'],$e['tipo']])->fetchColumn();
            $previo = $this->q('SELECT * FROM pago_webhook_eventos WHERE fk_conexion=? AND evento_proveedor_id=?', [$conexionId,$e['evento_id']])->fetch();
            if ($previo['recurso_proveedor_id'] !== $e['recurso_id'] || $previo['tipo'] !== $e['tipo']) throw new RuntimeException('EVENTO_CONFLICTIVO');
            return (int)($id ?: $previo['id']);
        });
    }

    /** Adquiere lease corto; consulta al proveedor sin mantener transaccion abierta. */
    public function procesarEvento(int $eventoId): string
    {
        $e = $this->tx(fn() => $this->q("UPDATE pago_webhook_eventos SET estado='procesando', intentos=intentos+1,
                bloqueo_hasta=clock_timestamp()+interval '60 seconds',actualizado_en=clock_timestamp()
            WHERE id=? AND ((estado IN ('recibido','error') AND (proximo_intento_en IS NULL OR proximo_intento_en<=clock_timestamp()))
                OR (estado='procesando' AND bloqueo_hasta<=clock_timestamp())) RETURNING *", [$eventoId])->fetch());
        if (!$e) return 'sin_trabajo';
        try {
            if ($e['tipo'] !== 'payment') {
                return $this->tx(function () use ($e) {
                    $this->cerrarEvento($e, 'ignorado');
                    return 'ignorado';
                });
            }
            $c = $this->conexion((int)$e['fk_conexion']);
            $s = $this->proveedor->consultarPago($c, $e['recurso_proveedor_id']);
            if ($s['id'] !== $e['recurso_proveedor_id'] || $s['cuenta'] !== $c['cuenta_proveedor'] ||
                $s['ambiente'] !== $c['ambiente'] || $s['moneda'] !== 'ARS') throw new RuntimeException('CORRELACION_INVALIDA');
            if (!in_array($s['estado'], ['pendiente','aprobado','rechazado','cancelado','reembolsado','contracargo'], true)) throw new RuntimeException('ESTADO_INVALIDO');
            $importe = self::importe($s['importe']);
            return $this->tx(function () use ($e,$s,$importe) {
                $origen = $this->q('SELECT * FROM pago_transacciones WHERE referencia_externa=? AND fk_conexion=?', [$s['referencia'],$e['fk_conexion']])->fetch();
                if (!$origen) throw new RuntimeException('REFERENCIA_DESCONOCIDA');
                $r = $this->reserva((int)$origen['fk_reserva']);
                $actual = $this->q("SELECT 1 FROM pago_webhook_eventos WHERE id=? AND estado='procesando'
                    AND bloqueo_hasta=? AND bloqueo_hasta>clock_timestamp() FOR UPDATE", [$e['id'],$e['bloqueo_hasta']])->fetchColumn();
                if (!$actual) throw new RuntimeException('LEASE_PERDIDO');
                if ($importe !== self::importe($origen['importe_ars'])) throw new RuntimeException('IMPORTE_NO_COINCIDE');
                $p = $this->q('SELECT * FROM pago_transacciones WHERE fk_conexion=? AND pago_proveedor_id=? FOR UPDATE', [$e['fk_conexion'],$s['id']])->fetch();
                if ($p && (int)$p['fk_reserva'] !== (int)$r['id']) throw new RuntimeException('PAGO_OTRA_RESERVA');
                if (!$p) {
                    $p = $this->q('SELECT * FROM pago_transacciones WHERE id=? FOR UPDATE', [$origen['id']])->fetch();
                    if ($p['pago_proveedor_id'] !== null) {
                        $p = $this->q('INSERT INTO pago_transacciones(fk_reserva,fk_entidad,fk_conexion,importe_ars)
                            VALUES(?,?,?,?) RETURNING *', [$r['id'],$origen['fk_entidad'],$origen['fk_conexion'],$importe])->fetch();
                    }
                }
                // Una consulta iniciada antes que otra no debe revertir resultados terminales.
                $prev = $p['estado'];
                $next = $s['estado'];
                $permitidos = match ($prev) {
                    'aprobado' => ['aprobado','reembolsado','contracargo'],
                    'reembolsado' => ['reembolsado'], 'contracargo' => ['contracargo'],
                    'rechazado' => ['rechazado'], 'cancelado' => ['cancelado'],
                    default => ['pendiente','aprobado','rechazado','cancelado','reembolsado','contracargo'],
                };
                if (!in_array($next,$permitidos,true)) {
                    $this->cerrarEvento($e,'ignorado');
                    return 'ignorado';
                }
                if ($next === 'aprobado' && $s['inmediato'] !== true) throw new RuntimeException('MEDIO_NO_INMEDIATO');
                $this->q('UPDATE pago_transacciones SET estado=?,pago_proveedor_id=?,verificado_en=clock_timestamp() WHERE id=?', [$next,$s['id'],$p['id']]);
                if ($next === 'aprobado') {
                    $otro = $this->q('SELECT 1 FROM pago_transacciones WHERE fk_reserva=? AND id<>? AND confirmo_reserva', [$r['id'],$p['id']])->fetchColumn();
                    $confirmado = $p['confirmo_reserva'];
                    if ($r['estado'] === 'pendiente_pago' && $r['vigente'] && !$otro) {
                        // Revalidar el plazo en la escritura: puede vencer despues de obtener el lock.
                        $confirmado = $this->q("UPDATE reservas SET estado='reservado',actualizado_en=clock_timestamp()
                            WHERE id=? AND estado='pendiente_pago' AND pago_vence_en>clock_timestamp()", [$r['id']])->rowCount() === 1;
                        if ($confirmado) $this->q('UPDATE pago_transacciones SET confirmo_reserva=TRUE WHERE id=?', [$p['id']]);
                    }
                    if (!$confirmado) {
                        $this->q("UPDATE reservas SET estado='vencido',actualizado_en=clock_timestamp()
                            WHERE id=? AND estado='pendiente_pago' AND pago_vence_en<=clock_timestamp()", [$r['id']]);
                        $this->crearReembolso((int)$p['id'], $otro ? 'pago_duplicado' : 'pago_tardio', null);
                    }
                }
                $this->cerrarEvento($e,'procesado',(int)$p['id']);
                return 'procesado';
            });
        } catch (Throwable $error) {
            $this->q("UPDATE pago_webhook_eventos SET estado='error',codigo_error='CONSULTA_O_CONCILIACION',
                proximo_intento_en=clock_timestamp()+interval '1 minute',bloqueo_hasta=NULL,actualizado_en=clock_timestamp()
                WHERE id=? AND estado='procesando' AND bloqueo_hasta=?", [$e['id'],$e['bloqueo_hasta']]);
            // No persistir mensajes del adaptador ni respuestas del proveedor.
            throw new RuntimeException('EVENTO_REQUIERE_REINTENTO');
        }
    }

    private function cerrarEvento(array $e, string $estado, ?int $pago = null): void
    {
        $s = $this->q("UPDATE pago_webhook_eventos SET estado=?,fk_pago=COALESCE(?,fk_pago),procesado_en=clock_timestamp(),
                actualizado_en=clock_timestamp(),bloqueo_hasta=NULL,codigo_error=NULL,proximo_intento_en=NULL
            WHERE id=? AND estado='procesando' AND bloqueo_hasta=? AND bloqueo_hasta>clock_timestamp()", [$estado,$pago,$e['id'],$e['bloqueo_hasta']]);
        if ($s->rowCount() !== 1) throw new RuntimeException('LEASE_PERDIDO');
    }

    private function crearReembolso(int $pagoId, string $motivo, ?int $usuario): void
    {
        $this->q("INSERT INTO pago_reembolsos(fk_pago,fk_conexion,importe_ars,motivo,solicitado_por)
            SELECT id,fk_conexion,importe_ars,?,? FROM pago_transacciones WHERE id=?
            ON CONFLICT(fk_pago) DO NOTHING", [$motivo,$usuario,$pagoId]);
    }

    public function vencerRetenciones(): int
    {
        return $this->tx(fn() => $this->q("UPDATE reservas SET estado='vencido',actualizado_en=clock_timestamp()
            WHERE estado='pendiente_pago' AND pago_vence_en<=clock_timestamp()")->rowCount());
    }

    public function cancelarUsuario(int $reservaId, int $usuarioId): void
    {
        $this->tx(function () use ($reservaId,$usuarioId) {
            $r = $this->reserva($reservaId);
            if ((int)$r['fk_usuario'] !== $usuarioId) throw new RuntimeException('RESERVA_NO_ENCONTRADA');
            if ($r['estado'] === 'cancelado') return;
            if (!in_array($r['estado'],['reservado','pendiente_pago','pendiente_presencial'],true)) throw new RuntimeException('RESERVA_NO_CANCELABLE');
            $anticipada = $this->q("SELECT ((fecha_de_asistencia+horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')
                >= clock_timestamp()+interval '24 hours' FROM reservas WHERE id=?", [$reservaId])->fetchColumn();
            $this->q("UPDATE reservas SET estado='cancelado',cancelado_en=clock_timestamp(),cancelacion_origen='usuario',
                motivo_cancelacion='Cancelacion solicitada por titular',cancelado_por_usuario=?,actualizado_en=clock_timestamp()
                WHERE id=?", [$usuarioId,$reservaId]);
            if ($anticipada) {
                $pagos = $this->q("SELECT id FROM pago_transacciones WHERE fk_reserva=? AND estado='aprobado' ORDER BY id FOR UPDATE", [$reservaId])->fetchAll();
                foreach ($pagos as $p) $this->crearReembolso((int)$p['id'],'cancelacion_usuario',$usuarioId);
            }
        });
    }

    /** Sin lease de reembolso en 002: serializar por advisory lock de sesion, sin transaccion de red.
     * Solo consumir conexiones PDO no persistentes; siempre liberar el lock al terminar.
     */
    public function procesarReembolso(int $id): string
    {
        if ($this->db->inTransaction()) throw new RuntimeException('TRANSACCION_EXTERNA');
        if (!$this->q('SELECT pg_try_advisory_lock(20261026, ?::integer)', [$id])->fetchColumn()) return 'ocupado';
        try {
            $r = $this->q('SELECT * FROM pago_reembolsos WHERE id=?', [$id])->fetch();
            if (!$r) throw new RuntimeException('REEMBOLSO_NO_ENCONTRADO');
            if (in_array($r['estado'],['aprobado','rechazado'],true)) return $r['estado'];
            $p = $this->q('SELECT * FROM pago_transacciones WHERE id=?', [$r['fk_pago']])->fetch();
            $this->q("UPDATE pago_reembolsos SET estado='procesando',intentos=intentos+1,codigo_error=NULL WHERE id=?", [$id]);
            // El adaptador reconcilia solicitudes previas antes de repetir; siempre misma clave.
            $s = $this->proveedor->reembolsar($this->conexion((int)$r['fk_conexion']),$p,$r['clave_idempotencia']);
            if (trim($s['id']) === '' || $s['pago_id'] !== $p['pago_proveedor_id'] || $s['moneda'] !== 'ARS' ||
                self::importe($s['importe']) !== self::importe($r['importe_ars']) ||
                !in_array($s['estado'],['pendiente','aprobado','rechazado'],true)) throw new RuntimeException('REEMBOLSO_NO_COINCIDE');
            return $this->tx(function () use ($id,$r,$p,$s) {
                $this->reserva((int)$p['fk_reserva']);
                $this->q('SELECT id FROM pago_transacciones WHERE id=? FOR UPDATE', [$p['id']]);
                $this->q('UPDATE pago_reembolsos SET estado=?,reembolso_proveedor_id=?,verificado_en=clock_timestamp(),codigo_error=NULL WHERE id=?', [$s['estado'],$s['id'],$id]);
                if ($s['estado'] === 'aprobado') $this->q("UPDATE pago_transacciones SET estado='reembolsado' WHERE id=?", [$r['fk_pago']]);
                return $s['estado'];
            });
        } catch (Throwable $error) {
            $this->q("UPDATE pago_reembolsos SET estado='error',codigo_error='CONCILIAR_REEMBOLSO' WHERE id=? AND estado<>'aprobado'", [$id]);
            throw new RuntimeException('REEMBOLSO_REQUIERE_CONCILIACION');
        } finally {
            $this->q('SELECT pg_advisory_unlock(20261026, ?::integer)', [$id]);
        }
    }
}
