<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261128, P2 (CA4) — TESTS DE ACEPTACIÓN: al leer un pedido de la tienda, el asistente
 * recibe los datos de contacto del COMPRADOR en un bloque aparte (`comprador_del_pedido`), con una nota que le dice
 * que el contacto del pedido es el del comprador y no el de la ficha de Clientes.
 *
 * EL CASO REAL (Truvari, msg 870): «el pedido quedó vinculado a un comprador de la tienda… ninguno de los tres
 * [clientes] tiene teléfono». El teléfono estaba en el pedido (buyer.phone) pero enterrado en el JSON y el modelo
 * lo buscó en la ficha de clientes.
 *
 * 🔴 Estado esperado en el commit de tests: los dos primeros casos están en ROJO (la clave `comprador_del_pedido`
 * no existe). `otra_ruta_no_trae_el_bloque` es una GUARDA que ya pasa hoy (la clave no existe en ninguna ruta) y
 * protege que el bloque no se cuele en consultas que no son de un pedido.
 *
 * Molde: U210 (HerramientasDeCarga::ejecutar('consultar_por_pantalla', …)). Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class U212_Comprador_del_pedido_en_la_consulta_Test extends EmpresaTestCase
{
    /** La nota exacta que acompaña al bloque (§2 del plan). */
    const NOTA_DEL_COMPRADOR = 'Los datos de contacto de este pedido son los del comprador de la tienda (comprador_del_pedido). Informalos de acá; no los busques en la ficha de clientes, que es otra cosa.';

    /** @var User */
    protected $dueno;

    /** @var string|null */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-u212']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->confianza_original = $this->dueno->agente_confianza;

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first() ?: ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        User::where('id', $this->dueno->id)->update(['agente_confianza' => 'directo']);

        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        parent::tearDown();
    }

    /**
     * El pedido N° 83 de los datos comunes de P1/P2 con el comprador «Gastón Buteler».
     *
     * @param  int|null  $client_id  Cliente del sistema al que está vinculado el comprador (null = sin vincular).
     * @return \App\Models\Order
     */
    protected function pedido_83($client_id = null)
    {
        $estado = OrderStatus::firstOrCreate(['name' => 'Sin confirmar']);

        $buyer = Buyer::create([
            'name'                    => 'Gastón',
            'surname'                 => 'Buteler',
            'phone'                   => '3516762999',
            'email'                   => 'gaston@testing.local',
            'user_id'                 => $this->dueno->id,
            'comercio_city_client_id' => $client_id,
        ]);

        return Order::create([
            'num'             => 83,
            'status'          => 'unconfirmed',
            'deliver'         => 1,
            'buyer_id'        => $buyer->id,
            'order_status_id' => $estado->id,
            'user_id'         => $this->dueno->id,
            'total'           => 225501.5,
            'fecha_entrega'   => '2026-11-27 00:00:00',
            'envio_direccion' => [
                'formatted_address' => 'Ramón Cárcano 251, Córdoba',
                'apartment'         => 'Torre B9 1B',
                'reference'         => 'Country, tiene guardia',
            ],
        ]);
    }

    /** Una conversación del dueño con su assistant 'pendiente'. */
    protected function conversacion($pedido = 'Pasame el teléfono del comprador del pedido 83')
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

    /** consultar_por_pantalla por el despacho real. */
    protected function consultar($conversation, $assistant, $ruta, array $parametros)
    {
        $resultado = HerramientasDeCarga::ejecutar('consultar_por_pantalla', ['ruta' => $ruta, 'parametros' => $parametros], $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'Falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * 🔴 CA4 — El comprador sin cliente vinculado (y con tres clientes «Buteler» sin teléfono en la base) trae su
     * contacto en `comprador_del_pedido` y la nota exacta; `cliente_del_sistema` es null.
     *
     * @test
     */
    public function consultar_el_pedido_trae_el_contacto_del_comprador()
    {
        foreach (['BUTELER GASTON', 'BUTELER MARIA', 'BUTELER JUAN'] as $nombre) {
            Client::create(['name' => $nombre, 'user_id' => $this->dueno->id]);
        }

        $this->pedido_83();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->consultar($conversation, $assistant, 'api/order/{order}', ['numero' => 83]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        $this->assertArrayHasKey('comprador_del_pedido', $respuesta, 'Falta el bloque con el contacto del comprador.');
        $this->assertSame([
            'nombre'               => 'Gastón Buteler',
            'telefono'             => '3516762999',
            'email'                => 'gaston@testing.local',
            'direccion_de_entrega' => 'Ramón Cárcano 251, Córdoba, Torre B9 1B (Country, tiene guardia)',
            'cliente_del_sistema'  => null,
        ], $respuesta['comprador_del_pedido']);

        $this->assertSame(self::NOTA_DEL_COMPRADOR, $respuesta['nota_del_comprador']);
    }

    /**
     * 🔴 CA4 — Con el comprador vinculado a un cliente «GASTON BUTELER GIORGI», `cliente_del_sistema` es ese
     * nombre y el teléfono sigue siendo el del comprador (el cliente no tiene).
     *
     * @test
     */
    public function con_cliente_vinculado_el_telefono_sigue_siendo_el_del_comprador()
    {
        $cliente = Client::create(['name' => 'GASTON BUTELER GIORGI', 'user_id' => $this->dueno->id]);

        $this->pedido_83($cliente->id);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->consultar($conversation, $assistant, 'api/order/{order}', ['numero' => 83]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        $this->assertArrayHasKey('comprador_del_pedido', $respuesta, 'Falta el bloque con el contacto del comprador.');
        $this->assertSame('GASTON BUTELER GIORGI', $respuesta['comprador_del_pedido']['cliente_del_sistema']);
        $this->assertSame('3516762999', $respuesta['comprador_del_pedido']['telefono']);
    }

    /**
     * Guarda (verde hoy): una consulta de otra ruta (una venta) no trae el bloque del comprador.
     *
     * @test
     */
    public function otra_ruta_no_trae_el_bloque()
    {
        list($conversation, $assistant) = $this->conversacion('Dame las ventas');

        $respuesta = $this->consultar($conversation, $assistant, 'api/sale', []);

        $this->assertArrayNotHasKey('comprador_del_pedido', $respuesta);
        $this->assertArrayNotHasKey('nota_del_comprador', $respuesta);
    }
}
