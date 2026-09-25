<?php

namespace App\Http\Controllers\Helpers\Afip;

/**
 * Leyenda de Ingresos Brutos de CABA en los comprobantes a consumidor final
 * (Res. 169/AGIP/2026; plazo de adecuacion prorrogado por las Res. 312 y 339/AGIP/2026 hasta
 * el 1/1/2027).
 *
 * La norma pide que el comprobante diga "ALÍCUOTA ISIB CABA XX,XX%" y, si el contribuyente
 * tributa por Convenio Multilateral, que agregue "APLICABLE SOBRE INGRESOS BRUTOS ATRIBUIDOS A
 * CABA". ARCA no agrega ningun campo al web service: es texto impreso, y lo arma este helper
 * para que el PDF A4, el ticket PDF y el Ticket 2.0 digan exactamente lo mismo.
 *
 * Solo va cuando se cumplen las tres cosas:
 *   1. el emisor (punto de venta del ticket) tiene cargada la alicuota;
 *   2. el comprobante se le declaro a ARCA como emitido a consumidor final (condicion 5);
 *   3. no es clase A, M ni E (esas nunca van a consumidor final; la norma prohibe la leyenda en
 *      comprobantes a otros eslabones de la cadena).
 *
 * Los textos van sin guion largo: el Cell() de FPDF pasa por utf8_decode() y el Ticket 2.0 manda
 * Latin-1, y en los dos el "—" sale como "?". El Ticket 2.0 ademas le saca la tilde a "ALÍCUOTA"
 * antes de imprimir (la impresora esta en PC850, ver afip_qr_iva.js del SPA); el PDF la conserva.
 */
class LeyendaIsibCabaHelper {

    /**
     * Texto que agrega la leyenda cuando el emisor tributa por Convenio Multilateral.
     */
    const TEXTO_CONVENIO_MULTILATERAL = 'APLICABLE SOBRE INGRESOS BRUTOS ATRIBUIDOS A CABA';

    /**
     * Partes de la leyenda, en el orden en que se imprimen. Un array vacio significa que ESTE
     * comprobante no lleva leyenda.
     *
     * Se devuelven separadas para que cada formato decida como acomodarlas: el A4 las une en un
     * solo renglon y los tickets imprimen cada una en su propio renglon (cortado al ancho).
     *
     * @param \App\Models\AfipTicket|null $afip_ticket
     * @return string[]
     */
    static function partes($afip_ticket) {

        if (is_null($afip_ticket)) {

            return [];
        }

        $afip_information = $afip_ticket->afip_information;

        if (is_null($afip_information)) {

            return [];
        }

        $alicuota = self::alicuota_configurada($afip_information->isib_caba_alicuota);

        if (is_null($alicuota)) {

            return [];
        }

        if (!self::es_a_consumidor_final($afip_ticket)) {

            return [];
        }

        $partes = ['ALÍCUOTA ISIB CABA '.number_format($alicuota, 2, ',', '.').'%'];

        if ($afip_information->isib_caba_convenio_multilateral) {

            $partes[] = self::TEXTO_CONVENIO_MULTILATERAL;
        }

        return $partes;
    }

    /**
     * La leyenda en un solo renglon, o null si el comprobante no la lleva.
     *
     * @param \App\Models\AfipTicket|null $afip_ticket
     * @return string|null
     */
    static function texto($afip_ticket) {

        $partes = self::partes($afip_ticket);

        if (count($partes) == 0) {

            return null;
        }

        return implode(' - ', $partes);
    }

    /**
     * Si el comprobante se emitio a un consumidor final, segun lo que se le declaro a ARCA.
     *
     * @param \App\Models\AfipTicket $afip_ticket
     * @return bool
     */
    static function es_a_consumidor_final($afip_ticket) {

        $letra = strtoupper(trim((string) $afip_ticket->cbte_letra));

        if (in_array($letra, ['A', 'M', 'E'], true)) {

            return false;
        }

        return CondicionIvaReceptorHelper::condicion_declarada_del_ticket($afip_ticket) === 5;
    }

    /**
     * Normaliza la alicuota guardada (o recibida del formulario): vacio, no numerico, cero o
     * negativo es "no aplica" (null). Se descarta el cero a proposito: el formulario generico
     * puede mandar 0 en un numero que nadie toco, y "ALÍCUOTA ISIB CABA 0,00%" impreso en todas
     * las facturas es peor que no imprimir nada (una operacion exenta lleva otra leyenda). Arriba
     * de 100 tampoco es una alicuota: es un error de tipeo (300 por 3,00) y no se imprime.
     *
     * @param mixed $valor
     * @return float|null
     */
    static function alicuota_configurada($valor) {

        if (is_null($valor) || $valor === '' || !is_numeric($valor)) {

            return null;
        }

        $alicuota = round((float) $valor, 2);

        if ($alicuota <= 0 || $alicuota > 100) {

            return null;
        }

        return $alicuota;
    }
}
