<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261128, P4 (CA5 a CA9) — TESTS DE ACEPTACIÓN: el ajuste de stock por lote no pierde
 * renglones cuando se corrige, dice lo que quedó afuera y entiende «sumá».
 *
 * EL CASO REAL (conv. 31): un lote de 20 artículos se reemplazó por la corrección de 3 bobinas (el reemplazo por
 * clave no hace merge), hubo dos tarjetas de lote en el mismo mensaje, el modelo «sumó a mano» (fijó actual+N) y se
 * confirmaron 18: los 3 faltantes nunca estuvieron en la tarjeta confirmada y el resultado no los nombraba.
 *
 * Qué fija este archivo:
 *   CA5  una corrección que manda sólo lo que cambia CONSERVA los demás renglones (con `reemplaza_a` y sin él) y la
 *        respuesta trae `heredados`;
 *   CA6  dos llamadas en el MISMO mensaje quedan en una sola tarjeta;
 *   CA7  `quitar: true` saca un renglón heredado;
 *   CA8  lo que quedó afuera se guarda en la tarjeta y el resultado del «sí» lo nombra (y las correcciones lo arrastran);
 *   CA9  `modo: sumar` recalcula contra el stock del momento de confirmar y se hereda en las correcciones.
 *
 * 🔴 Estado esperado en el commit de tests: TODOS ROJOS. T160 (el lote de siempre, en otro archivo) es la guarda
 * de regresión y ya pasa.
 *
 * Molde: T160 (`lote()`, `confirmar()`). Sin red. PHP 7.4: sin match, str_contains, ?->, argumentos nombrados.
 *
 * @group chat-ia
 */
class T163_Lote_conserva_renglones_y_modo_sumar_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    const DELTA = 0.01;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();
    }

    /** Un artículo del negocio del test con su stock total (sin depósitos). */
    protected function articulo_t163($nombre, $stock)
    {
        $articulo = Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 1000,
            'cost'        => 500,
            'iva_id'      => 2,
        ]);

        DB::table('articles')->where('id', $articulo->id)->update(['stock' => $stock]);

        return $articulo->fresh();
    }

    /** Una conversación del dueño con su primer assistant 'pendiente'. */
    protected function conversacion($pedido = 'Te paso el conteo, ajustá el stock')
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);

        return [$conversation, $this->assistant_pendiente($conversation)];
    }

    /** Un assistant 'pendiente' nuevo en la conversación (un mensaje más del modelo). */
    protected function assistant_pendiente($conversation)
    {
        return AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);
    }

    /**
     * Cierra el mensaje del assistant que propuso y abre el siguiente turno: la persona escribe y el modelo
     * contesta en OTRO mensaje (así pasa la corrección en la vida real).
     *
     * @return \App\Models\AiMessage El assistant nuevo.
     */
    protected function siguiente_mensaje($conversation, AiMessage $anterior, $texto = 'Corregí esto')
    {
        $anterior->contenido = 'Te dejé la tarjeta.';
        $anterior->estado = 'listo';
        $anterior->save();

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $texto, 'estado' => 'listo']);

        return $this->assistant_pendiente($conversation);
    }

    /** La herramienta por el despacho real. */
    protected function lote($conversation, $assistant, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar('proponer_ajuste_de_stock_por_lote', $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'Falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /** Confirma la tarjeta por el endpoint real. */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    protected function stock_total($article_id)
    {
        return (float) DB::table('articles')->where('id', $article_id)->value('stock');
    }

    /** Los (article_id => cantidad) de los esperados de una tarjeta, en el orden en que están guardados. */
    protected function esperados_de($tarjeta_id)
    {
        $esperados = [];

        foreach (AiMessageAction::find($tarjeta_id)->datos['esperados'] as $esperado) {
            $esperados[(int) $esperado['article_id']] = (float) $esperado['final'];
        }

        return $esperados;
    }

    /**
     * 🔴 CA5 — Tres artículos en 10, y en OTRO mensaje la persona corrige sólo uno (B→12): la tarjeta nueva
     * conserva A y C, en el orden de la anterior, y la respuesta dice cuáles heredó. Pasa lo mismo con
     * `reemplaza_a` y sin él (reemplazo por clave). Al confirmar quedan A 10, B 12, C 10.
     *
     * @test
     */
    public function la_correccion_conserva_los_renglones_que_no_se_reenvian()
    {
        foreach ([true, false] as $con_reemplaza_a) {

            $sufijo = $con_reemplaza_a ? ' con reemplaza_a' : ' por clave';

            $a = $this->articulo_t163('Tornillo T163 A' . $sufijo, 5);
            $b = $this->articulo_t163('Tornillo T163 B' . $sufijo, 5);
            $c = $this->articulo_t163('Tornillo T163 C' . $sufijo, 5);

            list($conversation, $assistant_1) = $this->conversacion();

            $uno = $this->lote($conversation, $assistant_1, ['renglones' => [
                ['articulo_id' => $a->id, 'cantidad' => 10],
                ['articulo_id' => $b->id, 'cantidad' => 10],
                ['articulo_id' => $c->id, 'cantidad' => 10],
            ]]);
            $this->assertTrue($uno['ok'], json_encode($uno));

            $assistant_2 = $this->siguiente_mensaje($conversation, $assistant_1, 'El B son 12');

            $entrada = ['renglones' => [['articulo_id' => $b->id, 'cantidad' => 12]]];

            if ($con_reemplaza_a) {
                $entrada['reemplaza_a'] = $uno['tarjeta_id'];
            }

            $dos = $this->lote($conversation, $assistant_2, $entrada);
            $this->assertTrue($dos['ok'], json_encode($dos));

            $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, AiMessageAction::find($uno['tarjeta_id'])->estado);

            // La tarjeta 2 lleva los tres, en el orden de la anterior, con B corregido.
            $this->assertSame(
                [(int) $a->id => 10.0, (int) $b->id => 12.0, (int) $c->id => 10.0],
                $this->esperados_de($dos['tarjeta_id']),
                'La corrección tiene que conservar A y C' . $sufijo . '.'
            );
            $this->assertSame(
                [(int) $a->id, (int) $b->id, (int) $c->id],
                array_keys($this->esperados_de($dos['tarjeta_id'])),
                'El orden es el de la tarjeta anterior.'
            );

            $this->assertArrayHasKey('heredados', $dos, 'La respuesta tiene que decir qué renglones heredó.');
            $this->assertSame([$a->name, $c->name], $dos['heredados']);

            $this->confirmar($conversation, $assistant_2, $dos['tarjeta_id'])->assertStatus(200);

            $this->assertEqualsWithDelta(10, $this->stock_total($a->id), self::DELTA);
            $this->assertEqualsWithDelta(12, $this->stock_total($b->id), self::DELTA);
            $this->assertEqualsWithDelta(10, $this->stock_total($c->id), self::DELTA);

            $resultado = json_decode(json_encode(AiMessageAction::find($dos['tarjeta_id'])->resultado), true);
            $this->assertSame('Ajusté el stock de 3 artículos.', $resultado['texto']);
        }
    }

    /**
     * 🔴 CA6 — Dos llamadas en el MISMO mensaje (A y B, después C) dejan UNA sola tarjeta propuesta con los
     * tres; al confirmar los tres quedan en 7.
     *
     * @test
     */
    public function dos_llamadas_del_mismo_mensaje_quedan_en_una_tarjeta()
    {
        $a = $this->articulo_t163('Clavo T163 A', 5);
        $b = $this->articulo_t163('Clavo T163 B', 5);
        $c = $this->articulo_t163('Clavo T163 C', 5);

        list($conversation, $assistant) = $this->conversacion();

        $uno = $this->lote($conversation, $assistant, ['renglones' => [
            ['articulo_id' => $a->id, 'cantidad' => 7],
            ['articulo_id' => $b->id, 'cantidad' => 7],
        ]]);
        $dos = $this->lote($conversation, $assistant, ['renglones' => [['articulo_id' => $c->id, 'cantidad' => 7]]]);

        $this->assertTrue($uno['ok'] && $dos['ok'], json_encode([$uno, $dos]));

        $vigentes = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->get();
        $this->assertCount(1, $vigentes, 'Queda una sola tarjeta propuesta.');
        $this->assertSame((int) $dos['tarjeta_id'], (int) $vigentes[0]->id);

        $this->assertSame(
            [(int) $a->id => 7.0, (int) $b->id => 7.0, (int) $c->id => 7.0],
            $this->esperados_de($dos['tarjeta_id'])
        );

        $this->confirmar($conversation, $assistant, $dos['tarjeta_id'])->assertStatus(200);

        foreach ([$a, $b, $c] as $articulo) {
            $this->assertEqualsWithDelta(7, $this->stock_total($articulo->id), self::DELTA);
        }
    }

    /**
     * 🔴 CA7 — Sobre la tarjeta de A, B y C, la corrección `{articulo: B, quitar: true}` saca B: la tarjeta nueva
     * tiene A y C y, al confirmar, B queda como estaba (5).
     *
     * @test
     */
    public function quitar_saca_un_renglon_heredado()
    {
        $a = $this->articulo_t163('Tarugo T163 A', 5);
        $b = $this->articulo_t163('Tarugo T163 B', 5);
        $c = $this->articulo_t163('Tarugo T163 C', 5);

        list($conversation, $assistant_1) = $this->conversacion();

        $uno = $this->lote($conversation, $assistant_1, ['renglones' => [
            ['articulo_id' => $a->id, 'cantidad' => 10],
            ['articulo_id' => $b->id, 'cantidad' => 10],
            ['articulo_id' => $c->id, 'cantidad' => 10],
        ]]);
        $this->assertTrue($uno['ok'], json_encode($uno));

        $assistant_2 = $this->siguiente_mensaje($conversation, $assistant_1, 'El B no va');

        $dos = $this->lote($conversation, $assistant_2, [
            'renglones'   => [['articulo' => $b->name, 'quitar' => true]],
            'reemplaza_a' => $uno['tarjeta_id'],
        ]);

        $this->assertTrue($dos['ok'], json_encode($dos));
        $this->assertSame([(int) $a->id => 10.0, (int) $c->id => 10.0], $this->esperados_de($dos['tarjeta_id']));

        $this->confirmar($conversation, $assistant_2, $dos['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(10, $this->stock_total($a->id), self::DELTA);
        $this->assertEqualsWithDelta(5, $this->stock_total($b->id), self::DELTA, 'B se sacó de la tarjeta: queda como estaba.');
        $this->assertEqualsWithDelta(10, $this->stock_total($c->id), self::DELTA);
    }

    /**
     * 🔴 CA8 — Lo que no se pudo ubicar queda guardado en la tarjeta (`datos.quedaron_afuera`) y el texto del
     * resultado del «sí» lo nombra, aunque el modelo no lo cuente. Una corrección posterior que no nombra la rana
     * la sigue listando.
     *
     * @test
     */
    public function lo_que_quedo_afuera_lo_dice_el_resultado()
    {
        $a = $this->articulo_t163('Broca T163 A', 5);

        list($conversation, $assistant_1) = $this->conversacion();

        $uno = $this->lote($conversation, $assistant_1, ['renglones' => [
            ['articulo_id' => $a->id, 'cantidad' => 9],
            ['articulo' => 'Rana amarilla inexistente', 'cantidad' => 3],
        ]]);
        $this->assertTrue($uno['ok'], json_encode($uno));

        $datos = AiMessageAction::find($uno['tarjeta_id'])->datos;
        $this->assertArrayHasKey('quedaron_afuera', $datos, 'La tarjeta tiene que guardar lo que quedó afuera.');
        $this->assertSame('Rana amarilla inexistente', $datos['quedaron_afuera'][0]['articulo']);

        // Una corrección posterior que no nombra la rana la sigue listando.
        $assistant_2 = $this->siguiente_mensaje($conversation, $assistant_1, 'El A son 11');

        $dos = $this->lote($conversation, $assistant_2, ['renglones' => [['articulo_id' => $a->id, 'cantidad' => 11]]]);
        $this->assertTrue($dos['ok'], json_encode($dos));

        $datos_2 = AiMessageAction::find($dos['tarjeta_id'])->datos;
        $this->assertArrayHasKey('quedaron_afuera', $datos_2);
        $this->assertSame('Rana amarilla inexistente', $datos_2['quedaron_afuera'][0]['articulo']);

        $this->confirmar($conversation, $assistant_2, $dos['tarjeta_id'])->assertStatus(200);

        $texto = json_decode(json_encode(AiMessageAction::find($dos['tarjeta_id'])->resultado), true)['texto'];

        $this->assertStringStartsWith(
            'Ajusté el stock de 1 artículo. Quedaron afuera de la tarjeta ' . $dos['tarjeta_id'] . ': Rana amarilla inexistente (',
            $texto
        );
        $this->assertStringEndsWith(').', $texto);
    }

    /**
     * 🔴 CA9 — `modo: sumar`: la tarjeta muestra «se suma al stock» y «4 → 6 (suma 2)»; al confirmar recalcula
     * contra el stock de ese momento (3 + 2 = 5). Una corrección sin `modo` que agrega otro artículo hereda
     * `sumar`. La cantidad 0 en modo sumar va afuera con su motivo. Sin `modo` y sin herencia sigue fijando.
     *
     * @test
     */
    public function modo_sumar_se_recalcula_al_confirmar_y_se_hereda()
    {
        // (1) Se arma con sumar y se recalcula al confirmar.
        $a = $this->articulo_t163('Bobina T163 A', 4);

        list($conversation, $assistant) = $this->conversacion('Sumá 2 bobinas al stock');

        $respuesta = $this->lote($conversation, $assistant, ['modo' => 'sumar', 'renglones' => [['articulo_id' => $a->id, 'cantidad' => 2]]]);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $esperado = $tarjeta->datos['esperados'][0];

        $this->assertSame('sumar', $esperado['modo']);
        $this->assertEqualsWithDelta(2, (float) $esperado['cantidad'], self::DELTA);
        $this->assertEqualsWithDelta(6, (float) $esperado['final'], self::DELTA);

        $valores = [];
        foreach ($tarjeta->presentacion['renglones'] as $renglon) {
            $valores[$renglon['etiqueta']] = $renglon['valor'];
        }
        $this->assertSame('1 (se suma al stock)', $valores['Artículos']);
        $this->assertSame('4 → 6 (suma 2)', $valores[$a->name]);

        // Entre la tarjeta y el sí el stock baja a 3: sumar 2 deja 5, no 6.
        DB::table('articles')->where('id', $a->id)->update(['stock' => 3]);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);
        $this->assertEqualsWithDelta(5, $this->stock_total($a->id), self::DELTA, 'Sumar se recalcula sobre el stock del momento de confirmar.');

        // (2) Una corrección sin `modo` que agrega B hereda «sumar»; la cantidad 0 va afuera.
        $c = $this->articulo_t163('Bobina T163 C', 4);
        $b = $this->articulo_t163('Bobina T163 B', 1);
        $d = $this->articulo_t163('Bobina T163 D', 1);

        list($conversation_2, $assistant_1) = $this->conversacion('Sumá 2 al C');

        $uno = $this->lote($conversation_2, $assistant_1, ['modo' => 'sumar', 'renglones' => [['articulo_id' => $c->id, 'cantidad' => 2]]]);
        $this->assertTrue($uno['ok'], json_encode($uno));

        $assistant_2 = $this->siguiente_mensaje($conversation_2, $assistant_1, 'Y uno más del B');

        $dos = $this->lote($conversation_2, $assistant_2, ['renglones' => [
            ['articulo_id' => $b->id, 'cantidad' => 1],
            ['articulo_id' => $d->id, 'cantidad' => 0],
        ]]);
        $this->assertTrue($dos['ok'], json_encode($dos));

        $datos = AiMessageAction::find($dos['tarjeta_id'])->datos;
        $this->assertCount(2, $datos['esperados'], 'C heredado y B nuevo.');
        $this->assertSame('sumar', $datos['esperados'][0]['modo']);
        $this->assertSame('sumar', $datos['esperados'][1]['modo'], 'Sin `modo` en la corrección, hereda «sumar».');

        $this->assertCount(1, $respuesta_afuera = $dos['quedaron_afuera'], 'La cantidad 0 en modo sumar va afuera.');
        $this->assertStringContainsString('para sumar, la cantidad tiene que ser mayor que 0', $respuesta_afuera[0]['motivo']);

        $this->confirmar($conversation_2, $assistant_2, $dos['tarjeta_id'])->assertStatus(200);
        $this->assertEqualsWithDelta(6, $this->stock_total($c->id), self::DELTA, 'C: 4 + 2.');
        $this->assertEqualsWithDelta(2, $this->stock_total($b->id), self::DELTA, 'B: 1 + 1 (heredó sumar).');
        $this->assertEqualsWithDelta(1, $this->stock_total($d->id), self::DELTA, 'D quedó afuera: no se toca.');

        // (3) Guarda de regresión (verde hoy): sin `modo` y sin herencia, el lote fija la cantidad contada.
        $e = $this->articulo_t163('Bobina T163 E', 4);

        list($conversation_3, $assistant_3) = $this->conversacion('Conté 9');

        $fijo = $this->lote($conversation_3, $assistant_3, ['renglones' => [['articulo_id' => $e->id, 'cantidad' => 9]]]);
        $this->assertTrue($fijo['ok'], json_encode($fijo));

        $this->confirmar($conversation_3, $assistant_3, $fijo['tarjeta_id'])->assertStatus(200);
        $this->assertEqualsWithDelta(9, $this->stock_total($e->id), self::DELTA);
    }
}
