<?php

namespace Tests\Feature\ChatIa;

use App\Models\Address;
use App\Models\AiMessageAction;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/T163_Lote_conserva_renglones_y_modo_sumar_Test.php';

/**
 * Misión asistente-diario-20261128, ronda 2 (revisiones C y A) — TESTS del ajuste de stock por lote:
 *   1. una tarjeta de lote VENCIDA (más de AiMessageAction::HORAS_VENCIMIENTO) no se hereda: un conteo viejo no
 *      puede volver a la tarjeta nueva y pisar el stock de hoy;
 *   2. una corrección sin depósito con `reemplaza_a` hereda el depósito de la tarjeta reemplazada.
 *
 * Reutiliza las escenas de T163. PHP 7.4.
 *
 * @group chat-ia
 */
class T164_Lote_vencida_y_deposito_heredado_Test extends T163_Lote_conserva_renglones_y_modo_sumar_Test
{
    /**
     * 🔴 La tarjeta abandonada hace 3 días no se hereda: la nueva lleva sólo lo que se manda hoy.
     *
     * @test
     */
    public function una_tarjeta_vencida_no_se_hereda()
    {
        $a = $this->articulo_t163('Repro vencida A', 5);
        $b = $this->articulo_t163('Repro vencida B', 5);
        $c = $this->articulo_t163('Repro vencida C', 5);

        list($conversation, $assistant_1) = $this->conversacion();

        $uno = $this->lote($conversation, $assistant_1, ['renglones' => [
            ['articulo_id' => $a->id, 'cantidad' => 10],
            ['articulo_id' => $b->id, 'cantidad' => 10],
        ]]);

        DB::table('ai_message_actions')->where('id', $uno['tarjeta_id'])->update(['created_at' => now()->subDays(3)]);

        // Entre tanto se vendió: A y B quedan en 2.
        DB::table('articles')->whereIn('id', [$a->id, $b->id])->update(['stock' => 2]);

        $assistant_2 = $this->siguiente_mensaje($conversation, $assistant_1, 'Ajustá C a 7');

        $dos = $this->lote($conversation, $assistant_2, ['renglones' => [['articulo_id' => $c->id, 'cantidad' => 7]]]);
        $this->assertTrue($dos['ok'], json_encode($dos));

        $this->assertSame([(int) $c->id => 7.0], $this->esperados_de($dos['tarjeta_id']), 'El conteo vencido no vuelve.');
        $this->assertSame([], $dos['heredados']);

        $this->confirmar($conversation, $assistant_2, $dos['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(2, $this->stock_total($a->id), self::DELTA);
        $this->assertEqualsWithDelta(2, $this->stock_total($b->id), self::DELTA);
    }

    /**
     * 🔴 Con dos depósitos, la corrección que no repite el depósito (y trae `reemplaza_a`) sigue en el de la tarjeta
     * reemplazada y conserva sus renglones.
     *
     * @test
     */
    public function la_correccion_sin_deposito_hereda_el_de_la_tarjeta_reemplazada()
    {
        $centro = Address::create(['street' => 'Centro T164', 'user_id' => $this->dueno->id]);
        Address::create(['street' => 'Galpon T164', 'user_id' => $this->dueno->id]);

        $a = $this->articulo_t163('Deposito heredado A', 0);
        $b = $this->articulo_t163('Deposito heredado B', 0);

        list($conversation, $assistant_1) = $this->conversacion();

        $uno = $this->lote($conversation, $assistant_1, [
            'deposito'  => 'Centro T164',
            'renglones' => [['articulo_id' => $a->id, 'cantidad' => 4], ['articulo_id' => $b->id, 'cantidad' => 4]],
        ]);
        $this->assertTrue($uno['ok'], json_encode($uno));

        $assistant_2 = $this->siguiente_mensaje($conversation, $assistant_1, 'El B son 6');

        $dos = $this->lote($conversation, $assistant_2, [
            'renglones'   => [['articulo_id' => $b->id, 'cantidad' => 6]],
            'reemplaza_a' => $uno['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($dos['ok']), 'No tendría que volver a preguntar el depósito: ' . json_encode($dos, JSON_UNESCAPED_UNICODE));
        $this->assertSame([(int) $a->id => 4.0, (int) $b->id => 6.0], $this->esperados_de($dos['tarjeta_id']));
        $this->assertSame((int) $centro->id, (int) AiMessageAction::find($dos['tarjeta_id'])->datos['esperados'][0]['address_id']);
    }
}
