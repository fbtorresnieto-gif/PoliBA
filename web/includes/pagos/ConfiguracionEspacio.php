<?php
declare(strict_types=1);

namespace MultiPro\Pagos;

use InvalidArgumentException;

/** Configuracion administrativa de precio y medios disponibles. */
final class ConfiguracionEspacio
{
    public static function leer(array $datos): array
    {
        $entrada = $datos['precio_hora_ars'] ?? null;
        if (!is_string($entrada) || !preg_match('/^[0-9]{1,10}(?:[.,][0-9]{1,2})?$/D', trim($entrada))) {
            throw new InvalidArgumentException('Ingresá un precio entre 0 y 9999999999,99, con hasta dos decimales y sin separadores de miles.');
        }
        $partes = explode('.', str_replace(',', '.', trim($entrada)));
        $precio = (ltrim($partes[0], '0') ?: '0') . '.' . str_pad($partes[1] ?? '', 2, '0');
        foreach (['cobro_online_habilitado','pago_presencial_habilitado'] as $campo) {
            if (isset($datos[$campo]) && (!is_string($datos[$campo]) || !in_array($datos[$campo], ['0','1'], true))) {
                throw new InvalidArgumentException('La configuración del medio de pago no es válida.');
            }
        }
        if (($datos['cobro_online_habilitado'] ?? '0') === '1') {
            throw new InvalidArgumentException('El pago online todavía no está disponible en MultiPro.');
        }
        return ['precio_hora_ars'=>$precio, 'cobro_online_habilitado'=>false,
            'pago_presencial_habilitado'=>$precio !== '0.00' && ($datos['pago_presencial_habilitado'] ?? '0') === '1'];
    }

    /** Formato monetario de presentacion, sin convertir el importe a float. */
    public static function etiqueta(string $precio): string
    {
        if ($precio === '0.00') return 'Gratuito';
        [$entero,$decimales] = explode('.', $precio);
        $entero = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $entero);
        return '$ ' . $entero . ',' . $decimales . ' ARS / hora';
    }
    public static function estadoReserva(string $estado): string {
        return match($estado) {
            'pendiente_presencial'=>'Pago presencial pendiente', 'pendiente_pago'=>'Pago online pendiente',
            'reservado'=>'Reservado', 'cancelado'=>'Cancelado', 'vencido'=>'Vencido', default=>'Estado no disponible',
        };
    }
}
