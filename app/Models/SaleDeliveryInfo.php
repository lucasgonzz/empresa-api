<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Overrides opcionales de datos de envío para una venta (etiqueta / PDF).
 * Si los campos están vacíos en persistencia, la UI y el PDF usan los datos del Client.
 *
 * Campos: first_name, last_name, phone, dni, cuit, address, locality, province, postal_code, email.
 * `address` es la calle y número del destinatario (misión etiqueta-envio-direccion, 9/10/2026); si
 * está vacía, la etiqueta usa el domicilio del cliente (la columna de texto `clients.address`).
 */
class SaleDeliveryInfo extends Model
{
    protected $guarded = [];

    /**
     * Scope requerido por fullModel() del Controller base.
     */
    public function scopeWithAll($query)
    {
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
