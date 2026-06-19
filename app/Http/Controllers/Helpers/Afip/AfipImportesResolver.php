<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipTicket;

/**
 * Resuelve importes fiscales de un comprobante AFIP.
 * Prioriza el snapshot persistido al autorizar (FECAESolicitar) y recalcula solo como fallback.
 */
class AfipImportesResolver
{
    /**
     * Mapa AFIP Id de alícuota -> etiqueta interna usada por calculadores legacy.
     *
     * @var array<int, string>
     */
    protected static $iva_id_to_label_map = [
        6 => '27',
        5 => '21',
        4 => '10.5',
        8 => '5',
        9 => '2.5',
        3 => '0',
    ];

    /**
     * Obtiene importes del ticket priorizando snapshot fiscal persistido.
     *
     * @param AfipTicket $afip_ticket Comprobante AFIP con posibles importes enviados.
     * @param AfipHelper|null $afip_helper Helper para recalcular cuando no hay snapshot.
     * @return array Estructura compatible con AfipHelper::getImportes().
     */
    public static function resolve(AfipTicket $afip_ticket, AfipHelper $afip_helper = null): array
    {
        /**
         * Si existe snapshot de autorización, se usa como fuente única para exportaciones fiscales.
         */
        $importes_from_snapshot = self::resolve_from_snapshot($afip_ticket);
        if (!is_null($importes_from_snapshot)) {
            return $importes_from_snapshot;
        }

        /**
         * Fallback histórico: recalcular desde ítems de venta cuando el ticket no tiene snapshot.
         */
        if (!is_null($afip_helper)) {
            return $afip_helper->getImportes();
        }

        return [
            'gravado' => 0,
            'neto_no_gravado' => 0,
            'exento' => 0,
            'iva' => 0,
            'ivas' => [],
            'total' => 0,
        ];
    }

    /**
     * Construye importes desde columnas persistidas al enviar comprobante a AFIP.
     *
     * @param AfipTicket $afip_ticket Ticket con snapshot fiscal.
     * @return array|null Null cuando no hay snapshot disponible.
     */
    public static function resolve_from_snapshot(AfipTicket $afip_ticket): ?array
    {
        if (is_null($afip_ticket->imp_total_enviado)) {
            return null;
        }

        /**
         * Mapa de alícuotas con el mismo formato esperado por exportaciones TXT/PDF.
         */
        $ivas = [];
        /**
         * Detalle de IVA persistido (array por cast o JSON legacy como string).
         */
        $detalle = $afip_ticket->iva_detalle_enviado_json;
        if (is_string($detalle)) {
            $decoded = json_decode($detalle, true);
            if (is_array($decoded)) {
                $detalle = $decoded;
            } else {
                $detalle = [];
            }
        }
        if (!is_array($detalle)) {
            $detalle = [];
        }

        foreach ($detalle as $iva_row) {
            if (!isset($iva_row['Id'])) {
                continue;
            }
            $iva_label = self::iva_id_to_label((int) $iva_row['Id']);
            if ($iva_label === null) {
                continue;
            }
            $ivas[$iva_label] = [
                'BaseImp' => isset($iva_row['BaseImp']) ? (float) $iva_row['BaseImp'] : 0,
                'Importe' => isset($iva_row['Importe']) ? (float) $iva_row['Importe'] : 0,
                'Id' => (int) $iva_row['Id'],
            ];
        }

        return [
            'gravado' => (float) $afip_ticket->imp_neto_enviado,
            'neto_no_gravado' => (float) $afip_ticket->imp_tot_conc_enviado,
            'exento' => (float) $afip_ticket->imp_op_ex_enviado,
            'iva' => (float) $afip_ticket->imp_iva_enviado,
            'ivas' => $ivas,
            'total' => (float) $afip_ticket->imp_total_enviado,
        ];
    }

    /**
     * Convierte Id de alícuota AFIP al texto de porcentaje usado en renderer/calculadores.
     *
     * @param int $iva_id Identificador AFIP de alícuota.
     * @return string|null
     */
    public static function iva_id_to_label(int $iva_id): ?string
    {
        if (!isset(self::$iva_id_to_label_map[$iva_id])) {
            return null;
        }

        return self::$iva_id_to_label_map[$iva_id];
    }
}
