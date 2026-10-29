<?php
declare(strict_types=1);

namespace MultiPro\Pagos;

/** Adaptador de servidor: autentica, consulta recursos y normaliza sin guardar payloads. */
interface ProveedorPagos
{
    /** @return array{evento_id:string,recurso_id:string,tipo:string} Firma invalida: lanzar excepcion. */
    public function autenticarEvento(array $conexion, array $cabeceras, string $cuerpo, array $consulta): array;

    /** @return array{id:string,referencia:string,cuenta:string,ambiente:string,importe:string,moneda:string,estado:string,inmediato:bool} */
    public function consultarPago(array $conexion, string $recursoId): array;

    /** Reintentar con la MISMA clave; ante incertidumbre consultar primero al proveedor.
     * @return array{id:string,pago_id:string,importe:string,moneda:string,estado:string}
     */
    public function reembolsar(array $conexion, array $pago, string $clave): array;
}
