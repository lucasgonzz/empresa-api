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
     * Configura al dueño con DeepSeek ágil (claves e ids inventados) para correr el turno entero con el
     * modelo simulado.
     *
     * @return void
     */
    protected function con_el_modelo_simulado()
    {
        config([
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-p103',
            'services.deepseek.model'          => 'deepseek-general-p103',
            'services.deepseek.model_agil'     => 'deepseek-flash-p103',
            'services.deepseek.model_profundo' => 'deepseek-pro-p103',
            'services.deepseek.model_vision'   => 'deepseek-vision-p103',
        ]);

        $this->dueno->agente_proveedor   = 'deepseek';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->save();
    }

    /**
     * Una respuesta del proveedor (formato compatible con Anthropic) con los bloques y el stop_reason pedidos.
     *
     * @param  string  $stop_reason
     * @param  array  $bloques
     * @return array
     */
    protected function respuesta_del_modelo($stop_reason, array $bloques)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => $stop_reason,
            'content'     => $bloques,
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * El turno entero: el modelo llama a proponer_venta con la misma venta, recibe venta_repetida y contesta
     * `$texto`. Devuelve el texto final y los bodies que salieron al proveedor.
     *
     * @param  string  $texto  Lo que contesta el modelo después del tool_result.
     * @return array{0: string, 1: array<int, array>, 2: AiMessage}
     */
    protected function turno_con_la_venta_repetida($texto)
    {
        list($conversation, $assistant) = $this->conversacion();

        $secuencia = \Illuminate\Support\Facades\Http::sequence()
            ->push($this->respuesta_del_modelo('tool_use', [[
                'type'  => 'tool_use',
                'id'    => 'toolu_p103_venta',
                'name'  => 'proponer_venta',
                'input' => $this->input_a_cuenta_corriente(2),
            ]]), 200)
            ->push($this->respuesta_del_modelo('end_turn', [['type' => 'text', 'text' => $texto]]), 200)
            // Si hubiera un reintento, contestaría esto (y el conteo lo vería).
            ->push($this->respuesta_del_modelo('end_turn', [['type' => 'text', 'text' => '¿Querés que la arme igual?']]), 200)
            ->whenEmpty(\Illuminate\Support\Facades\Http::response(['error' => ['type' => 'api_error', 'message' => 'la secuencia se agotó (P103)']], 500));

        \Illuminate\Support\Facades\Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                  => \Illuminate\Support\Facades\Http::response(['error' => 'host sin stub'], 500),
        ]);

        $final = (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = [];

        foreach (\Illuminate\Support\Facades\Http::recorded() as $par) {

            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {

                $bodies[] = json_decode($par[0]->body(), true);
            }
        }

        return [$final, $bodies, $assistant];
    }

    /**
     * 🔴 F1 de la ronda de arreglos (cruce A2 × B1, 31/10/2026): el modelo recibe venta_repetida y lo dice
     * con el N° y la fecha («Ya está registrada la venta N° X del dd/mm a las HH:ii…»). La guarda de la carga
     * dada por hecha NO reintenta (antes lo leía como `quedo`, la nota le hacía borrar el aviso y preguntar
     * «¿la armo?»): una sola llamada después de la herramienta, y la persona lee el N°.
     *
     * @test
     */
    public function el_aviso_de_la_venta_repetida_no_dispara_el_reintento_y_conserva_el_numero()
    {
        $venta = $this->venta_registrada();

        $this->con_el_modelo_simulado();

        $cuando = Carbon::parse($venta->created_at);

        $texto = 'Ya está registrada la venta N° ' . (int) $venta->num . ' del ' . $cuando->format('d/m') . ' a las ' . $cuando->format('H:i')
            . ' a ' . TestingFerreteriaSeeder::CLIENTE_CC . ' con los mismos artículos, cantidades y total. No armé otra. Si es otra venta distinta, decime y la armo.';

        list($final, $bodies, $assistant) = $this->turno_con_la_venta_repetida($texto);

        $this->assertCount(2, $bodies, 'La llamada a proponer_venta y el texto final, sin reintento: ' . json_encode(array_map(function ($b) {
            return end($b['messages']);
        }, $bodies), JSON_UNESCAPED_UNICODE));

        // El tool_result que vio el modelo trae el N° con «del dd/mm a las HH:ii».
        $turno = end($bodies[1]['messages']);
        $resultado = json_decode($turno['content'][0]['content'], true);
        $this->assertSame('venta_repetida', $resultado['codigo']);
        $this->assertStringContainsString('Ya está registrada la venta N° ' . (int) $venta->num . ' del ' . $cuando->format('d/m') . ' a las ' . $cuando->format('H:i'), $resultado['error']);

        $this->assertStringContainsString('venta N° ' . (int) $venta->num, $final, 'La persona lee el N° de la venta ya registrada.');
        $this->assertStringNotContainsString('Ojo: con este mensaje no registré', $final);
        $this->assertSame(0, $this->tarjetas_de($assistant), 'No se armó ninguna tarjeta.');
    }

    /**
     * F1 (b): aunque el modelo lo diga SIN la fecha («La venta N° X ya está registrada…», que la guarda sola
     * lee como `quedo`), el N° que venta_repetida devolvió en ESTE turno queda eximido, como las cargas vistas
     * de otras conversaciones (R2): sin reintento.
     *
     * @test
     */
    public function el_numero_de_venta_repetida_de_este_turno_queda_eximido_de_la_guarda()
    {
        $venta = $this->venta_registrada();

        $this->con_el_modelo_simulado();

        $texto = 'La venta N° ' . (int) $venta->num . ' ya está registrada a ' . TestingFerreteriaSeeder::CLIENTE_CC . '. Si es otra venta distinta, decime y la armo.';

        // La oración sola, sin la exención, sí la lee como carga hecha (si no, este test no probaría nada).
        $this->assertSame('quedo', \App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper::afirma_una_carga_hecha($texto));

        list($final, $bodies) = $this->turno_con_la_venta_repetida($texto);

        $this->assertCount(2, $bodies, 'Sin reintento.');
        $this->assertStringContainsString('venta N° ' . (int) $venta->num, $final);
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

    // ---------------------------------------------------------------------
    // A3 — CUIT y CUIL con el dígito verificador
    // ---------------------------------------------------------------------

    /**
     * El cálculo: los dos casos conocidos válidos, uno con el verificador cambiado, un DNI y un largo
     * que no es ni CUIT ni DNI.
     *
     * @test
     */
    public function el_digito_verificador_se_calcula_como_arca()
    {
        $this->assertNull(CuitIaHelper::motivo_si_no_sirve('30523505668'));
        $this->assertNull(CuitIaHelper::motivo_si_no_sirve('20166036829'));
        $this->assertNull(CuitIaHelper::motivo_si_no_sirve('20-16603682-9'));
        $this->assertNull(CuitIaHelper::motivo_si_no_sirve('14.409.964'), 'Un DNI pasa.');
        $this->assertNull(CuitIaHelper::motivo_si_no_sirve('1440996'), 'Un DNI de 7 dígitos también.');

        $this->assertStringContainsString('dígito verificador', (string) CuitIaHelper::motivo_si_no_sirve('20166036823'));
        $this->assertStringContainsString('11', (string) CuitIaHelper::motivo_si_no_sirve('2016603682'));
    }

    /**
     * 🔴 El alta de un cliente con un CUIT inválido → error y ninguna tarjeta; con uno válido o con un
     * DNI → tarjeta.
     *
     * @test
     */
    public function el_alta_con_un_cuit_invalido_no_arma_tarjeta()
    {
        list($conversation, $assistant) = $this->conversacion();

        $mal = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'client',
            'datos'   => ['name' => 'zz-c103 Cliente con cuit', 'cuit' => '20166036823'],
        ]);

        $this->assertFalse($mal['ok'], json_encode($mal));
        $this->assertStringContainsString('El CUIT 20166036823 no es válido: el dígito verificador no da', $mal['error']);
        $this->assertStringContainsString('pedile el número a la persona', $mal['error']);
        $this->assertSame(0, $this->tarjetas_de($assistant));

        $bien = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'client',
            'datos'   => ['name' => 'zz-c103 Cliente con cuit', 'cuit' => '20166036829'],
        ]);

        $this->assertTrue(!empty($bien['ok']), json_encode($bien));
        $this->assertSame('20166036829', AiMessageAction::find($bien['tarjeta_id'])->datos['pedidos']['cuit']);

        list($conversation_2, $assistant_2) = $this->conversacion();

        $dni = $this->herramienta($conversation_2, $assistant_2, 'proponer_alta', [
            'entidad' => 'client',
            'datos'   => ['name' => 'zz-c103 Cliente con dni', 'cuit' => '14.409.964'],
        ]);

        $this->assertTrue(!empty($dni['ok']), json_encode($dni));
        $this->assertSame('14.409.964', AiMessageAction::find($dni['tarjeta_id'])->datos['pedidos']['cuit']);
    }

    /**
     * Lo mismo en la edición de un cliente que ya existe.
     *
     * @test
     */
    public function la_edicion_con_un_cuit_invalido_no_arma_tarjeta()
    {
        Client::create(['name' => 'zz-c103 Cliente a editar', 'user_id' => $this->dueno->id]);

        list($conversation, $assistant) = $this->conversacion();

        $mal = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'client',
            'registro' => 'zz-c103 Cliente a editar',
            'cambios'  => ['cuit' => '30-52350566-1'],
        ]);

        $this->assertFalse($mal['ok'], json_encode($mal));
        $this->assertStringContainsString('no es válido: el dígito verificador no da', $mal['error']);
        $this->assertSame(0, $this->tarjetas_de($assistant));

        $bien = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'client',
            'registro' => 'zz-c103 Cliente a editar',
            'cambios'  => ['cuit' => '30-52350566-8'],
        ]);

        $this->assertTrue(!empty($bien['ok']), json_encode($bien));
        $this->assertSame(1, $this->tarjetas_de($assistant));
    }

    /**
     * El CUIL de un cliente pasa por la misma validación.
     *
     * @test
     */
    public function el_cuil_tambien_se_valida()
    {
        list($conversation, $assistant) = $this->conversacion();

        $mal = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'client',
            'datos'   => ['name' => 'zz-c103 Cliente con cuil', 'cuil' => '20166036823'],
        ]);

        $this->assertFalse($mal['ok'], json_encode($mal));
        $this->assertStringContainsString('El CUIL 20166036823 no es válido', $mal['error']);
        $this->assertSame(0, $this->tarjetas_de($assistant));

        $bien = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'client',
            'datos'   => ['name' => 'zz-c103 Cliente con cuil', 'cuil' => '20-16603682-9'],
        ]);

        $this->assertTrue(!empty($bien['ok']), json_encode($bien));
    }

    // ---------------------------------------------------------------------
    // A4 — la regla del lote desde una planilla
    // ---------------------------------------------------------------------

    /**
     * La regla de las varias ventas desde una planilla está en el system con las herramientas de carga
     * (en WhatsApp y en el sistema), y no en el de solo lectura.
     *
     * @test
     */
    public function la_regla_del_lote_desde_una_planilla_esta_en_el_bloque_de_carga()
    {
        list($conversation) = $this->conversacion();

        $servicio = new AsistenteIaService();

        $whatsapp = $servicio->build_system_prompt($conversation, $this->dueno->fresh(), true, true);
        $sistema = $servicio->build_system_prompt($conversation, $this->dueno->fresh(), true, false);
        $solo_lectura = $servicio->build_system_prompt($conversation, $this->dueno->fresh(), false, false);

        foreach ([$whatsapp, $sistema] as $prompt) {
            $this->assertStringContainsString('Varias ventas desde una planilla: armá una lista renglón → cliente → artículo → cantidad →', $prompt);
            $this->assertStringContainsString('sacá los renglones ya registrados (decí con', $prompt);
            $this->assertStringContainsString('nunca digas que llegó una', $prompt);
            $this->assertStringContainsString('planilla nueva si este mensaje no trae un adjunto nuevo', $prompt);
            $this->assertStringContainsString('si una foto no se lee, decilo y pedí el', $prompt);
        }

        $this->assertStringNotContainsString('Varias ventas desde una planilla', $solo_lectura);
    }
}
