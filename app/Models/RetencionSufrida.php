<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un certificado de retención que le practicaron al comercio (misión
 * compras-factura-manual-alicuotas, 17/9/2026, parte C).
 *
 * El hecho ocurre cuando un CLIENTE que es agente de retención te paga: te retiene una parte y te
 * entrega el certificado. El monto retenido viaja como un medio de pago más del cobro (tipo con
 * slug `retencion`, que cancela deuda sin entrar a la caja, igual que el cheque) y acá quedan los
 * datos administrativos del papel, que son los que después necesita la Posición Fiscal.
 *
 * 🔴 EL IMPORTE DE ESTA FILA NO CANCELA NADA POR SÍ SOLO. Lo que cancela la deuda es la fila de
 * `current_acount_payment_methods`; esta tabla es el respaldo documental. Si alguien alguna vez
 * necesita cambiar cuánto se retuvo, tiene que cambiar las dos puntas, y por eso el alta de los
 * certificados cuelga del alta del cobro y su baja del `deleting` de CurrentAcount.
 *
 * Ver la migración `create_retenciones_sufridas_table` para el detalle de por qué casi todos los
 * campos son nullable y por qué `regimen` es texto libre.
 */
class RetencionSufrida extends Model
{
    /**
     * La tabla no se deduce del nombre de la clase (Laravel pluralizaría "retencion_sufridas").
     *
     * @var string
     */
    protected $table = 'retenciones_sufridas';

    protected $guarded = [];

    /**
     * Los tres impuestos que la Posición Fiscal sabe restar. Cualquier otro valor no tiene renglón
     * donde caer, así que el alta lo normaliza contra esta lista.
     *
     * @var array<int,string>
     */
    const IMPUESTOS = ['ganancias', 'iva', 'iibb'];

    /**
     * Impuesto al que va a parar un certificado que llegó sin impuesto o con uno desconocido.
     *
     * 🔴 Es GANANCIAS y no IVA ni IIBB a propósito: el renglón de Ganancias de la Posición Fiscal
     * es un acumulador PURAMENTE INFORMATIVO de pagos a cuenta (ver
     * PosicionFiscalHelper::pagos_a_cuenta_ganancias()), mientras que IVA e IIBB entran a la cuenta
     * de un saldo de DDJJ. Un dato flojo que cae en el acumulador informativo se ve y se corrige;
     * el mismo dato cayendo en IVA le cambia el impuesto a pagar del período sin que nadie lo
     * declare. Ante la duda, el bucket que no hace aritmética fiscal.
     *
     * @var string
     */
    const IMPUESTO_POR_DEFECTO = 'ganancias';

    /** Certificado cargado en el cobro de la cuenta corriente de un cliente: el circuito normal. */
    const ORIGEN_COBRO = 'cobro';

    /**
     * Fila reconstruida por la migración de datos a partir de las columnas viejas de
     * `provider_order_afip_tickets`. No tiene cliente ni cobro: no hay de dónde sacarlos.
     */
    const ORIGEN_MIGRACION_COMPRA = 'migracion_factura_compra';

    /**
     * El cobro donde se cargó el certificado.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function current_acount() {
        return $this->belongsTo(CurrentAcount::class);
    }

    /**
     * El agente de retención: el cliente que pagó reteniendo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function client() {
        return $this->belongsTo(Client::class);
    }

    /**
     * La factura de compra de la que salió la fila, cuando el origen es la migración de datos.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function provider_order_afip_ticket() {
        return $this->belongsTo(ProviderOrderAfipTicket::class);
    }

    function scopeWithAll($q) {
        $q->with('client');
    }

    /**
     * Normaliza el impuesto que llegó del formulario contra los tres que la Posición Fiscal sabe
     * restar. Lo que no está en la lista cae en IMPUESTO_POR_DEFECTO (ver su PHPDoc).
     *
     * @param  string|null $impuesto
     * @return string
     */
    static function normalizar_impuesto($impuesto) {

        if (is_null($impuesto)) {

            return self::IMPUESTO_POR_DEFECTO;
        }

        $impuesto = strtolower(trim((string) $impuesto));

        if (!in_array($impuesto, self::IMPUESTOS)) {

            return self::IMPUESTO_POR_DEFECTO;
        }

        return $impuesto;
    }

    /**
     * Nombre del impuesto para mostrar en el detalle del reporte.
     *
     * @param  string|null $impuesto
     * @return string
     */
    static function nombre_impuesto($impuesto) {

        $nombres = [
            'iva'       => 'IVA',
            'iibb'      => 'IIBB',
            'ganancias' => 'Ganancias',
        ];

        if (isset($nombres[$impuesto])) {

            return $nombres[$impuesto];
        }

        return (string) $impuesto;
    }
}
