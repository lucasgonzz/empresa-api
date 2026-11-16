<?php

namespace Tests\Feature\ChatIa;

use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116, rama B — 30/10 #2: ajuste de stock por lote.
 *
 * EL CASO REAL (Ferre Total, 30/10/2026, conv 30): la foto de un conteo («necesito hacer ajuste de
 * stock») terminó en error sin que se ajustara nada. Lo que protege este archivo:
 *
 *  - UNA llamada con todos los renglones arma UNA tarjeta con lo que se pudo ubicar; lo que no, va en
 *    `quedaron_afuera` (con candidatos si encaja con varios) y no frena al resto;
 *  - al confirmar, cada renglón queda en la cantidad CONTADA por el mismo camino que
 *    proponer_stock_en_deposito: sin depósitos, el total con su movimiento de stock; con depósitos, el
 *    pivot de ESE depósito (y el otro depósito no se toca);
 *  - un renglón que ya no se puede hacer al confirmar va a `no_hechos` con el motivo y el resto sale;
 *  - la herramienta está registrada y NUNCA se auto-confirma, ni en "directo".
 *
 * Ningún test sale a la red. PHP 7.4: sin match, str_contains, ?->, argumentos nombrados.
 *
 * @group chat-ia
 */
class T160_Ajuste_de_stock_por_lote_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    const DELTA = 0.01;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();
    }

    /** Un artículo del negocio del test con su stock total. */
    protected function articulo_t160($nombre, $stock)
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

    /** Una conversación del dueño con su assistant 'pendiente'. */
    protected function conversacion($pedido = 'Te paso el conteo, ajustá el stock')
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
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

    protected function pivot($article_id, $address_id)
    {
        $fila = DB::table('address_article')->where('article_id', $article_id)->where('address_id', $address_id)->first(['amount']);

        return is_null($fila) ? null : (float) $fila->amount;
    }

    /**
     * El caso del conteo sin depósitos: una tarjeta, lo dudoso afuera, cada uno queda en lo contado.
     *
     * @test
     */
    public function sin_depositos_una_sola_tarjeta_deja_cada_articulo_en_lo_contado()
    {
        $tornillo = $this->articulo_t160('Tornillo T160 aguja 8', 5);
        $tarugo = $this->articulo_t160('Tarugo T160 fischer 6', 12);
        $clavo = $this->articulo_t160('Clavo T160 punta paris', 10);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->lote($conversation, $assistant, ['renglones' => [
            ['articulo' => 'Tornillo T160 aguja 8', 'cantidad' => 8],
            ['articulo' => 'Tarugo T160 fischer 6', 'cantidad' => 0],
            ['articulo' => 'Clavo T160 punta paris', 'cantidad' => 10],
            ['articulo' => 'Mecha T160 inexistente', 'cantidad' => 3],
        ]]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_AJUSTE_STOCK_LOTE, $respuesta['tipo']);
        $this->assertCount(1, $respuesta['quedaron_afuera'], 'La mecha no existe: va afuera, sin frenar al resto.');
        $this->assertSame('Mecha T160 inexistente', $respuesta['quedaron_afuera'][0]['articulo']);
        $this->assertCount(1, $respuesta['sin_cambios'], 'El clavo ya estaba en 10.');

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertCount(2, $accion->datos['esperados'], 'Una sola tarjeta con los dos que cambian.');
        $this->assertSame(1, AiMessageAction::where('ai_message_id', $assistant->id)->count(), 'Una sola tarjeta para el lote.');

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(8, $this->stock_total($tornillo->id), self::DELTA);
        $this->assertEqualsWithDelta(0, $this->stock_total($tarugo->id), self::DELTA);
        $this->assertEqualsWithDelta(10, $this->stock_total($clavo->id), self::DELTA);

        // Por el camino del movimiento de stock, no escribiendo la columna a mano.
        $this->assertTrue(DB::table('stock_movements')->where('article_id', $tornillo->id)->exists(), 'El ajuste deja su movimiento de stock.');

        $resultado = json_decode(json_encode(AiMessageAction::find($respuesta['tarjeta_id'])->resultado), true);
        $this->assertCount(2, $resultado['hechos']);
        $this->assertCount(0, $resultado['no_hechos']);
    }

    /**
     * Con dos depósitos, el lote va al depósito nombrado (abriéndolo si hace falta) y el otro no se toca.
     *
     * @test
     */
    public function con_depositos_ajusta_el_deposito_nombrado_y_no_toca_el_otro()
    {
        $centro = Address::create(['street' => 'Centro T160', 'user_id' => $this->dueno->id]);
        $galpon = Address::create(['street' => 'Galpon T160', 'user_id' => $this->dueno->id]);

        $codo = $this->articulo_t160('Codo T160 pvc 40', null);
        $codo->addresses()->syncWithoutDetaching([$centro->id => ['amount' => 4], $galpon->id => ['amount' => 7]]);

        $cupla = $this->articulo_t160('Cupla T160 pvc 40', null);
        $cupla->addresses()->syncWithoutDetaching([$galpon->id => ['amount' => 3]]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->lote($conversation, $assistant, [
            'deposito'  => 'Centro T160',
            'renglones' => [
                ['articulo' => 'Codo T160 pvc 40', 'cantidad' => 9],
                ['articulo_id' => $cupla->id, 'cantidad' => 2],
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertCount(0, $respuesta['quedaron_afuera']);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(9, $this->pivot($codo->id, $centro->id), self::DELTA);
        $this->assertEqualsWithDelta(7, $this->pivot($codo->id, $galpon->id), self::DELTA, 'El otro depósito no se toca.');
        $this->assertEqualsWithDelta(2, $this->pivot($cupla->id, $centro->id), self::DELTA, 'Se le abre el depósito.');
        $this->assertEqualsWithDelta(3, $this->pivot($cupla->id, $galpon->id), self::DELTA);
    }

    /**
     * Con varios depósitos y sin decir cuál (ni uno por defecto), se pregunta UNA vez para todo el lote.
     *
     * @test
     */
    public function con_depositos_y_sin_decir_cual_pregunta_una_vez()
    {
        Address::create(['street' => 'Norte T160', 'user_id' => $this->dueno->id]);
        Address::create(['street' => 'Sur T160', 'user_id' => $this->dueno->id]);

        $this->articulo_t160('Llave T160 francesa', 1);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->lote($conversation, $assistant, ['renglones' => [['articulo' => 'Llave T160 francesa', 'cantidad' => 4]]]);

        $this->assertFalse($respuesta['ok']);
        $this->assertNotEmpty($respuesta['faltan']);
        $this->assertCount(2, $respuesta['opciones']['depositos']);
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * Un nombre que encaja con varios va afuera CON los candidatos, y el resto arma la tarjeta.
     *
     * @test
     */
    public function un_nombre_ambiguo_queda_afuera_con_los_candidatos()
    {
        $this->articulo_t160('Pinza T160 universal 7', 2);
        $this->articulo_t160('Pinza T160 universal 8', 2);
        $this->articulo_t160('Martillo T160 galponero', 1);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->lote($conversation, $assistant, ['renglones' => [
            ['articulo' => 'Pinza T160 universal', 'cantidad' => 5],
            ['articulo' => 'Martillo T160 galponero', 'cantidad' => 3],
        ]]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertCount(1, $respuesta['quedaron_afuera']);
        $this->assertCount(2, $respuesta['quedaron_afuera'][0]['opciones'], 'Vuelven los dos candidatos para preguntar.');
        $this->assertCount(1, AiMessageAction::find($respuesta['tarjeta_id'])->datos['esperados']);
    }

    /**
     * Lo que no se pudo hacer al confirmar va a no_hechos con el motivo, y el resto se hace igual.
     *
     * @test
     */
    public function un_renglon_que_falla_al_confirmar_va_a_no_hechos_y_el_resto_sale()
    {
        $sierra = $this->articulo_t160('Sierra T160 copa 32', 1);
        $broca = $this->articulo_t160('Broca T160 widia 10', 1);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->lote($conversation, $assistant, ['renglones' => [
            ['articulo' => 'Sierra T160 copa 32', 'cantidad' => 6],
            ['articulo' => 'Broca T160 widia 10', 'cantidad' => 4],
        ]]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        // Entre la tarjeta y el sí, la broca se borra.
        $broca->delete();

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(6, $this->stock_total($sierra->id), self::DELTA);

        $resultado = json_decode(json_encode(AiMessageAction::find($respuesta['tarjeta_id'])->resultado), true);
        $this->assertCount(1, $resultado['hechos']);
        $this->assertCount(1, $resultado['no_hechos']);
        $this->assertNotEmpty($resultado['no_hechos'][0]['motivo']);
        $this->assertStringContainsString('NO se ajustaron 1', $resultado['texto']);
    }

    /**
     * La herramienta está registrada y no se auto-confirma ni en "directo".
     *
     * @test
     */
    public function esta_registrada_y_nunca_se_auto_confirma()
    {
        $this->assertContains('proponer_ajuste_de_stock_por_lote', HerramientasDeCarga::nombres());
        $this->assertNotContains(AiMessageAction::TIPO_AJUSTE_STOCK_LOTE, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);
        $this->assertNotContains(AiMessageAction::TIPO_AJUSTE_STOCK_LOTE, HerramientasDeCarga::AUTO_CONFIRMABLES);
    }
}
