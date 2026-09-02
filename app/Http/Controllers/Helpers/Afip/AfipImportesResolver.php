<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipTicket;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve importes fiscales de un comprobante AFIP.
 * Prioriza el snapshot persistido al autorizar (FECAESolicitar) y recalcula solo como fallback.
 *
 * Ademas es la FUENTE UNICA de la tabla de alicuotas de IVA (ver `alicuotas()`).
 */
class AfipImportesResolver
{
    /**
     * FUENTE UNICA de la tabla de alicuotas de IVA. Clave interna => Id de ARCA + porcentaje real.
     *
     * 🔴 Las claves NO son el porcentaje: `'10'` significa 10,5 % y `'2'` significa 2,5 %.
     * Son claves internas historicas, alineadas con los Id de ARCA (4 y 9), y estan congeladas
     * porque son un CONTRATO con `empresa-spa` (el front manda `'10'`/`'2'` en el reparto del
     * importe personalizado, y `MakeAfipTicket::validar_filas_importe_personalizado()` rechaza
     * con 422 cualquier otra) y porque hay datos ya persistidos en produccion con esas claves
     * en `afip_tickets.importe_personalizado_ivas_json`.
     *
     * Quien la consume:
     *  - `AfipImportesCalculator::default_ivas()` arma el acumulador de buckets desde aca.
     *  - `MakeAfipTicket::keys_de_alicuota_validas()` publica estas claves como contrato de API.
     *  - `resolve_from_snapshot()` traduce el Id de ARCA que volvio autorizado a la clave interna.
     *  - Los cuatro renderers de PDF/ticket imprimen `etiqueta_de_clave()`, NUNCA la clave cruda.
     *
     * 🔴 Nadie deriva el porcentaje casteando la clave a numero, y nadie arma una clave con
     * `(string) $porcentaje`. Para eso estan `porcentaje_de_clave()` y `clave_de_porcentaje()`.
     *
     * 🔴 Agregar o cambiar una alicuota se hace ACA y en ningun otro lado. El orden importa:
     * `AfipImportesCalculator::repartir_importe_personalizado()` le encaja el descuadre de
     * centavos a la ULTIMA fila viva, que tiene que ser la de alicuota MENOR.
     *
     * @var array<string, array>
     */
    protected static $alicuotas = [
        '27' => ['id' => 6, 'porcentaje' => 27.0],
        '21' => ['id' => 5, 'porcentaje' => 21.0],
        '10' => ['id' => 4, 'porcentaje' => 10.5],
        '5'  => ['id' => 8, 'porcentaje' => 5.0],
        '2'  => ['id' => 9, 'porcentaje' => 2.5],
        '0'  => ['id' => 3, 'porcentaje' => 0.0],
    ];

    /**
     * Tabla completa de alicuotas. Ver el docblock de `$alicuotas`: es LA fuente unica.
     *
     * @return array Mapa clave interna => ['id' => int, 'porcentaje' => float].
     */
    public static function alicuotas(): array
    {
        return self::$alicuotas;
    }

    /**
     * Claves internas de alicuota, en el orden fijo '27','21','10','5','2','0'.
     *
     * 🔴 El `(string)` no es decorativo: PHP convierte a ENTERO toda clave de array que sea un
     * string numerico, asi que `array_keys()` devuelve `[27, 21, 10, 5, 2, 0]`. El contrato con
     * `empresa-spa` que publica `MakeAfipTicket::keys_de_alicuota_validas()` es de STRINGS, y el
     * test lo compara con `assertSame`. Sacar el cast rompe el contrato sin cambiar el 422.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        /** @var array<int, string> $keys Claves ya normalizadas a string. */
        $keys = [];

        foreach (self::$alicuotas as $key => $alicuota) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * Id de ARCA de una clave interna.
     *
     * @param string $key Clave interna ('27','21','10','5','2','0').
     * @return int|null Null si la clave no esta en la tabla.
     */
    public static function id_de_clave($key): ?int
    {
        $key = (string) $key;

        if (!isset(self::$alicuotas[$key])) {
            return null;
        }

        return (int) self::$alicuotas[$key]['id'];
    }

    /**
     * Porcentaje REAL de una clave interna ('10' => 10.5).
     *
     * @param string $key Clave interna.
     * @return float|null Null si la clave no esta en la tabla.
     */
    public static function porcentaje_de_clave($key): ?float
    {
        $key = (string) $key;

        if (!isset(self::$alicuotas[$key])) {
            return null;
        }

        return (float) self::$alicuotas[$key]['porcentaje'];
    }

    /**
     * Clave interna a partir del Id de ARCA.
     *
     * @param int $iva_id Id de alicuota de ARCA.
     * @return string|null Null si el Id no esta en la tabla.
     */
    public static function clave_de_id($iva_id): ?string
    {
        foreach (self::$alicuotas as $key => $alicuota) {
            if ((int) $alicuota['id'] === (int) $iva_id) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Clave interna a partir del porcentaje real. Es la traduccion que necesitan las
     * descripciones libres, donde el porcentaje viene de `ivas.percentage` (un STRING).
     *
     * 🔴 Dos cosas que NO se pueden "simplificar":
     *  1. El `is_numeric()` va primero. Sin el, `'Exento'` y `'No Gravado'` se castean a `0.0`
     *     y se cuelan como clave `'0'` sin que nadie lo note. Que caigan en `'0'` es una
     *     decision explicita de quien llama (ver `AfipImportesCalculator`), no un accidente
     *     de esta funcion.
     *  2. La comparacion es por tolerancia, no `==` ni `(string) (float) $x`. Castear un float
     *     a texto depende del ini `precision`, y esa fragilidad es exactamente la que esta
     *     funcion viene a sacar del sistema.
     *
     * @param mixed $porcentaje Porcentaje real (10.5, '10.5', 21, 'Exento', ...).
     * @return string|null Null si no es numerico o no corresponde a ninguna alicuota.
     */
    public static function clave_de_porcentaje($porcentaje): ?string
    {
        if (!is_numeric($porcentaje)) {
            return null;
        }

        /** @var float $valor Porcentaje ya normalizado a numero. */
        $valor = (float) $porcentaje;

        foreach (self::$alicuotas as $key => $alicuota) {
            if (abs(((float) $alicuota['porcentaje']) - $valor) < 0.0001) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Etiqueta VISIBLE de una clave interna: lo que se imprime en un PDF o en un ticket.
     *
     * 🔴 Es la contracara del error que esta mision cierra: la clave `'10'` se imprimia tal cual
     * y en el papel salia "IVA 10%" donde iba 10,5 %. Ningun renderer arma la etiqueta con la
     * clave: la pide aca.
     *
     * Se deriva con `(string) $porcentaje`, que da exactamente '27','21','10.5','5','2.5','0'.
     * No se pasa a '10,5' con coma: eso cambiaria una salida que hoy ya es correcta.
     *
     * Con una clave desconocida LOGUEA y devuelve la clave tal cual, sin tirar. Una etiqueta rara
     * en un papel es feo; lo que no puede fallar en silencio es el `Id` que va a ARCA, y de eso
     * se ocupa `AfipImportesCalculator::add_iva_bucket()`, que ahi si tira.
     *
     * @param string $key Clave interna.
     * @return string Etiqueta a imprimir (sin el signo %).
     */
    public static function etiqueta_de_clave($key): string
    {
        $key = (string) $key;

        /** @var float|null $porcentaje Porcentaje real de la clave. */
        $porcentaje = self::porcentaje_de_clave($key);

        if (is_null($porcentaje)) {
            Log::warning(
                'AfipImportesResolver: no hay etiqueta para la clave de alicuota "'.$key.'". '.
                'Se imprime la clave tal cual. Si es una alicuota nueva, se agrega en '.
                'AfipImportesResolver::$alicuotas.'
            );

            return $key;
        }

        return (string) $porcentaje;
    }

    /**
     * Renglones de IVA a imprimir: los buckets con importe > 0, ya con su etiqueta visible.
     *
     * Unifica el filtro `Importe > 0` que estaba repetido (y con tres redacciones distintas) en
     * `TicketInfoHelper`, `AfipTicketPdf` y `SaleTicketPdf`.
     *
     * @param array $importes Estructura de `resolve()` / `AfipHelper::getImportes()`.
     * @return array<int, array> Lista de ['etiqueta' => string, 'importe' => float].
     */
    public static function renglones_de_iva($importes): array
    {
        /** @var array $renglones Renglones listos para imprimir. */
        $renglones = [];

        if (!isset($importes['ivas']) || !is_array($importes['ivas'])) {
            return $renglones;
        }

        foreach ($importes['ivas'] as $key => $bucket) {
            /** @var float $importe Importe de IVA de ese bucket. */
            $importe = isset($bucket['Importe']) ? (float) $bucket['Importe'] : 0;

            if ($importe <= 0) {
                continue;
            }

            $renglones[] = [
                'etiqueta' => self::etiqueta_de_clave($key),
                'importe'  => $importe,
            ];
        }

        return $renglones;
    }

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
            /**
             * 🔴 Un renglon sin `Id`, o con un `Id` que no esta en la tabla, se LOGUEA y se saltea.
             * Se loguea porque significa que ARCA autorizo una alicuota que este sistema no conoce
             * y su plata desaparece del desglose sin que nadie se entere (el error viejo era el
             * `continue` mudo). Y se saltea en vez de tirar porque un comprobante YA AUTORIZADO
             * tiene que poder imprimirse igual: romper la impresion no le devuelve el renglon a nadie.
             */
            if (!isset($iva_row['Id'])) {
                Log::error(
                    'AfipImportesResolver: renglon de iva_detalle_enviado_json sin Id en el afip_ticket '.
                    $afip_ticket->id.'. El renglon se ignora en el desglose.'
                );
                continue;
            }
            $iva_label = self::clave_de_id((int) $iva_row['Id']);
            if ($iva_label === null) {
                Log::error(
                    'AfipImportesResolver: Id de alicuota desconocido ('.$iva_row['Id'].') en el afip_ticket '.
                    $afip_ticket->id.'. El renglon se ignora en el desglose. Si es una alicuota nueva de ARCA, '.
                    'se agrega en AfipImportesResolver::$alicuotas.'
                );
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
     * Convierte Id de alicuota de ARCA a la CLAVE INTERNA del bucket de IVA.
     *
     * 🔴 Devuelve `'10'` (no `'10.5'`) y `'2'` (no `'2.5'`): es la misma clave que produce el
     * recalculo en `AfipImportesCalculator::default_ivas()`. Que las dos fuentes —snapshot y
     * recalculo— emitan la MISMA clave es justamente la unificacion: antes, el mismo comprobante
     * se imprimia y se exportaba distinto segun por donde entraba.
     *
     * Se conserva el nombre viejo porque es la firma publica que ya usaban los llamadores.
     *
     * @param int $iva_id Identificador de alicuota de ARCA.
     * @return string|null Clave interna, o null si el Id no esta en la tabla.
     */
    public static function iva_id_to_label(int $iva_id): ?string
    {
        return self::clave_de_id($iva_id);
    }
}
