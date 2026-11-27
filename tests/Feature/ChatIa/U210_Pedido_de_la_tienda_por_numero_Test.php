<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Buyer;
use App\Models\ExtencionEmpresa;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-recuperacion-20261127, CA2 (A3 del informe de la semana).
 *
 * EL CASO REAL (Truvari, 27/11/2026, msgs 831-836). «Dame el detalle del pedido N° 78» devolvió el
 * pedido N° 77 y «borralo» ejecutó `DELETE api/order/78` anunciando «el pedido N° 77». El dueño ve el
 * N° (`orders.num`, lo que dice el aviso de pedido nuevo) y el asistente pasaba ese número al router
 * como si fuera el id. Es el mismo patrón que el borrado de presupuestos (`112_…`, B8): se resuelve
 * por el número que ve la persona y la tarjeta muestra N° e id antes de borrar.
 *
 * Escenario: dos pedidos del dueño. D (id K, N° K-1, «Domínguez», $188.000) y B (id K+1, N° K,
 * «Bainotti», $102.000). Decir «el pedido N° K» es B, no D.
 *
 * 🔴 Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class U210_Pedido_de_la_tienda_por_numero_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var string|null */
    protected $confianza_original = null;

    /** @var \App\Models\Order Pedido D: id K, N° K-1. */
    protected $pedido_d;

    /** @var \App\Models\Order Pedido B: id K+1, N° K. */
    protected $pedido_b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-u210']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->confianza_original = $this->dueno->agente_confianza;

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first() ?: ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        User::where('id', $this->dueno->id)->update(['agente_confianza' => 'directo']);

        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        // D se crea primero para conocer su id; el N° de D es K-1 y el de B es K (el id de D).
        $this->pedido_d = $this->pedido('Domínguez', 188000, 1, $this->dueno->id);
        $this->pedido_d->update(['num' => (int) $this->pedido_d->id - 1]);

        $this->pedido_b = $this->pedido('Bainotti', 102000, (int) $this->pedido_d->id, $this->dueno->id);
        $this->pedido_b = $this->pedido_b->fresh();
        $this->pedido_d = $this->pedido_d->fresh();

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        parent::tearDown();
    }

    /**
     * Un pedido de la tienda con su comprador.
     *
     * @param  string  $comprador  Nombre del comprador.
     * @param  float  $total
     * @param  int  $numero  El N° (`orders.num`) que ve el dueño.
     * @param  int  $user_id  El dueño del pedido.
     * @return \App\Models\Order
     */
    protected function pedido($comprador, $total, $numero, $user_id)
    {
        $estado = OrderStatus::firstOrCreate(['name' => 'Sin confirmar']);

        $buyer = Buyer::create(['name' => $comprador, 'phone' => '2216000000', 'email' => uniqid('u210') . '@testing.local', 'user_id' => $user_id]);

        return Order::create([
            'num'             => $numero,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $buyer->id,
            'order_status_id' => $estado->id,
            'user_id'         => $user_id,
            'total'           => $total,
        ]);
    }

    protected function conversacion($pedido)
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

    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    protected function borrar($conversation, $assistant, array $parametros)
    {
        return $this->herramienta($conversation, $assistant, 'proponer_borrado_por_pantalla', [
            'ruta'        => 'api/order/{order}',
            'parametros'  => $parametros,
            'descripcion' => 'Borrar el pedido',
        ]);
    }

    protected function consultar($conversation, $assistant, array $parametros)
    {
        return $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', [
            'ruta'       => 'api/order/{order}',
            'parametros' => $parametros,
        ]);
    }

    /**
     * 🔴 CA2.a: «borrá el pedido N° K» deja UNA tarjeta sobre B (id K+1), con N° e id a la vista.
     *
     * @test
     */
    public function borrar_por_numero_arma_la_tarjeta_con_numero_e_id()
    {
        $numero = (int) $this->pedido_b->num;

        list($conversation, $assistant) = $this->conversacion('Borrá el pedido N° ' . $numero);

        $respuesta = $this->borrar($conversation, $assistant, ['numero' => $numero]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_BORRADO_PANTALLA, $respuesta['tipo']);
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        // Se borra B (id K+1), no D (id K).
        $this->assertSame((int) $this->pedido_b->id, (int) $tarjeta->datos['parametros']['order']);
        $this->assertSame(
            'Borrar el pedido N° ' . $numero . ' (id ' . (int) $this->pedido_b->id . ') de Bainotti',
            substr($tarjeta->presentacion['titulo'], 0, strlen('Borrar el pedido N° ' . $numero . ' (id ' . (int) $this->pedido_b->id . ') de Bainotti'))
        );
        $this->assertStringContainsString('102.000', $tarjeta->presentacion['titulo']);
    }

    /**
     * 🔴 CA2.b: si el número que dijo la persona es el N° de uno y el id de OTRO, no se hace nada: el
     * error nombra los dos y no queda ninguna tarjeta.
     *
     * @test
     */
    public function un_numero_que_es_id_de_otro_pedido_no_borra_nada()
    {
        $k = (int) $this->pedido_d->id;

        list($conversation, $assistant) = $this->conversacion('Borrá el pedido ' . $k);

        $respuesta = $this->borrar($conversation, $assistant, ['order' => $k]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('Bainotti', $respuesta['error']);
        $this->assertStringContainsString('Domínguez', $respuesta['error']);
        $this->assertStringContainsString('numero', $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Lo mismo con el número metido en la ruta.
        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_borrado_por_pantalla', [
            'ruta'        => 'api/order/' . $k,
            'parametros'  => [],
            'descripcion' => 'Borrar el pedido',
        ]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        $this->assertNotNull(Order::find($this->pedido_d->id));
        $this->assertNotNull(Order::find($this->pedido_b->id));
    }

    /**
     * 🔴 CA2.c: confirmar la tarjeta del N° K borra B y deja D intacto.
     *
     * @test
     */
    public function confirmar_borra_el_pedido_del_numero_y_no_el_del_id()
    {
        $numero = (int) $this->pedido_b->num;

        list($conversation, $assistant) = $this->conversacion('Borrá el pedido N° ' . $numero);

        $respuesta = $this->borrar($conversation, $assistant, ['numero' => $numero]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $assistant->contenido = 'Te dejé la tarjeta.';
        $assistant->estado = 'listo';
        $assistant->save();

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')->assertStatus(200);

        $this->assertNull(Order::find($this->pedido_b->id), 'El pedido del N° dicho tenía que borrarse.');
        $this->assertNotNull(Order::find($this->pedido_d->id), 'El pedido cuyo id coincide con el N° no se puede tocar.');
    }

    /**
     * 🔴 CA2.d: la consulta por N° trae el pedido que ve el dueño; con el número ambiguo en `order`, el
     * error de ambigüedad.
     *
     * @test
     */
    public function consultar_por_numero_trae_el_pedido_que_ve_el_dueno()
    {
        $numero = (int) $this->pedido_b->num;

        list($conversation, $assistant) = $this->conversacion('Dame el detalle del pedido N° ' . $numero);

        $respuesta = $this->consultar($conversation, $assistant, ['numero' => $numero]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertStringContainsString('api/order/' . (int) $this->pedido_b->id, $respuesta['ruta']);
        $this->assertStringContainsString('Bainotti', json_encode($respuesta['respuesta']));

        $ambigua = $this->consultar($conversation, $assistant, ['order' => (int) $this->pedido_d->id]);

        $this->assertFalse($ambigua['ok'], json_encode($ambigua));
        $this->assertStringContainsString('Bainotti', $ambigua['error']);
        $this->assertStringContainsString('Domínguez', $ambigua['error']);
    }

    /**
     * 🔴 CA2.e: un pedido de otro dueño con ese N° no se toma.
     *
     * @test
     */
    public function el_pedido_de_otro_dueno_no_se_toma()
    {
        $otro = User::create(['name' => 'Otro u210', 'email' => 'otro-u210-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);

        $ajeno = $this->pedido('Ajeno', 5000, 987654, $otro->id);

        list($conversation, $assistant) = $this->conversacion('Borrá el pedido N° 987654');

        $respuesta = $this->borrar($conversation, $assistant, ['numero' => 987654]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('No encontré el pedido N° 987654', (string) $respuesta['error'], json_encode($respuesta));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNotNull(Order::find($ajeno->id));

        $consulta = $this->consultar($conversation, $assistant, ['numero' => 987654]);

        $this->assertFalse($consulta['ok'], json_encode($consulta));
        $this->assertStringContainsString('No encontré el pedido N° 987654', (string) $consulta['error'], json_encode($consulta));
    }

    /**
     * CA2.f: las descripciones de las dos herramientas le dicen al modelo que el pedido va por su N°.
     *
     * @test
     */
    public function las_descripciones_dicen_que_va_por_numero()
    {
        $descripciones = [];

        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            $descripciones[$definicion['name']] = $definicion['description'];
        }

        foreach (['consultar_por_pantalla', 'proponer_borrado_por_pantalla'] as $nombre) {
            $this->assertStringContainsString('api/order/{order}', $descripciones[$nombre], $nombre);
            $this->assertStringContainsString('"numero"', $descripciones[$nombre], $nombre);
        }
    }
}
