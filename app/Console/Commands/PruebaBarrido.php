<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\PruebaHitosHelper;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use App\Models\Order;
use App\Models\PruebaHito;
use Illuminate\Console\Command;

/**
 * Barrido de la prueba gratuita (misión prueba-experiencia-empresa, 3/11/2026). Corre cada 5 minutos, solo
 * en una instancia de prueba, y registra los dos hitos que no nacen de un request de empresa:
 *
 * - `prueba.tienda_pedido`: el pedido lo escribe tienda-api directo en la base compartida, así que acá no
 *   hay controlador que enganchar. Cuenta el primer pedido del dueño que NO sea de la semilla.
 * - `prueba.vencida`: el negocio puede no volver a entrar después de vencer, y el admin tiene que enterarse
 *   igual (el middleware también lo emite si entra). `prueba_hitos` garantiza una sola emisión.
 *
 * Cada hito se registra en este mismo proceso (ya es un comando, no un request).
 */
class PruebaBarrido extends Command
{
    protected $signature = 'prueba:barrido';

    protected $description = 'Registra los hitos de la prueba gratuita que no nacen de un request (pedido de tienda, vencimiento).';

    /**
     * @return int
     */
    public function handle()
    {
        if (!PruebaHelper::puede_haber_prueba()) {
            return 0;
        }

        PruebaHelper::olvidar_cache();

        $dueno = PruebaHelper::dueno();

        if (is_null($dueno) || !PruebaHelper::activa($dueno->id)) {
            return 0;
        }

        $this->pedido_de_tienda($dueno->id);
        $this->vencimiento($dueno->id);

        return 0;
    }

    /**
     * @param int $owner_id
     * @return void
     */
    private function pedido_de_tienda($owner_id)
    {
        if (PruebaHito::where('owner_id', $owner_id)->where('tipo', 'prueba.tienda_pedido')->exists()) {
            return;
        }

        $sembrados = SemillaRegistroHelper::tabla_disponible() ? SemillaRegistroHelper::ids($owner_id, 'orders') : [];

        $pedido = Order::where('user_id', $owner_id)
            ->when(count($sembrados), function ($query) use ($sembrados) {
                $query->whereNotIn('id', $sembrados);
            })
            ->orderBy('id')
            ->first();

        if (is_null($pedido)) {
            return;
        }

        PruebaHitosHelper::emitir_ahora('prueba.tienda_pedido', ['id' => (int) $pedido->id], $owner_id);
        $this->info('prueba.tienda_pedido registrado (pedido ' . $pedido->id . ').');
    }

    /**
     * @param int $owner_id
     * @return void
     */
    private function vencimiento($owner_id)
    {
        if (!PruebaHelper::vencida($owner_id)) {
            return;
        }

        if (PruebaHito::where('owner_id', $owner_id)->where('tipo', 'prueba.vencida')->exists()) {
            return;
        }

        $vence_at = PruebaHelper::vence_at($owner_id);

        PruebaHitosHelper::emitir_ahora('prueba.vencida', [
            'vence_at' => is_null($vence_at) ? null : PruebaHelper::iso($vence_at),
        ], $owner_id);
        $this->info('prueba.vencida registrado.');
    }
}
