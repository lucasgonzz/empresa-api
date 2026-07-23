<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserConfiguration extends Model
{
    protected $guarded = [];

    /**
     * Valor de condicion_iva_precios para Responsable Inscripto.
     * Comportamiento actual del sistema: costo neto, IVA sumado al vender.
     */
    const CONDICION_RRII = 'RRII';

    /**
     * Valor de condicion_iva_precios para Monotributista.
     * El monotributista no recupera IVA: se asume que los precios ya lo incluyen.
     */
    const CONDICION_MT = 'MT';

    function scopeWithAll($q) {

    }

    /**
     * Indica si la cuenta calcula precios/costos como Monotributista.
     * (No recupera IVA: se asume que los precios de compra ya lo incluyen).
     *
     * @return bool
     */
    public function es_monotributista() {
        return $this->condicion_iva_precios == self::CONDICION_MT;
    }

    /**
     * Indica si la cuenta calcula precios/costos como Responsable Inscripto.
     * (El usuario indica por compra si el precio ya incluye IVA; el costo base queda neto).
     *
     * @return bool
     */
    public function es_responsable_inscripto() {
        return $this->condicion_iva_precios == self::CONDICION_RRII;
    }
}
