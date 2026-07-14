<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryPerformanceGenerated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    // Id del usuario owner dueño del reporte generado.
    public $user_id;

    // Cantidad de artículos que quedaron bajo el stock mínimo en el reporte recién generado.
    public $stock_minimo;

    /**
     * @param int $user_id      Id del usuario owner.
     * @param int $stock_minimo Cantidad de artículos bajo el mínimo.
     */
    public function __construct(int $user_id, int $stock_minimo)
    {
        $this->user_id = $user_id;
        $this->stock_minimo = $stock_minimo;
    }

    /**
     * Canal público al que se emite el evento.
     */
    public function broadcastOn()
    {
        return new Channel('inventory_performance.'.$this->user_id);
    }

    /**
     * Nombre del evento con el que lo escucha el frontend.
     */
    public function broadcastAs()
    {
        return 'InventoryPerformanceGenerated';
    }

    /**
     * Payload que recibe el frontend. No se manda el reporte entero por el socket:
     * sólo el número, para que el front decida si vuelve a pedir el endpoint.
     */
    public function broadcastWith()
    {
        return [
            'stock_minimo' => $this->stock_minimo,
        ];
    }
}
