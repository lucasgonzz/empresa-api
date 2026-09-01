<?php

namespace App\Http\Controllers\Helpers\contabilidad;

use App\Models\SaleTax;

/**
 * Posición fiscal del período: IVA, IIBB y pagos a cuenta de Ganancias (Grupo 225, Prompt 02).
 *
 * Le dice al negocio cuánto impuesto le va a tocar pagar, con los renglones separados como los pide
 * la DDJJ (nunca pre-neteados): un contador necesita ver cada línea, no un número final solo.
 *
 * Los campos fiscales (`percepcion_iva`, `percepcion_iibb`, `retencion_iva`, `retencion_iibb`,
 * `retencion_ganancias` en `provider_order_afip_tickets`) ya existen desde la migración de
 * 2026-02-25 y los clientes ya los cargan, pero hasta este prompt eran puramente informativos:
 * nunca se conectaron a nada. Este prompt los conecta del lado de REPORTES. Lo que queda pendiente
 * para el Grupo D es el otro lado (que la percepción sume a la deuda con el proveedor en su cuenta
 * corriente) — NO se toca acá.
 *
 * 🔴 Cero queries propias: este helper consume EXCLUSIVAMENTE `ContabilidadRepository`, mismo
 * contrato que `EstadoResultadosHelper` (prompt 01).
 *
 * 🔴 Cero `auth()` / `Auth::` / `request()` / sesión global: igual que `EstadoResultadosHelper`,
 * este helper puede terminar consumido por procesos sin sesión HTTP (crons). Todos los métodos
 * reciben `$user_id` como primer parámetro explícito. El controller es el único que puede leer
 * `auth()->user()`, y lo hace para pasarle el id acá.
 *
 * 🔴 Sin dimensión de moneda: a diferencia de `EstadoResultadosHelper::estado_resultados()`, ningún
 * método de acá acepta ni aplica `moneda`/cotización. Todos los renglones (IVA débito, IVA crédito,
 * percepciones, retenciones, IIBB determinado) salen de métodos de `ContabilidadRepository` que NO
 * aceptan `$filtros`: son globales y ya vienen en pesos, porque los impuestos se liquidan en pesos
 * sobre toda la operación, sin dimensión de moneda. Combinarlos con una cotización los infla por
 * error (bug real que ya hizo fallar tres veces al prompt 01 de este mismo grupo).
 */
class PosicionFiscalHelper
{
    /**
     * Posición de IVA del período: débito de ventas menos crédito de compras/gastos, menos
     * percepciones y retenciones de IVA sufridas como comprador (créditos fiscales recuperables,
     * renglones separados, nunca neteados de entrada).
     *
     * Fórmula:
     *   IVA débito − IVA de notas de crédito emitidas − IVA crédito − Percepciones de IVA sufridas
     *   − Retenciones de IVA sufridas
     *   = IVA estimado a pagar (o a favor, si el resultado es negativo)
     *
     * Ambas puntas (débito y crédito) ya filtran por fecha de EMISION del comprobante
     * (`ContabilidadRepository::iva_debito()`/`iva_credito()`), consistencia resuelta por el grupo
     * 224 — este método no vuelve a tocar esa lógica, solo la consume.
     *
     * @param  int $user_id Owner dueño del reporte (nunca el usuario de sesión).
     * @param  string $desde Fecha de inicio del período (Y-m-d), inclusive.
     * @param  string $hasta Fecha de fin del período (Y-m-d), inclusive.
     * @return array{iva_debito: float, iva_notas_credito: float, notas_credito_sin_medir: int,
     *               iva_credito: float, percepcion_iva_sufrida: float, retencion_iva_sufrida: float,
     *               saldo: float, tipo: string}
     */
    public static function posicion_iva($user_id, $desde, $hasta)
    {
        // Renglones separados, cada uno de una fuente única del repository (regla del prompt: la
        // DDJJ necesita cada línea discriminada, no un neto).
        $iva_debito = ContabilidadRepository::iva_debito($user_id, $desde, $hasta);

        /*
         * Debito fiscal cancelado. Una nota de credito facturada ante ARCA sobre una venta que ya
         * se habia facturado devuelve ese IVA: el comercio ya no lo debe. Va como renglon aparte y
         * no pre-neteado del debito porque la DDJJ los pide discriminados (misma regla que el resto
         * de los renglones de este helper).
         *
         * No hay riesgo de doble conteo con `iva_debito`: el afip_ticket de una NC nace sin
         * `sale_id` (AfipNotaCreditoHelper::create_afip_ticket()), asi que el `join sales` de
         * `query_iva_debito()` lo descarta.
         */
        $iva_notas_credito = ContabilidadRepository::iva_notas_credito($user_id, $desde, $hasta);

        /*
         * Cuantas notas de credito emitidas todavia no tienen el IVA medido, para que la UI pueda
         * decir "no lo se todavia" en vez de mostrar un cero que parece un dato.
         *
         * Sin esto, el dia del despliegue el renglon de arriba da $0 para todo el historico —el IVA
         * de las notas de credito no se persistio hasta el 1/9/2026— y ese cero es visualmente
         * identico al de un mes sin ninguna devolucion. La diferencia entre los dos es el impuesto
         * que el comercio paga de mas, asi que no puede quedar librada a que alguien se acuerde de
         * correr el backfill.
         */
        $notas_credito_sin_medir = ContabilidadRepository::notas_credito_sin_medir($user_id, $desde, $hasta);

        $iva_credito = ContabilidadRepository::iva_credito($user_id, $desde, $hasta);

        $percepciones = ContabilidadRepository::percepciones_sufridas($user_id, $desde, $hasta);
        $retenciones = ContabilidadRepository::retenciones_sufridas($user_id, $desde, $hasta);

        $percepcion_iva_sufrida = $percepciones['iva'];
        $retencion_iva_sufrida = $retenciones['iva'];

        // Saldo final: positivo = a pagar, negativo = a favor del contribuyente. El signo se
        // normaliza recién al armar la respuesta (self::signo_y_monto), acá se deja crudo.
        $saldo = $iva_debito - $iva_notas_credito - $iva_credito - $percepcion_iva_sufrida - $retencion_iva_sufrida;

        list($monto, $tipo) = self::signo_y_monto($saldo);

        return [
            'iva_debito'              => $iva_debito,
            'iva_notas_credito'       => $iva_notas_credito,
            'notas_credito_sin_medir' => $notas_credito_sin_medir,
            'iva_credito'             => $iva_credito,
            'percepcion_iva_sufrida'  => $percepcion_iva_sufrida,
            'retencion_iva_sufrida'   => $retencion_iva_sufrida,
            'saldo'                   => $monto,
            'tipo'                    => $tipo,
        ];
    }

    /**
     * Posición de IIBB del período: impuesto determinado sobre las ventas menos percepciones y
     * retenciones de IIBB sufridas como comprador.
     *
     * Fórmula:
     *   IIBB determinado − Percepciones de IIBB sufridas − Retenciones de IIBB sufridas
     *   = Saldo IIBB (a pagar o a favor)
     *
     * Si el negocio no tiene `sale_taxes` activos configurados, el IIBB determinado es 0 y se
     * devuelve `iibb_configurado: false`, para que la UI pueda explicarlo en vez de mostrar ceros
     * sin contexto (regla 05 del prompt).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @return array{iibb_determinado: float, percepcion_iibb_sufrida: float,
     *               retencion_iibb_sufrida: float, saldo: float, tipo: string,
     *               iibb_configurado: bool}
     */
    public static function posicion_iibb($user_id, $desde, $hasta)
    {
        // El negocio tiene sale_taxes activos configurados: si no, iibb_determinado() ya devuelve 0
        // por su cuenta, pero acá se necesita el flag explícito para que la UI no muestre ceros sin
        // explicación (regla 05 del prompt).
        $iibb_configurado = SaleTax::where('user_id', $user_id)->where('activo', 1)->exists();

        $iibb_determinado = ContabilidadRepository::iibb_determinado($user_id, $desde, $hasta);

        $percepciones = ContabilidadRepository::percepciones_sufridas($user_id, $desde, $hasta);
        $retenciones = ContabilidadRepository::retenciones_sufridas($user_id, $desde, $hasta);

        $percepcion_iibb_sufrida = $percepciones['iibb'];
        $retencion_iibb_sufrida = $retenciones['iibb'];

        $saldo = $iibb_determinado - $percepcion_iibb_sufrida - $retencion_iibb_sufrida;

        list($monto, $tipo) = self::signo_y_monto($saldo);

        return [
            'iibb_determinado'        => $iibb_determinado,
            'percepcion_iibb_sufrida' => $percepcion_iibb_sufrida,
            'retencion_iibb_sufrida'  => $retencion_iibb_sufrida,
            'saldo'                   => $monto,
            'tipo'                    => $tipo,
            'iibb_configurado'        => $iibb_configurado,
        ];
    }

    /**
     * Pagos a cuenta de Ganancias del período: acumulador puramente informativo de las retenciones
     * de Ganancias sufridas como comprador. No calcula el impuesto (eso lo hace el contador); solo
     * entrega el total de pagos a cuenta ya efectuados.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @return array{retencion_ganancias_sufrida: float}
     */
    public static function pagos_a_cuenta_ganancias($user_id, $desde, $hasta)
    {
        $retenciones = ContabilidadRepository::retenciones_sufridas($user_id, $desde, $hasta);

        return [
            'retencion_ganancias_sufrida' => $retenciones['ganancias'],
        ];
    }

    /**
     * Normaliza el signo de un saldo fiscal: cuando el resultado da negativo (más crédito/pago a
     * cuenta que débito/impuesto determinado), es un saldo A FAVOR del contribuyente, no una deuda
     * (regla 03 del prompt). Devuelve siempre el monto en valor absoluto más el tipo explícito, para
     * que la UI no tenga que inferirlo del signo.
     *
     * @param  float $saldo Puede venir positivo o negativo.
     * @return array{0: float, 1: string} [monto (siempre positivo), tipo ('a_pagar'|'a_favor')]
     */
    private static function signo_y_monto($saldo)
    {
        if ($saldo < 0) {
            return [abs($saldo), 'a_favor'];
        }

        return [$saldo, 'a_pagar'];
    }
}
