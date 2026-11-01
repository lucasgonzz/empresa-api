<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\CuitIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión agente-mejoras-diario (análisis diario del 31/10/2026): lo que el agente hizo mal con un lote
 * de ventas sacado de una planilla, y con los CUIT de esa planilla.
 *
 *  A2) 🔴 La venta duplicada de Ferre Total (59542 = 59466): al día siguiente, releyendo la misma
 *      planilla, el asistente armó otra vez una venta ya registrada y el dueño la confirmó. Ahora
 *      proponer_venta, CON cliente, busca desde el inicio de ayer una venta del mismo dueño al mismo
 *      cliente con el mismo total y los mismos pares artículo:cantidad, y si la encuentra contesta error
 *      `venta_repetida` con el N° y no arma la tarjeta; `es_otra_venta: true` la deja pasar. Las ventas
 *      sin cliente no se miran (el 39_Venta_por_asistente_Test arma dos iguales sin cliente) y las
 *      anuladas no cuentan.
 *  A3) 🔴 Los CUIT válidos «corregidos» a inválidos: la carga genérica valida el dígito verificador de
 *      `cuit` y `cuil`, en el alta y en la edición; un DNI (7 u 8 dígitos) pasa.
 *  A4) La regla de las varias ventas desde una planilla está en el bloque de carga del system.
 *
 * Corre sobre el fixture de plata (TestingFerreteriaSeeder). Los artículos son propios del archivo
 * (prefijo `zz-c103`). El dueño se pone en "cauteloso" en cada test (la transacción lo revierte): con
 * "directo" las altas genéricas se ejecutarían solas y no quedaría tarjeta que mirar.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Lote_de_ventas_y_cuit_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var Article Precio 2.000, stock 50. */
    protected $martillo;

    /** @var Article Precio 500, stock 50. */
    protected $pinza;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        // Las altas y ediciones genéricas tienen que dejar tarjeta (ver el docblock de la clase).
        $this->dueno->agente_confianza = ConfianzaDelAgenteIaHelper::CAUTELOSO;
        $this->dueno->save();
        $this->dueno->load('extencions');

        Catalogo::olvidar();

        $this->martillo = $this->articulo_de_prueba('zz-c103 Martillo prueba', 2000, 1000, 50);
        $this->pinza = $this->articulo_de_prueba('zz-c103 Pinza prueba', 500, 200, 50);
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Un artículo del dueño con precio final, costo y stock fijos.
     *
     * @param  string  $nombre
     * @param  float  $precio
     * @param  float  $costo
     * @param  float  $stock
     * @return Article
     */
    protected function articulo_de_prueba($nombre, $precio, $costo, $stock)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => $precio,
            'cost'        => $costo,
            'stock'       => $stock,
            'iva_id'      => 2,
        ]);
    }

    /**
     * Conversación del dueño con su assistant pendiente y las acciones habilitadas.
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Cargá las ventas de la planilla',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * proponer() con el contexto de la conversación (como lo hace HerramientasDeCarga).
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  array  $input
     * @return array
     */
    protected function proponer_venta($conversation, $assistant, array $input)
    {
        return PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $input);
    }

    /**
     * El input de una venta a cuenta corriente al cliente de cuenta corriente del fixture.
     *
     * @param  float  $martillos
     * @param  array  $extra
     * @return array
     */
    protected function input_a_cuenta_corriente($martillos, array $extra = [])
    {
        return array_merge([
            'items'   => [
                ['articulo_id' => $this->martillo->id, 'cantidad' => $martillos],
                ['articulo_id' => $this->pinza->id, 'cantidad' => 1],
            ],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CC,
            'cobro'   => 'cuenta_corriente',
        ], $extra);
    }

    /**
     * Propone y confirma por el endpoint real la venta de input_a_cuenta_corriente(2): la venta ya
     * registrada contra la que se compara todo lo demás.
     *
     * @return Sale
     */
    protected function venta_registrada()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_venta($conversation, $assistant, $this->input_a_cuenta_corriente(2));

        $this->assertTrue(!empty($respuesta['ok']), 'La primera venta tenía que proponerse: ' . json_encode($respuesta));

        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')
            ->assertStatus(200);

        $venta = Sale::find(AiMessageAction::find($respuesta['tarjeta_id'])->resultado->venta_id);

        $this->assertNotNull($venta, 'La primera venta tenía que quedar registrada.');

        return $venta;
    }

    /**
     * Una herramienta de carga por el despacho real (HerramientasDeCarga::ejecutar), decodificada.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $herramienta
     * @param  array  $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * Cuántas tarjetas colgó la herramienta de ese mensaje.
     *
     * @param  AiMessage  $assistant
     * @return int
     */
    protected function tarjetas_de($assistant)
    {
        return AiMessageAction::where('ai_message_id', $assistant->id)->count();
    }

    // ---------------------------------------------------------------------
    // A2 — la venta ya registrada
    // ---------------------------------------------------------------------

    /**
     * 🔴 El caso de Ferre Total: la misma venta (mismo cliente, mismos artículos y cantidades, mismo
     * total) en otra conversación → error `venta_repetida` con el N°, y ninguna tarjeta. Con
     * `es_otra_venta: true` (la persona confirmó que es otra) se arma.
     *
     * @test
     */
    public function la_misma_venta_al_mismo_cliente_no_se_vuelve_a_armar()
    {
        $venta = $this->venta_registrada();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_venta($conversation, $assistant, $this->input_a_cuenta_corriente(2));

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('venta_repetida', $respuesta['codigo']);
        $this->assertStringContainsString('Ya está registrada la venta N° ' . (int) $venta->num, $respuesta['error']);
        $this->assertStringContainsString(TestingFerreteriaSeeder::CLIENTE_CC, $respuesta['error']);
        $this->assertStringContainsString('es_otra_venta: true', $respuesta['error']);
        $this->assertSame((int) $venta->id, $respuesta['venta']['venta_id']);
        $this->assertSame((int) $venta->num, $respuesta['venta']['numero']);
        $this->assertSame(0, $this->tarjetas_de($assistant), 'No se armó ninguna tarjeta.');

        // La persona dice que es otra: se arma.
        $otra = $this->proponer_venta($conversation, $assistant, $this->input_a_cuenta_corriente(2, ['es_otra_venta' => true]));

        $this->assertTrue(!empty($otra['ok']), json_encode($otra));
        $this->assertSame(1, $this->tarjetas_de($assistant));
    }

    /**
     * Otra cantidad u otro cliente es otra venta: se arma como siempre.
     *
     * @test
     */
    public function otra_cantidad_u_otro_cliente_es_otra_venta()
    {
        $this->venta_registrada();

        // Otra cantidad (y por lo tanto otro total).
        list($conversation, $assistant) = $this->conversacion();

        $otra_cantidad = $this->proponer_venta($conversation, $assistant, $this->input_a_cuenta_corriente(3));

        $this->assertTrue(!empty($otra_cantidad['ok']), json_encode($otra_cantidad));

        // Otro cliente, mismos artículos, cantidades y total (de contado: no necesita cuenta corriente).
        Client::create(['name' => 'zz-c103 Otro cliente', 'user_id' => $this->dueno->id]);

        list($conversation_2, $assistant_2) = $this->conversacion();

        $otro_cliente = $this->proponer_venta($conversation_2, $assistant_2, [
            'items'          => [
                ['articulo_id' => $this->martillo->id, 'cantidad' => 2],
                ['articulo_id' => $this->pinza->id, 'cantidad' => 1],
            ],
            'cliente'        => 'zz-c103 Otro cliente',
            'cobro'          => 'contado',
            'metodo_de_pago' => 'Transferencia',
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ]);

        $this->assertTrue(!empty($otro_cliente['ok']), json_encode($otro_cliente));
    }

    /**
     * Una venta anulada (borrada: SoftDeletes) no cuenta, y una de antes de ayer tampoco; una de ayer a
     * primera hora sí.
     *
     * @test
     */
    public function una_venta_anulada_o_de_antes_de_ayer_no_cuenta()
    {
        $venta = $this->venta_registrada();

        // Ayer a primera hora: adentro de la ventana.
        Sale::where('id', $venta->id)->update(['created_at' => Carbon::today()->subDays(1)->addMinute()]);

        list($conversation, $assistant) = $this->conversacion();

        $ayer = $this->proponer_venta($conversation, $assistant, $this->input_a_cuenta_corriente(2));

        $this->assertSame('venta_repetida', isset($ayer['codigo']) ? $ayer['codigo'] : null, json_encode($ayer));

        // Antes de ayer a última hora: afuera de la ventana.
        Sale::where('id', $venta->id)->update(['created_at' => Carbon::today()->subDays(1)->subMinute()]);

        list($conversation_2, $assistant_2) = $this->conversacion();

        $antes_de_ayer = $this->proponer_venta($conversation_2, $assistant_2, $this->input_a_cuenta_corriente(2));

        $this->assertTrue(!empty($antes_de_ayer['ok']), json_encode($antes_de_ayer));

        // De hoy pero anulada.
        Sale::where('id', $venta->id)->update(['created_at' => Carbon::now(), 'deleted_at' => Carbon::now()]);

        list($conversation_3, $assistant_3) = $this->conversacion();

        $anulada = $this->proponer_venta($conversation_3, $assistant_3, $this->input_a_cuenta_corriente(2));

        $this->assertTrue(!empty($anulada['ok']), json_encode($anulada));
    }

    /**
     * La propiedad nueva va AL FINAL de las de proponer_venta (el orden es el prefijo del caché).
     *
     * @test
     */
    public function es_otra_venta_va_al_final_de_las_propiedades_de_proponer_venta()
    {
        $definiciones = collect(HerramientasDeCarga::definiciones())->keyBy('name');

        $propiedades = array_keys($definiciones['proponer_venta']['input_schema']['properties']);

        $this->assertSame('es_otra_venta', end($propiedades));
        $this->assertSame('boolean', $definiciones['proponer_venta']['input_schema']['properties']['es_otra_venta']['type']);
    }
}
