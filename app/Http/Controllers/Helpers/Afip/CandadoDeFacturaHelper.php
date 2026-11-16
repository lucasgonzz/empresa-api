<?php

namespace App\Http\Controllers\Helpers\Afip;

use Illuminate\Support\Facades\DB;

/** Los candados fiscales no vencen mientras ARCA está contestando ni dependen de un rollback. */
class CandadoDeFacturaHelper
{
    public static function ventas(array $ids, callable $accion)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        $claves = array_map(function ($id) { return 'venta:'.$id; }, $ids);
        return self::con($claves, $accion);
    }

    public static function emisor($emisor, callable $accion)
    {
        // Dos configuraciones del mismo CUIT/punto comparten la numeración de ARCA.
        return self::con(['emisor:'.preg_replace('/\D/', '', $emisor->cuit).':'.(int) $emisor->punto_venta], $accion);
    }

    private static function con(array $claves, callable $accion)
    {
        $conexion = DB::connection();
        $tomados = [];
        try {
            foreach ($claves as $clave) {
                $nombre = 'cc-fiscal:'.sha1($conexion->getDatabaseName().':'.$clave);
                $resultado = $conexion->select('SELECT GET_LOCK(?, 0) AS tomado', [$nombre]);
                if (empty($resultado) || (int) $resultado[0]->tomado !== 1) {
                    FacturaDeVentaHelper::rechazar('Hay una facturación o consolidación en curso. Esperá a que termine antes de volver a confirmar.');
                }
                $tomados[] = $nombre;
            }
            return $accion();
        } finally {
            foreach (array_reverse($tomados) as $nombre) {
                $conexion->select('SELECT RELEASE_LOCK(?) AS liberado', [$nombre]);
            }
        }
    }
}
