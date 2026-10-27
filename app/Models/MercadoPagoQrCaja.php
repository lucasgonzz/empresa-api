<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un QR de Mercado Pago del comercio para cobrar en el mostrador (misión cobro-qr-mercado-pago,
 * 26/10/2026).
 *
 * Es la pareja sucursal + caja ("store" + "pos") que vive en la cuenta de Mercado Pago del
 * comercio. El QR es ESTÁTICO: se imprime una vez y queda pegado en el mostrador; lo que cambia
 * en cada venta es la orden que se le carga (ver `MercadoPagoQrCobro`).
 *
 * `mp_user_id` es la cuenta de MP con la que se creó. Si el comercio desconecta y conecta OTRA
 * cuenta, este QR queda inactivo (`activo: false` en la API) y no se usa para cobrar: el token de
 * la cuenta nueva no puede cargarle órdenes a una caja de la vieja. Se borra con soft delete para
 * no perder el rastro de los cobros que lo usaron.
 *
 * Toda la conversación con Mercado Pago vive en `App\Services\MercadoPago\MercadoPagoQrService`.
 */
class MercadoPagoQrCaja extends Model
{
    use SoftDeletes;

    protected $table = 'mercado_pago_qr_cajas';

    protected $guarded = [];

    /**
     * Scope estándar del proyecto. Carga la caja y la sucursal, que son las que dan el
     * `caja_name` y el `address_name` de la forma `QrCaja` que consume el SPA.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with('caja', 'address');
    }

    /**
     * Caja de ComercioCity del QR (null si es el QR "General").
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function caja()
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    /**
     * Sucursal de ComercioCity del QR (null si no se eligió ninguna).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function address()
    {
        return $this->belongsTo(Address::class, 'address_id');
    }

    /**
     * Cobros que se le cargaron a este QR.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function cobros()
    {
        return $this->hasMany(MercadoPagoQrCobro::class, 'mercado_pago_qr_caja_id');
    }
}
