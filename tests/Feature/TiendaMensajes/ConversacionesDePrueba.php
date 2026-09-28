<?php

namespace Tests\Feature\TiendaMensajes;

use App\Events\TiendaChatActualizado;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Buyer\ComercioDePrueba;

/**
 * Andamiaje de los tests del submódulo "Mensajes" de Tienda Online (misión mensajes-tienda-online,
 * 28/9/2026): dueños, empleados y compradores (de `ComercioDePrueba`), más mensajes de cada lado,
 * los eventos C1 emitidos y el log de consultas de un pedido.
 *
 * 🔴 Cada test crea SUS dueños: la base del slot arrastra compradores y mensajes de otras suites, y
 * las aserciones son por conjunto exacto ("estos y solo estos"). `DatabaseTransactions` (de
 * `EmpresaTestCase`) deshace todo al terminar.
 *
 * 🔴 Un solo POST por test cuando el endpoint despacha algo con `dispatchAfterResponse()`: en
 * Laravel 8 `Application::terminate()` no vacía los callbacks después de correrlos, así que en un
 * mismo test el segundo request vuelve a correr los del primero. En producción cada request es un
 * proceso nuevo y eso no pasa; acá contaría notificaciones de más.
 *
 * Requiere `Tests\EmpresaTestCase`. PHP 7.4.
 */
trait ConversacionesDePrueba
{
    use ComercioDePrueba;

    /**
     * Un mensaje que escribió el comprador desde la tienda (así lo guarda tienda-api: `user_id` =
     * dueño del comercio, `from_buyer = 1`), sin leer salvo que se pise.
     *
     * @param  \App\Models\Buyer  $comprador
     * @param  array  $atributos  `text`, `read`, `created_at`, `article_id`...
     * @return \App\Models\Message
     */
    protected function mensaje_del_comprador($comprador, $atributos = [])
    {
        return $this->crear_mensaje($comprador, array_merge([
            'text'       => 'Hola, ¿tienen talle 42?',
            'from_buyer' => 1,
            'read'       => 0,
        ], $atributos));
    }

    /**
     * Un mensaje del comercio al comprador (`from_buyer = 0`), sin leer por el comprador salvo que
     * se pise.
     *
     * @param  \App\Models\Buyer  $comprador
     * @param  array  $atributos
     * @return \App\Models\Message
     */
    protected function mensaje_del_comercio($comprador, $atributos = [])
    {
        return $this->crear_mensaje($comprador, array_merge([
            'text'       => 'Sí, nos queda uno.',
            'from_buyer' => 0,
            'read'       => 0,
        ], $atributos));
    }

    /**
     * @param  \App\Models\Buyer  $comprador
     * @param  array  $atributos
     * @return \App\Models\Message
     */
    protected function crear_mensaje($comprador, $atributos)
    {
        // Si el test fija `created_at`, `updated_at` lo acompaña (si no, Eloquent pone "ahora").
        if (isset($atributos['created_at']) && !isset($atributos['updated_at'])) {
            $atributos['updated_at'] = $atributos['created_at'];
        }

        return Message::create(array_merge([
            'buyer_id' => $comprador->id,
            'user_id'  => $comprador->user_id,
        ], $atributos));
    }

    /**
     * Los `TiendaChatActualizado` que se emitieron (requiere `Event::fake([TiendaChatActualizado::class])`).
     *
     * @return \App\Events\TiendaChatActualizado[]
     */
    protected function eventos_emitidos()
    {
        return Event::dispatched(TiendaChatActualizado::class)
                    ->map(function ($argumentos) {
                        return $argumentos[0];
                    })
                    ->values()
                    ->all();
    }

    /**
     * Corre `$pedido` con el log de consultas prendido y devuelve las consultas que costó.
     *
     * @param  callable  $pedido
     * @return array  Cada una con `query` y `bindings`.
     */
    protected function consultas_de(callable $pedido)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $pedido();

        $consultas = DB::getQueryLog();

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $consultas;
    }
}
