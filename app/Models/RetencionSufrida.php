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
     * donde caer, así que el alta lo normaliza contra esta lista. La única excepción son los
     * impuestos propios del comercio (`imp_<id>`, ver RetencionImpuesto), que viajan en la misma
     * columna pero son informativos: no restan contra ningún saldo.
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
     * Normaliza el impuesto que llegó del formulario: uno de los tres que la Posición Fiscal sabe
     * restar, o —desde la misión retenciones-abm-impuestos (8/10/2026)— un impuesto propio del
     * comercio (`imp_<id>`). Lo que no es ninguno de los dos cae en IMPUESTO_POR_DEFECTO (ver su
     * PHPDoc).
     *
     * 🔴 UN `imp_<id>` SOLO ENTRA SI ES DEL DUEÑO. El valor viaja desde la SPA y es texto libre: un
     * `imp_7` de otro comercio, uno que no existe o uno sin `$user_id` para comprobarlo se tratan
     * como cualquier valor desconocido y caen en ganancias, igual que antes de esta misión. Se
     * devuelve la forma canónica (`imp_07` queda `imp_7`) para que la búsqueda por texto exacto del
     * resto del sistema (el borrado del impuesto, la suma de la Posición Fiscal) lo encuentre.
     *
     * @param  string|null $impuesto
     * @param  int|null    $user_id  El dueño del cobro. Sin él, los `imp_<id>` no se aceptan.
     * @return string
     */
    static function normalizar_impuesto($impuesto, $user_id = null) {

        if (is_null($impuesto)) {

            return self::IMPUESTO_POR_DEFECTO;
        }

        $impuesto = strtolower(trim((string) $impuesto));

        if (in_array($impuesto, self::IMPUESTOS)) {

            return $impuesto;
        }

        $impuesto_id = RetencionImpuesto::id_de_clave($impuesto);

        if ($impuesto_id > 0 && !is_null($user_id)) {

            $es_del_dueno = RetencionImpuesto::where('id', $impuesto_id)
                                                ->where('user_id', $user_id)
                                                ->exists();

            if ($es_del_dueno) {

                return RetencionImpuesto::clave($impuesto_id);
            }
        }

        return self::IMPUESTO_POR_DEFECTO;
    }

    /**
     * Nombre del impuesto para mostrar en el detalle del reporte.
     *
     * Los tres de siempre salen de una tabla fija. Un `imp_<id>` se resuelve por id contra
     * `retencion_impuestos`: con `$nombres` (un mapa `[id => nombre]` ya cargado, para no hacer una
     * consulta por fila en un listado) o, si no viene, consultando la tabla scopeada por
     * `$user_id`. Si el impuesto ya no existe se dice "Impuesto eliminado" y no el código crudo.
     *
     * @param  string|null $impuesto
     * @param  int|null    $user_id  Dueño, para scopear la consulta de un `imp_<id>`.
     * @param  array|null  $nombres  Mapa `[id => nombre]` precargado.
     * @return string
     */
    static function nombre_impuesto($impuesto, $user_id = null, $nombres = null) {

        $fijos = [
            'iva'       => 'IVA',
            'iibb'      => 'IIBB',
            'ganancias' => 'Ganancias',
        ];

        if (isset($fijos[$impuesto])) {

            return $fijos[$impuesto];
        }

        $impuesto_id = RetencionImpuesto::id_de_clave($impuesto);

        if ($impuesto_id > 0) {

            if (is_array($nombres)) {

                return isset($nombres[$impuesto_id]) ? (string) $nombres[$impuesto_id] : 'Impuesto eliminado';
            }

            $query = RetencionImpuesto::where('id', $impuesto_id);

            if (!is_null($user_id)) {

                $query->where('user_id', $user_id);
            }

            $model = $query->first();

            return !is_null($model) ? (string) $model->name : 'Impuesto eliminado';
        }

        return (string) $impuesto;
    }
}
