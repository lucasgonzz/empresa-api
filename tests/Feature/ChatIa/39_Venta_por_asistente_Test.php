<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AccionIaException;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Assert;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-omnisciente — bloque C2: la venta que propone el asistente y lo que pasa al
 * confirmarla (contrato §4).
 *
 * Corre sobre el fixture de plata (TestingFerreteriaSeeder): la caja de efectivo, los métodos de
 * pago del catálogo, el cliente de cuenta corriente, el descuento de venta "Descuento e2e" (15 %)
 * y el descuento del 10 % por pagar con Efectivo. Los artículos son propios del archivo (prefijo
 * `zz-c39`), con precios redondos para que los números de la tarjeta se puedan leer a ojo.
 *
 * Lo que protege:
 *  - Que la venta se cree por `SaleController::store()` con el MISMO payload que arma `vender.js`
 *    (las mismas claves, en el mismo orden), y que al confirmar queden `sales`, `article_sale`, el
 *    método de pago con su caja y el movimiento de caja (contado), o el movimiento de cuenta
 *    corriente (cuenta corriente), con el stock descontado.
 *  - Los `faltan`: contado sin método, cliente sin cobro, artículo ambiguo; y el `error` del
 *    artículo inexistente.
 *  - Que el precio dictado mande sobre el de lista, que el descuento del método de pago se aplique
 *    al precio como en la pantalla, y que el descuento de venta se aplique al total como en la
 *    pantalla (y sea uno del catálogo).
 *  - Que un 422 del controller (límite de crédito) vuelva como texto de la tarjeta sin escribir
 *    nada, y que el 200 vacío del guard anti-duplicado se informe y no duplique.
 *  - Que con el dueño en "resuelto" la tarjeta quede propuesta: una venta nunca se auto-confirma.
 *
 * @group chat-ia
 */
class Venta_por_asistente_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta para comparar montos. */
    const DELTA = 0.01;

    /**
     * Las claves del POST de `api/sale` tal como las manda la acción `vender` de
     * `src/store/vender/vender.js` (empresa-spa, develop del 21/9/2026), en su orden. Copiadas del
     * archivo, no del helper: si la SPA agrega una clave, este test se pone rojo y el helper se
     * actualiza en el mismo diff.
     *
     * @var array<int, string>
     */
    const CLAVES_DE_VENDER_JS = [
        'save_afip_ticket', 'items', 'client_id', 'discounts', 'surchages', 'save_current_acount',
        'make_current_acount_pago', 'sale_type_id', 'discounts_in_services', 'surchages_in_services',
        'current_acount_payment_method_id', 'afip_information_id', 'employee_id', 'address_id',
        'to_check', 'checked', 'confirmed', 'observations', 'omitir_en_cuenta_corriente',
        'numero_orden_de_compra', 'selected_payment_methods', 'discount_percentage', 'discount_amount',
        'sub_total', 'price_type_id', 'total', 'seller_id', 'cuota_id', 'cantidad_cuotas',
        'cuota_descuento', 'cuota_recargo', 'monto_credito_real', 'caja_id', 'moneda_id', 'valor_dolar',
        'afip_tipo_comprobante_id', 'descuento', 'forzar_total_monto', 'puntos_canjeados',
        'descuento_puntos', 'fecha_entrega', 'incoterms', 'observations_ocultas',
        'aplicar_recargos_directo_a_items', 'sale_status_id', 'discount_stock', 'iva_aplicado',
        'price_description', 'send_mail', 'dias_alerta_venta_no_cobrada_personalizado', 'log',
    ];

    /** @var User */
    protected $dueno;

    /** @var Article Precio 2.000, stock 10. */
    protected $martillo;

    /** @var Article Precio 500, stock 3. */
    protected $pinza;

    /** @var array<int,int> Ids de credit_accounts cuyo limite_credito se tocó, con su valor original. */
    protected $limites_a_restaurar = [];

    /** @var array<int,\App\Models\ExtencionEmpresa> Extensiones enganchadas por un test, para sacarlas. */
    protected $extensiones_enganchadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->dar_extension('asistente_ia');

        $this->martillo = $this->articulo_de_prueba('zz-c39 Martillo prueba', 2000, 1000, 10);
        $this->pinza = $this->articulo_de_prueba('zz-c39 Pinza prueba', 500, 200, 3);
    }

    protected function tearDown(): void
    {
        foreach ($this->limites_a_restaurar as $id => $limite) {
            CreditAccount::where('id', $id)->update(['limite_credito' => $limite]);
        }

        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        $this->limpiar_escenarios();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Engancha una extensión al dueño (creando la fila si la base no la tiene) y la anota para
     * desengancharla en tearDown().
     *
     * @param  string  $slug
     * @return void
     */
    protected function dar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $this->extensiones_enganchadas[] = $extencion;
    }

    /**
     * Un artículo del dueño con precio final, costo y stock fijos.
     *
     * @param  string  $nombre
     * @param  float  $precio
     * @param  float  $costo
     * @param  float|null  $stock
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
     * @param  User|null  $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Hacele una venta',
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
     * Llama a proponer() con el contexto de la conversación (como lo haría HerramientasDeCarga).
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  array  $input
     * @return array
     */
    protected function proponer($conversation, $assistant, array $input)
    {
        return PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $input);
    }

    /**
     * Input de una venta de contado por Transferencia (sin descuento por método) a la Caja Efectivo.
     *
     * @param  array  $items
     * @param  array  $extra
     * @return array
     */
    protected function input_de_contado(array $items, array $extra = [])
    {
        return array_merge([
            'items'          => $items,
            'cobro'          => 'contado',
            'metodo_de_pago' => 'Transferencia',
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ], $extra);
    }

    /**
     * Deja el assistant 'listo' (la SPA recién ahí muestra la tarjeta) y confirma la tarjeta,
     * registrando los movimientos de caja nuevos para la limpieza.
     *
     * Por el endpoint real (`POST .../acciones/{id}/confirmar`) en cuanto el constructor B haya
     * sumado `AiMessageAction::TIPO_VENTA` y su `case` en `EjecutorAccionesIaHelper` (contrato §4).
     * Hasta entonces, la tarjeta se confirma emulando lo que ese ejecutor hace alrededor de
     * `ejecutar()`: la transacción con el candado sobre la fila, el paso a 'confirmada' con el
     * resultado, y el `error_mensaje` persistido afuera de la transacción cuando es un 422. Así
     * el camino de la venta —que es lo que este archivo protege— se prueba de verdad hoy y por el
     * endpoint mañana, sin tocar un test.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  int  $tarjeta_id
     * @return RespuestaDeConfirmar
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $movimientos_antes = $this->max_id_movimiento_caja();

        if (defined('App\Models\AiMessageAction::TIPO_VENTA')) {

            $http = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');

            $respuesta = new RespuestaDeConfirmar($http->getStatusCode(), $http->json() ?: []);

        } else {

            $respuesta = $this->confirmar_emulando_al_ejecutor($conversation, $tarjeta_id);
        }

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $venta_id = $respuesta->json('model.resultado.venta_id');

        if (!is_null($venta_id)) {
            $this->ventas_creadas_por_escenarios[] = (int) $venta_id;
        }

        return $respuesta;
    }

    /**
     * Lo que EjecutorAccionesIaHelper::ejecutar_confirmacion() hace alrededor de ejecutar():
     * ver el docblock de confirmar().
     *
     * @param  AiConversation  $conversation
     * @param  int  $tarjeta_id
     * @return RespuestaDeConfirmar
     */
    protected function confirmar_emulando_al_ejecutor($conversation, $tarjeta_id)
    {
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation, Auth::user());

        try {

            DB::transaction(function () use ($contexto, $tarjeta_id) {

                $accion = AiMessageAction::where('id', $tarjeta_id)->lockForUpdate()->firstOrFail();

                $resultado = PropuestaVentaIaHelper::ejecutar($contexto, $accion);

                $accion->estado = AiMessageAction::ESTADO_CONFIRMADA;
                $accion->resultado = $resultado;
                $accion->resuelta_at = now();
                $accion->error_mensaje = null;
                $accion->save();
            });

        } catch (AccionIaException $e) {

            if ($e->status === 422) {
                AiMessageAction::where('id', $tarjeta_id)->update(['error_mensaje' => $e->getMessage()]);
            }

            return new RespuestaDeConfirmar($e->status, [
                'message' => $e->getMessage(),
                'model'   => AiMessageAction::find($tarjeta_id)->jsonSerialize(),
            ]);
        }

        return new RespuestaDeConfirmar(200, ['model' => AiMessageAction::find($tarjeta_id)->jsonSerialize()]);
    }

    /**
     * El renglón de la presentación con esa etiqueta (el primero), o todos los de esa etiqueta.
     *
     * @param  array  $presentacion
     * @param  string  $etiqueta
     * @param  bool  $todos
     * @return string|array|null
     */
    protected function renglon(array $presentacion, $etiqueta, $todos = false)
    {
        $valores = [];

        foreach ($presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === $etiqueta) {
                $valores[] = $renglon['valor'];
            }
        }

        if ($todos) {
            return $valores;
        }

        return count($valores) ? $valores[0] : null;
    }

    /**
     * @return Client
     */
    protected function cliente_cc()
    {
        return Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)
            ->where('user_id', $this->dueno->id)
            ->firstOrFail();
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    /**
     * 🔴 EL CAMINO COMPLETO DE CONTADO: propuesta → tarjeta con renglones y total → confirmar por
     * el endpoint real → fila en `sales` con `num`, `article_sale` con precio y cantidad, el método
     * de pago con su caja, el movimiento de caja, el stock descontado, y `resultado` con el número.
     *
     * @test
     */
    public function una_venta_de_contado_de_punta_a_punta()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $transferencia = $this->resolver_metodo_pago_por_nombre('Transferencia');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 3],
            ['articulo_id' => $this->pinza->id, 'cantidad' => 1],
        ], ['observaciones' => 'Venta desde el chat']));

        $this->assertTrue(!empty($respuesta['ok']), 'La propuesta tenía que quedar armada: ' . json_encode($respuesta));
        $this->assertEquals('venta', $respuesta['tipo']);
        $this->assertStringContainsString('3 × zz-c39 Martillo prueba', $respuesta['resumen']);
        $this->assertStringContainsString('Total $ 6.500', $respuesta['resumen']);
        $this->assertEquals([], $respuesta['reemplazo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('propuesta', $tarjeta->estado);
        $this->assertEquals('venta', $tarjeta->tipo);
        $this->assertStringStartsWith('venta:', $tarjeta->clave);

        $presentacion = $tarjeta->presentacion;

        $this->assertEquals('Venta', $presentacion['titulo']);
        $this->assertEquals(
            ['3 × zz-c39 Martillo prueba — $ 2.000 = $ 6.000', '1 × zz-c39 Pinza prueba — $ 500 = $ 500'],
            $this->renglon($presentacion, 'Artículo', true)
        );
        $this->assertEquals('Sin cliente', $this->renglon($presentacion, 'Cliente'));
        $this->assertEquals('Transferencia · ' . TestingFerreteriaSeeder::CAJA_EFECTIVO, $this->renglon($presentacion, 'Cobro'));
        $this->assertEquals('Venta desde el chat', $this->renglon($presentacion, 'Observaciones'));
        $this->assertEquals('$ 6.500', $this->renglon($presentacion, 'Total'));
        $this->assertNull($this->renglon($presentacion, 'Descuento'));
        $this->assertNull($presentacion['aviso'], 'Con stock de sobra no hay aviso.');

        // El payload guardado es el de vender.js: sin cliente, de contado por el método único del select.
        $payload = $tarjeta->datos['payload'];

        $this->assertNull($payload['client_id']);
        $this->assertEquals($transferencia->id, $payload['current_acount_payment_method_id']);
        $this->assertEquals($caja->id, $payload['caja_id']);
        $this->assertEquals([], $payload['selected_payment_methods']);
        $this->assertEquals(0, $payload['omitir_en_cuenta_corriente']);
        $this->assertEquals(1, $payload['save_current_acount']);
        $this->assertEqualsWithDelta(6500, $payload['sub_total'], self::DELTA);
        $this->assertEqualsWithDelta(6500, $payload['total'], self::DELTA);
        $this->assertEquals(1, $payload['discount_stock']);
        $this->assertEquals(1, $payload['iva_aplicado']);
        $this->assertEquals(0, $payload['save_afip_ticket']);
        $this->assertEquals(0, $payload['send_mail']);
        $this->assertNull($payload['price_type_id'], 'La cuenta no vende con listas: la venta sale sin lista, como en la pantalla.');
        $this->assertCount(2, $payload['items']);
        $this->assertEquals($this->martillo->id, $payload['items'][0]['id']);
        $this->assertTrue($payload['items'][0]['is_article']);
        $this->assertEqualsWithDelta(3, $payload['items'][0]['amount'], self::DELTA);
        $this->assertEqualsWithDelta(2000, $payload['items'][0]['price_vender'], self::DELTA);
        $this->assertNull($payload['items'][0]['price_vender_personalizado']);
        $this->assertEquals(6500, $tarjeta->datos['resumen']['total']);

        $ventas_antes = Sale::where('user_id', $this->dueno->id)->count();

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('confirmada', $confirmar->json('model.estado'));
        $this->assertEquals('sale', $confirmar->json('model.resultado.ruta.name'));
        $this->assertEquals('Ver en Ventas', $confirmar->json('model.resultado.ruta.texto'));

        $venta = Sale::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        $this->assertEquals($ventas_antes + 1, Sale::where('user_id', $this->dueno->id)->count());
        $this->assertEquals($venta->id, $confirmar->json('model.resultado.venta_id'));
        $this->assertEquals((int) $venta->num, $confirmar->json('model.resultado.numero'));
        $this->assertEqualsWithDelta(6500, $confirmar->json('model.resultado.total'), self::DELTA);
        $this->assertEquals('Venta N° ' . $venta->num . ' registrada', $confirmar->json('model.resultado.texto'));

        // La fila de sales, como la deja la pantalla.
        $this->assertGreaterThan(0, (int) $venta->num);
        $this->assertNull($venta->client_id);
        $this->assertEqualsWithDelta(6500, (float) $venta->total, self::DELTA);
        $this->assertEqualsWithDelta(6500, (float) $venta->sub_total, self::DELTA);
        $this->assertEquals(1, (int) $venta->discount_stock);
        $this->assertEquals(1, (int) $venta->moneda_id);
        $this->assertEquals('Venta desde el chat', $venta->observations);

        // Los renglones, con precio y cantidad.
        $venta->load('articles', 'current_acount_payment_methods');

        $this->assertCount(2, $venta->articles);

        $renglon_martillo = $venta->articles->firstWhere('id', $this->martillo->id);

        $this->assertNotNull($renglon_martillo);
        $this->assertEqualsWithDelta(3, (float) $renglon_martillo->pivot->amount, self::DELTA);
        $this->assertEqualsWithDelta(2000, (float) $renglon_martillo->pivot->price, self::DELTA);
        $this->assertEqualsWithDelta(1000, (float) $renglon_martillo->pivot->cost, self::DELTA);

        // El método de pago con su caja, y el movimiento de caja del cobro.
        $this->assertCount(1, $venta->current_acount_payment_methods);
        $this->assertEquals($transferencia->id, $venta->current_acount_payment_methods[0]->id);
        $this->assertEquals($caja->id, $venta->current_acount_payment_methods[0]->pivot->caja_id);
        $this->assertEqualsWithDelta(6500, (float) $venta->current_acount_payment_methods[0]->pivot->amount, self::DELTA);

        $movimientos = MovimientoCaja::where('sale_id', $venta->id)->get();

        $this->assertCount(1, $movimientos);
        $this->assertEquals($caja->id, $movimientos[0]->caja_id);
        $this->assertEqualsWithDelta(6500, (float) $movimientos[0]->ingreso, self::DELTA);

        // Sin cliente no hay cuenta corriente, y el stock bajó.
        $this->assertEquals(0, CurrentAcount::where('sale_id', $venta->id)->count());
        $this->assertEqualsWithDelta(7, (float) Article::find($this->martillo->id)->stock, self::DELTA);
        $this->assertEqualsWithDelta(2, (float) Article::find($this->pinza->id)->stock, self::DELTA);
    }

    /**
     * El camino completo a CUENTA CORRIENTE: la tarjeta dice "A cuenta corriente", y al confirmar
     * queda el movimiento en `current_acounts` con el total, sin método de pago ni movimiento de caja.
     *
     * @test
     */
    public function una_venta_a_cuenta_corriente_de_punta_a_punta()
    {
        $cliente = $this->cliente_cc();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'items'   => [['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 2]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CC,
            'cobro'   => 'cuenta_corriente',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(TestingFerreteriaSeeder::CLIENTE_CC, $this->renglon($tarjeta->presentacion, 'Cliente'));
        $this->assertEquals('A cuenta corriente', $this->renglon($tarjeta->presentacion, 'Cobro'));
        $this->assertEquals('$ 4.000', $this->renglon($tarjeta->presentacion, 'Total'));

        $payload = $tarjeta->datos['payload'];

        $this->assertEquals($cliente->id, $payload['client_id']);
        $this->assertEquals(0, $payload['omitir_en_cuenta_corriente']);
        $this->assertEquals(1, $payload['save_current_acount']);
        $this->assertEquals(0, $payload['current_acount_payment_method_id']);
        $this->assertEquals(0, $payload['caja_id']);

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('confirmada', $confirmar->json('model.estado'));

        $venta = Sale::find($confirmar->json('model.resultado.venta_id'));

        $this->assertNotNull($venta);
        $this->assertEquals($cliente->id, $venta->client_id);
        $this->assertEqualsWithDelta(4000, (float) $venta->total, self::DELTA);

        $movimiento = CurrentAcount::where('sale_id', $venta->id)->whereNull('haber')->first();

        $this->assertNotNull($movimiento, 'La venta a cuenta corriente deja su movimiento en current_acounts.');
        $this->assertEqualsWithDelta(4000, (float) $movimiento->debe, self::DELTA);
        $this->assertEquals($cliente->id, $movimiento->client_id);

        $venta->load('current_acount_payment_methods');

        $this->assertCount(0, $venta->current_acount_payment_methods, 'A cuenta corriente no se adjunta método de pago.');
        $this->assertEquals(0, MovimientoCaja::where('sale_id', $venta->id)->count());
    }

    /**
     * Contado sin método de pago → `faltan`, con los métodos usables (nunca el cheque ni la
     * tarjeta de crédito, que se cargan desde la pantalla).
     *
     * @test
     */
    public function contado_sin_metodo_de_pago_pide_el_metodo()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'items' => [['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1]],
            'cobro' => 'contado',
            'caja'  => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertNull($respuesta['error']);
        $this->assertCount(1, $respuesta['faltan']);
        $this->assertStringContainsString('método de pago', $respuesta['faltan'][0]);

        $nombres = array_column($respuesta['opciones']['metodos_de_pago'], 'nombre');

        $this->assertContains('Efectivo', $nombres);
        $this->assertContains('Transferencia', $nombres);
        $this->assertNotContains('Cheque', $nombres);
        $this->assertNotContains('Credito', $nombres);

        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Con datos faltantes no se crea tarjeta.');
    }

    /**
     * Con cliente y sin decir cómo se cobra → `faltan` en un solo mensaje: contado (con qué método
     * y a qué caja) o cuenta corriente.
     *
     * @test
     */
    public function con_cliente_y_sin_cobro_pregunta_si_es_contado_o_cuenta_corriente()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'items'   => [['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CC,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertNull($respuesta['error']);
        $this->assertCount(1, $respuesta['faltan']);
        $this->assertStringContainsString('cuenta corriente', $respuesta['faltan'][0]);
        $this->assertStringContainsString(TestingFerreteriaSeeder::CLIENTE_CC, $respuesta['faltan'][0]);
        $this->assertEquals(['contado', 'cuenta_corriente'], $respuesta['opciones']['cobro']);
        $this->assertNotEmpty($respuesta['opciones']['metodos_de_pago']);
        $this->assertNotEmpty($respuesta['opciones']['cajas']);
    }

    /**
     * Sin cliente no hay cuenta corriente a la que mandarla: pedir `cuenta_corriente` es un error,
     * y no decir nada es contado.
     *
     * @test
     */
    public function sin_cliente_la_venta_es_de_contado()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'items' => [['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1]],
            'cobro' => 'cuenta_corriente',
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('Sin cliente la venta es de contado', $respuesta['error']);

        // Sin cobro y sin cliente: contado, y lo que falta es el método (la caja se pide junto).
        $respuesta = $this->proponer($conversation, $assistant, [
            'items' => [['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1]],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertNull($respuesta['error']);
        $this->assertStringContainsString('método de pago', $respuesta['faltan'][0]);
    }

    /**
     * Un artículo que matchea dos → `faltan` con las opciones (id, nombre y precio), junto con lo
     * demás que falte, sin crear tarjeta.
     *
     * @test
     */
    public function un_articulo_ambiguo_pide_cual_es()
    {
        $cinco = $this->articulo_de_prueba('zz-c39 Tornillo 5mm', 100, 40, 50);
        $ocho = $this->articulo_de_prueba('zz-c39 Tornillo 8mm', 120, 50, 50);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Tornillo', 'cantidad' => 10],
            ['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1],
        ]));

        $this->assertFalse($respuesta['ok']);
        $this->assertNull($respuesta['error']);
        $this->assertCount(1, $respuesta['faltan']);
        $this->assertStringContainsString('zz-c39 Tornillo', $respuesta['faltan'][0]);

        $opciones = $respuesta['opciones']['articulos'];

        $this->assertEquals([$cinco->id, $ocho->id], array_column($opciones, 'articulo_id'));
        $this->assertEquals(['zz-c39 Tornillo 5mm', 'zz-c39 Tornillo 8mm'], array_column($opciones, 'nombre'));
        $this->assertEquals([100, 120], array_column($opciones, 'precio'));

        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // El nombre completo gana sobre los parciales: "zz-c39 Tornillo 5mm" no es ambiguo.
        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 tornillo 5MM', 'cantidad' => 10],
        ]));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertStringContainsString('10 × zz-c39 Tornillo 5mm', $respuesta['resumen']);
    }

    /**
     * Un artículo que no existe (o está pausado) → `error`, no tarjeta.
     *
     * @test
     */
    public function un_articulo_inexistente_es_error()
    {
        $pausado = $this->articulo_de_prueba('zz-c39 Pausado', 100, 40, 50);
        $pausado->status = 'inactive';
        $pausado->save();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Esto no existe', 'cantidad' => 1],
        ]));

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No encontré ningún artículo activo', $respuesta['error']);

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo_id' => $pausado->id, 'cantidad' => 1],
        ]));

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('no existe entre tus artículos activos', $respuesta['error']);

        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * El precio dictado manda sobre el de lista: va como precio personalizado del renglón (y, como
     * en la pantalla, sin el descuento del método de pago encima).
     *
     * @test
     */
    public function el_precio_dictado_manda_sobre_el_de_lista()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 3, 'precio_unitario' => 1500],
        ], ['metodo_de_pago' => 'Efectivo']));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(['3 × zz-c39 Martillo prueba — $ 1.500 = $ 4.500'], $this->renglon($tarjeta->presentacion, 'Artículo', true));
        $this->assertEquals('$ 4.500', $this->renglon($tarjeta->presentacion, 'Total'));

        $item = $tarjeta->datos['payload']['items'][0];

        $this->assertEqualsWithDelta(1500, $item['price_vender'], self::DELTA);
        $this->assertEqualsWithDelta(1500, $item['price_vender_personalizado'], self::DELTA);
        $this->assertEquals('dictado', $tarjeta->datos['resumen']['articulos'][0]['origen']);
    }

    /**
     * El descuento del método de pago (Efectivo 10 % en el fixture) se aplica al PRECIO de cada
     * renglón, como hace `getPriceVender()` con el método único del select: $ 2.000 → $ 1.800.
     *
     * @test
     */
    public function el_descuento_del_metodo_de_pago_se_aplica_al_precio_como_en_la_pantalla()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 2],
        ], ['metodo_de_pago' => 'efectivo']));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(['2 × zz-c39 Martillo prueba — $ 1.800 = $ 3.600'], $this->renglon($tarjeta->presentacion, 'Artículo', true));
        $this->assertEquals('Efectivo · ' . TestingFerreteriaSeeder::CAJA_EFECTIVO, $this->renglon($tarjeta->presentacion, 'Cobro'));
        $this->assertEquals('$ 3.600', $this->renglon($tarjeta->presentacion, 'Total'));
        $this->assertEqualsWithDelta(1800, $tarjeta->datos['payload']['items'][0]['price_vender'], self::DELTA);
        $this->assertEqualsWithDelta(3600, $tarjeta->datos['payload']['total'], self::DELTA);
    }

    /**
     * El descuento porcentual es uno del catálogo de descuentos de venta (el mismo panel de la
     * etapa 3 de Vender) y se aplica al total como `aplicar_discounts()`: 6.000 − 15 % = 5.100.
     * Un porcentaje que no está cargado no se inventa: se dice cuáles hay.
     *
     * @test
     */
    public function el_descuento_porcentual_se_aplica_al_total_como_en_la_pantalla()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 3],
        ], ['descuento_porcentaje' => TestingFerreteriaSeeder::DESCUENTO_VENTA_PORCENTAJE]));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(TestingFerreteriaSeeder::DESCUENTO_VENTA . ' 15 % (−$ 900)', $this->renglon($tarjeta->presentacion, 'Descuento'));
        $this->assertEquals('$ 5.100', $this->renglon($tarjeta->presentacion, 'Total'));

        $payload = $tarjeta->datos['payload'];

        $this->assertEqualsWithDelta(6000, $payload['sub_total'], self::DELTA);
        $this->assertEqualsWithDelta(5100, $payload['total'], self::DELTA);
        $this->assertCount(1, $payload['discounts']);
        $this->assertEqualsWithDelta(15, $payload['discounts'][0]['percentage'], self::DELTA);

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);

        $venta = Sale::find($confirmar->json('model.resultado.venta_id'));

        $this->assertEqualsWithDelta(5100, (float) $venta->total, self::DELTA);

        $venta->load('discounts');

        $this->assertCount(1, $venta->discounts, 'El descuento queda adjunto a la venta, como desde la pantalla.');
        $this->assertEqualsWithDelta(15, (float) $venta->discounts[0]->pivot->percentage, self::DELTA);

        // Un porcentaje que no está en el catálogo no se inventa.
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1],
        ], ['descuento_porcentaje' => 7]));

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No tenés un descuento de venta del 7 %', $respuesta['error']);
        $this->assertStringContainsString(TestingFerreteriaSeeder::DESCUENTO_VENTA . ' (15 %)', $respuesta['error']);
    }

    /**
     * 🔴 EL 422 DEL CONTROLLER (el límite de crédito, que solo él puede juzgar al momento del clic)
     * vuelve como mensaje de la tarjeta y NO escribe nada: ni `sales`, ni `article_sale`, ni
     * `current_acounts`, ni stock. La tarjeta sigue propuesta.
     *
     * @test
     */
    public function el_422_del_controller_vuelve_como_mensaje_de_la_tarjeta_y_no_escribe_nada()
    {
        $cliente = $this->cliente_cc();

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->dueno->id);

        $cuenta = CreditAccount::where('model_name', 'client')->where('model_id', $cliente->id)->where('moneda_id', 1)->firstOrFail();

        $this->limites_a_restaurar[$cuenta->id] = $cuenta->limite_credito;

        // Un límite que la venta de $ 4.000 supera seguro, sea cual sea el saldo de partida.
        $saldo = (float) CurrentAcountHelper::getSaldo($cuenta->id);
        $cuenta->limite_credito = max(0, $saldo) + 1000;
        $cuenta->save();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'items'   => [['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 2]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CC,
            'cobro'   => 'cuenta_corriente',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $ventas_antes = Sale::where('user_id', $this->dueno->id)->count();
        $renglones_antes = DB::table('article_sale')->where('article_id', $this->martillo->id)->count();
        $movimientos_antes = CurrentAcount::where('client_id', $cliente->id)->count();

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(422);
        $this->assertEquals('propuesta', $confirmar->json('model.estado'), 'La tarjeta queda confirmable.');
        $this->assertStringContainsString('límite', mb_strtolower($confirmar->json('model.error_mensaje')));
        $this->assertEquals($confirmar->json('message'), $confirmar->json('model.error_mensaje'));

        $this->assertEquals($ventas_antes, Sale::where('user_id', $this->dueno->id)->count(), 'Un 422 del controller no deja venta.');
        $this->assertEquals($renglones_antes, DB::table('article_sale')->where('article_id', $this->martillo->id)->count());
        $this->assertEquals($movimientos_antes, CurrentAcount::where('client_id', $cliente->id)->count());
        $this->assertEqualsWithDelta(10, (float) Article::find($this->martillo->id)->stock, self::DELTA, 'El stock no se tocó.');
    }

    /**
     * 🔴 EL GUARD ANTI-DUPLICADO. `SaleController::venta_ya_cread()` devuelve un 200 vacío si hay
     * una venta igual (cliente, empleado, total) de hace menos de 5 segundos. Eso no es un éxito:
     * la tarjeta queda propuesta con el texto que manda a mirar Ventas, y no hay segunda venta.
     *
     * @test
     */
    public function el_guard_anti_duplicado_se_informa_y_no_duplica()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        $items = [['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1]];

        list($conversation, $assistant) = $this->conversacion();

        $primera = $this->proponer($conversation, $assistant, $this->input_de_contado($items));

        $this->assertTrue(!empty($primera['ok']), json_encode($primera));

        $confirmar = $this->confirmar($conversation, $assistant, $primera['tarjeta_id']);

        $confirmar->assertStatus(200);

        $ventas_despues_de_la_primera = Sale::where('user_id', $this->dueno->id)->count();

        // La misma venta, un instante después (la tarjeta nueva nace con el aviso de la parecida).
        list($conversation_2, $assistant_2) = $this->conversacion();

        $segunda = $this->proponer($conversation_2, $assistant_2, $this->input_de_contado($items));

        $this->assertTrue(!empty($segunda['ok']), json_encode($segunda));

        $confirmar_2 = $this->confirmar($conversation_2, $assistant_2, $segunda['tarjeta_id']);

        $confirmar_2->assertStatus(422);
        $this->assertEquals(PropuestaVentaIaHelper::MENSAJE_DUPLICADA, $confirmar_2->json('message'));
        $this->assertEquals(PropuestaVentaIaHelper::MENSAJE_DUPLICADA, $confirmar_2->json('model.error_mensaje'));
        $this->assertEquals('propuesta', $confirmar_2->json('model.estado'));

        $this->assertEquals($ventas_despues_de_la_primera, Sale::where('user_id', $this->dueno->id)->count(), 'No hay segunda venta.');
    }

    /**
     * 🔴 NUNCA SE AUTO-CONFIRMA: con el dueño en "resuelto" la tarjeta queda propuesta, y `venta`
     * no está en AUTO_CONFIRMABLES. Si B ya registró `proponer_venta`, se prueba también por el
     * despacho real de HerramientasDeCarga.
     *
     * @test
     */
    public function con_el_dueno_en_resuelto_la_tarjeta_queda_propuesta()
    {
        $this->assertNotContains('venta', HerramientasDeCarga::AUTO_CONFIRMABLES);

        $this->dueno->agente_confianza = 'resuelto';
        $this->dueno->save();

        list($conversation, $assistant) = $this->conversacion();

        $input = $this->input_de_contado([['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1]]);

        if (HerramientasDeCarga::maneja('proponer_venta')) {

            $resultado = HerramientasDeCarga::ejecutar('proponer_venta', $input, $conversation, $assistant);

            $this->assertFalse($resultado['is_error'], $resultado['content']);

            $respuesta = json_decode($resultado['content'], true);

            $this->assertArrayNotHasKey('estado', $respuesta, 'Una respuesta con "estado" es la de confirmar_del_agente: la venta no puede haber pasado por ahí.');

        } else {

            $respuesta = $this->proponer($conversation, $assistant, $input);
        }

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertEquals('venta', $respuesta['tipo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertNull($tarjeta->resultado);
        $this->assertEquals(0, Sale::where('user_id', $this->dueno->id)->where('created_at', '>=', now()->subMinute())->where('observations', 'zz-c39')->count());
    }

    /**
     * El payload guardado tiene EXACTAMENTE las claves del POST de `vender.js`, en su orden.
     *
     * @test
     */
    public function el_payload_tiene_las_mismas_claves_que_vender_js()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1],
        ]));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $payload = AiMessageAction::find($respuesta['tarjeta_id'])->datos['payload'];

        $this->assertSame(self::CLAVES_DE_VENDER_JS, array_keys($payload));

        // Y los defaults del store para lo que la persona no dijo.
        $this->assertEquals(0, $payload['sale_type_id']);
        $this->assertEquals(0, $payload['afip_information_id']);
        $this->assertEquals(0, $payload['afip_tipo_comprobante_id']);
        $this->assertEquals(0, $payload['employee_id']);
        $this->assertEquals(0, $payload['seller_id']);
        $this->assertEquals(0, $payload['to_check']);
        $this->assertEquals(1, $payload['moneda_id']);
        $this->assertNull($payload['descuento']);
        $this->assertNull($payload['forzar_total_monto']);
        $this->assertNull($payload['fecha_entrega']);
        $this->assertEquals([], $payload['log']);
        $this->assertIsString($payload['price_description']);
        $this->assertIsArray(json_decode($payload['price_description'], true));

        // La cuenta tiene una sola sucursal: la venta sale con ella, como exige la pantalla.
        $this->assertGreaterThan(0, $payload['address_id']);
    }

    /**
     * El stock que queda en negativo se AVISA en la tarjeta (la pantalla, sin extensión, tampoco
     * rechaza); con `check_article_stock_en_vender` la pantalla no deja agregar el artículo, y acá
     * es un error.
     *
     * @test
     */
    public function el_stock_negativo_avisa_en_la_tarjeta_y_con_la_extension_de_control_rechaza()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Pinza prueba', 'cantidad' => 5],
        ]));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertStringContainsString('Descuenta stock', $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('zz-c39 Pinza prueba (hay 3, quedaría en -2)', $tarjeta->presentacion['aviso']);

        $this->dar_extension(PropuestaVentaIaHelper::EXTENSION_CHECK_STOCK);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Pinza prueba', 'cantidad' => 5],
        ]));

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('no vende sin stock', $respuesta['error']);
    }

    /**
     * Sin `sale.store` no se propone (espejo del `can` de la ruta /vender), y al ejecutar sin la
     * persona autenticada se corta con 500 antes de tocar el controller (contrato §5).
     *
     * @test
     */
    public function sin_permiso_no_propone_y_sin_persona_autenticada_no_ejecuta()
    {
        $empleado = User::create([
            'name'     => 'zz-c39 Empleado sin permiso',
            'email'    => 'c39-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->conversacion($empleado);

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1],
        ]));

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('No tenés permiso para cargar ventas desde tu usuario.', $respuesta['error']);

        // Una tarjeta del dueño, ejecutada sin nadie autenticado: 500 antes de llamar al controller.
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, $this->input_de_contado([
            ['articulo' => 'zz-c39 Martillo prueba', 'cantidad' => 1],
        ]));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        Auth::logout();

        $ventas_antes = Sale::where('user_id', $this->dueno->id)->count();

        try {
            PropuestaVentaIaHelper::ejecutar(ContextoDeCargaIa::de_la_conversacion($conversation), AiMessageAction::find($respuesta['tarjeta_id']));
            $this->fail('Tenía que cortar con AccionIaException.');
        } catch (AccionIaException $e) {
            $this->assertEquals(500, $e->status);
        }

        $this->assertEquals($ventas_antes, Sale::where('user_id', $this->dueno->id)->count());
    }
}

/**
 * La respuesta de confirmar una tarjeta, con la misma lectura tanto si vino del endpoint real
 * como de la emulación del ejecutor: `assertStatus()` y `json('model.estado')`.
 */
class RespuestaDeConfirmar
{
    /** @var int */
    protected $status;

    /** @var array */
    protected $body;

    /**
     * @param  int  $status
     * @param  mixed  $body
     */
    public function __construct($status, $body)
    {
        $this->status = (int) $status;

        // Todo a arrays, como lo dejaría json_decode de la respuesta HTTP (el cast 'object' de
        // `resultado` viaja como stdClass desde el modelo).
        $this->body = json_decode(json_encode($body), true);
    }

    /**
     * @param  int  $esperado
     * @return $this
     */
    public function assertStatus($esperado)
    {
        Assert::assertSame((int) $esperado, $this->status, 'Status de confirmar: ' . json_encode($this->body, JSON_UNESCAPED_UNICODE));

        return $this;
    }

    /**
     * @param  string|null  $clave  Ruta con puntos, o null para el cuerpo entero.
     * @return mixed
     */
    public function json($clave = null)
    {
        return is_null($clave) ? $this->body : Arr::get($this->body, $clave);
    }
}
