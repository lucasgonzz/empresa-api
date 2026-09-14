<?php

namespace Tests\Feature\Alertas;

use App\Models\Provider;
use App\Models\ProviderOrder;
use Carbon\Carbon;
use Tests\EmpresaTestCase;

/**
 * Exploración del módulo Alertas — Pieza 3: el endpoint de "Pedidos Proveedor"
 * (`GET api/provider-order/days-to-advise/not-received`), que hasta hoy no tenía ni un test.
 *
 * Lo que este archivo clava:
 *
 * 1. Un pedido EN PROCESO cuyo aviso ya venció (creado hace más días que su `days_to_advise`)
 *    aparece; el mismo pedido RECIBIDO desaparece; un pedido sin `days_to_advise` (o en 0)
 *    nunca aparece. Es la promesa de la pestaña: "avisame si a los N días no llegó".
 *
 * 2. 🔴 EL DÍA EXACTO NO ALERTA — comportamiento REAL medido el 3/9/2026, fijado a propósito.
 *    La condición del controller compara `created_at->addDays(N)` (un TIMESTAMP, con hora)
 *    contra `Carbon::today()` (la MEDIANOCHE de hoy): un pedido de hace exactamente N días,
 *    creado a las 15:00, da `hoy 15:00 <= hoy 00:00` = false, y recién alerta MAÑANA. O sea que
 *    el aviso pactado "a los N días" llega sistemáticamente al día N+1 (salvo pedidos creados
 *    justo a la medianoche). Si algún día se decide que el día N tiene que alertar (comparar
 *    fechas y no timestamps: `->startOfDay()` antes del `lte`, o un `whereDate`), este test se
 *    pone en ROJO — esa es la señal buscada, y entonces hay que invertir la aserción del caso
 *    (b) y avisar en la pestaña del cambio de criterio.
 *
 * PHP 7.4 (nada de `?->`, `match` ni `str_contains`). Todas las aserciones filtran por los
 * pedidos que crea este test: la base del slot arrastra pedidos de otras corridas.
 */
class Pedidos_proveedor_dias_de_aviso_Test extends EmpresaTestCase
{
    /** Id del estado "En proceso" de un pedido a proveedor. */
    const STATUS_EN_PROCESO = 1;

    /** Id del estado "Recibido". */
    const STATUS_RECIBIDO = 2;

    /**
     * Crea un pedido al proveedor del fixture con la antigüedad y el aviso pedidos.
     *
     * @param int      $hace_dias       Antigüedad del pedido en días (misma hora que ahora).
     * @param int|null $days_to_advise  Días de aviso; null = sin aviso configurado.
     * @param int      $status_id       Estado del pedido.
     * @return ProviderOrder
     */
    protected function pedido($hace_dias, $days_to_advise, $status_id = self::STATUS_EN_PROCESO)
    {
        $proveedor = Provider::where('name', 'Buenos Aires')->first();

        $this->assertNotNull($proveedor, 'el fixture tenía que tener al proveedor "Buenos Aires"');

        $creado = Carbon::now()->subDays($hace_dias);

        $pedido = ProviderOrder::create([
            'num'                      => 90000 + rand(1000, 9999),
            'provider_id'              => $proveedor->id,
            'provider_order_status_id' => $status_id,
            'days_to_advise'           => $days_to_advise,
            'user_id'                  => $this->user_id(),
        ]);

        // created_at explícito y por separado: create() lo pisa con now() si viaja en el array.
        $pedido->created_at = $creado;
        $pedido->updated_at = $creado;
        $pedido->timestamps = false;
        $pedido->save();

        return $pedido;
    }

    /**
     * Ids que devuelve el endpoint, para afirmar pertenencia sin depender del resto de la base.
     *
     * @return array
     */
    protected function ids_alertados()
    {
        $respuesta = $this->getJson('api/provider-order/days-to-advise/not-received');

        $respuesta->assertStatus(200);

        $ids = [];

        foreach ($respuesta->json('models') as $model) {
            $ids[] = (int) $model['id'];
        }

        return $ids;
    }

    /**
     * Id del usuario del fixture (el autenticado por EmpresaTestCase).
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) auth()->user()->id;
    }

    /**
     * @test
     * @return void
     */
    public function el_pedido_vencido_alerta_y_el_recibido_o_sin_aviso_no()
    {
        // (a) Vencido: hace 3 días, aviso a los 2 -> tiene que estar.
        $vencido = $this->pedido(3, 2);

        // (c) Recibido: mismo vencimiento, pero ya llegó -> no tiene que estar.
        $recibido = $this->pedido(3, 2, self::STATUS_RECIBIDO);

        // (d) Sin aviso configurado, y aviso en 0: nunca alertan, por viejos que sean.
        $sin_aviso = $this->pedido(30, null);
        $aviso_cero = $this->pedido(30, 0);

        $ids = $this->ids_alertados();

        $this->assertContains((int) $vencido->id, $ids, 'el pedido con el aviso vencido tenía que alertar');
        $this->assertNotContains((int) $recibido->id, $ids, 'un pedido RECIBIDO no puede alertar aunque el plazo haya pasado');
        $this->assertNotContains((int) $sin_aviso->id, $ids, 'sin days_to_advise no hay alerta');
        $this->assertNotContains((int) $aviso_cero->id, $ids, 'days_to_advise = 0 significa "sin aviso", no "avisar siempre"');
    }

    /**
     * @test
     * @return void
     */
    public function el_dia_exacto_del_aviso_todavia_no_alerta_y_el_dia_siguiente_si()
    {
        // (b) El caso borde del comportamiento real (ver el docblock de la clase): un pedido de
        //     hace EXACTAMENTE 2 días con aviso a los 2 días NO aparece hoy...
        $del_dia_exacto = $this->pedido(2, 2);

        // ...y el mismo escenario corrido un día (hace 3, aviso 2) sí — ya cubierto en el otro
        // test, repetido acá para que este archivo se lea solo.
        $del_dia_siguiente = $this->pedido(3, 2);

        $ids = $this->ids_alertados();

        $this->assertNotContains(
            (int) $del_dia_exacto->id,
            $ids,
            'COMPORTAMIENTO REAL FIJADO: el día N exacto no alerta (la comparación es contra la '
            . 'medianoche de hoy). Si este rojo aparece porque se corrigió el criterio al día N, '
            . 'invertir esta aserción y avisar el cambio en la pestaña.'
        );

        $this->assertContains((int) $del_dia_siguiente->id, $ids, 'al día N+1 el aviso tiene que estar');
    }
}
