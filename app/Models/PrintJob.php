<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un ticket esperando que el agente lo levante y lo mande a la comandera.
 *
 * @property int $id
 * @property int $print_agent_id
 * @property string $printer_name
 * @property string $payload_base64
 * @property string $status
 */
class PrintJob extends Model
{
    protected $guarded = [];

    protected $dates = ['tomado_at', 'terminado_at'];

    /**
     * El payload es el ticket entero y no le sirve a nadie del lado del SPA: se omite para no
     * mandar decenas de kilobytes en cada consulta de estado.
     *
     * @var array
     */
    protected $hidden = ['payload_base64'];

    const STATUS_PENDIENTE = 'pendiente';
    const STATUS_TOMADO    = 'tomado';
    const STATUS_IMPRESO   = 'impreso';
    const STATUS_ERROR     = 'error';

    /**
     * Equipo al que le toca imprimirlo.
     */
    function print_agent() {
        return $this->belongsTo(PrintAgent::class);
    }
}
