<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PermissionEmpresa;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116, rama A, M6-descuento — descuento % por renglón en proponer_venta.
 *
 * 🔴 EL PEDIDO REAL (Ferre Total, 14/11/2026): «a este hacele 15 %». En Vender es la columna `discount`
 * del remito; el asistente solo tenía el descuento de venta del catálogo, sobre el total.
 *
 * 🔴 LA ASERCIÓN DE PLATA: el total de la tarjeta y el de la venta guardada son exactamente lo que da
 * Vender —getTotalItem(): `t = precio × cant; t -= t × d / 100`, sumado y redondeado—, y el renglón
 * queda en `article_sale.discount` por el mismo camino (SaleController::store → attachArticle).
 *
 * Corre sobre el fixture de plata (TestingFerreteriaSeeder: Efectivo con 10 % de descuento del método).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class T152_Descuento_por_renglon_en_la_venta_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** @var User */
    protected $dueno;

    /** @var Article Precio 2.000. */
    protected $martillo;

    /** @var Article Precio 1.234,56. */
    protected $pinza;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'agentes_ia']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $this->martillo = Article::create(['name' => 'zz-t152 Martillo', 'user_id' => $this->dueno->id, 'status' => 'active', 'final_price' => 2000, 'cost' => 1000, 'stock' => 50, 'iva_id' => 2]);
        $this->pinza = Article::create(['name' => 'zz-t152 Pinza', 'user_id' => $this->dueno->id, 'status' => 'active', 'final_price' => 1234.56, 'cost' => 600, 'stock' => 50, 'iva_id' => 2]);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * El total de Vender para precios unitarios, cantidades y descuentos: getTotalItem() de
     * src/mixins/model_functions.js, escrito acá aparte del código que se prueba.
     *
     * @param  array  $renglones  [[precio, cantidad, descuento], ...]
     * @return float
     */
    protected function total_de_vender(array $renglones)
    {
        $suma = 0;

        foreach ($renglones as $r) {
            $t = $r[0] * $r[1];

            if ($r[2]) {
                $t -= $t * $r[2] / 100;
            }

            $suma += $t;
        }

        return round($suma, 2);
    }

    /**
     * @param  User  $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion(User $persona)
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $persona->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Vendé 3 martillos zz-t152 con 15 % y 2 pinzas con 10 %, en efectivo a la caja efectivo', 'estado' => 'listo']);

        $assistant = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        return [$conversation, $assistant];
    }

    protected function input()
    {
        return [
            'items' => [
                ['articulo_id' => $this->martillo->id, 'cantidad' => 3, 'descuento_porcentaje' => 15],
                ['articulo_id' => $this->pinza->id, 'cantidad' => 2, 'descuento_porcentaje' => 10],
            ],
            'cobro'          => 'contado',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ];
    }

    /**
     * 🔴 La plata: tarjeta, venta guardada y renglones dan lo mismo que Vender.
     *
     * @test
     */
    public function el_total_con_descuento_por_renglon_es_el_de_vender_y_se_guarda_en_el_renglon()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        list($conversation, $assistant) = $this->conversacion($this->dueno);

        $respuesta = PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $this->input());

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::findOrFail($respuesta['tarjeta_id']);
        $resumen = $tarjeta->datos['resumen'];
        $payload = $tarjeta->datos['payload'];

        // Los precios unitarios ya traen el 10 % de Efectivo (como Vender), y el renglón su propio %.
        $precio_martillo = round(2000 - 2000 * TestingFerreteriaSeeder::DESCUENTO_EFECTIVO / 100, 2);
        $precio_pinza = round(1234.56 - 1234.56 * TestingFerreteriaSeeder::DESCUENTO_EFECTIVO / 100, 2);

        $esperado = $this->total_de_vender([[$precio_martillo, 3, 15], [$precio_pinza, 2, 10]]);

        $this->assertEquals($esperado, $resumen['total']);
        $this->assertEquals($esperado, $payload['total']);
        $this->assertEquals(15.0, $resumen['articulos'][0]['descuento']);
        $this->assertEquals(round($precio_martillo * 3 * 0.85, 2), $resumen['articulos'][0]['subtotal']);
        $this->assertEquals(15.0, $payload['items'][0]['discount']);
        $this->assertEquals(10.0, $payload['items'][1]['discount']);

        // La tarjeta muestra el descuento del renglón.
        $filas = array_column($tarjeta->presentacion['renglones'], 'valor');
        $this->assertStringContainsString('− 15 %', $filas[0]);

        $assistant->contenido = 'Te dejé la tarjeta.';
        $assistant->estado = 'listo';
        $assistant->save();

        $movimientos_antes = $this->max_id_movimiento_caja();
        $http = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta->id . '/confirmar');
        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $this->assertSame(200, $http->getStatusCode(), json_encode($http->json()));

        $venta_id = (int) $http->json('model.resultado.venta_id');
        $this->ventas_creadas_por_escenarios[] = $venta_id;

        $venta = Sale::findOrFail($venta_id);

        $this->assertEquals($esperado, round((float) $venta->total, 2), 'La venta guardada tiene el total de Vender.');

        $renglones = DB::table('article_sale')->where('sale_id', $venta_id)->orderBy('id')->get(['article_id', 'price', 'amount', 'discount']);

        $this->assertEquals(15.0, (float) $renglones[0]->discount);
        $this->assertEquals(10.0, (float) $renglones[1]->discount);
        $this->assertEquals($precio_martillo, (float) $renglones[0]->price, 'El precio queda sin el descuento del renglón, como en Vender.');

        // Total = Σ(precio × cant × (1 − d)) sobre lo GUARDADO: cuadra con la venta.
        $de_los_renglones = 0.0;
        foreach ($renglones as $r) {
            $de_los_renglones += (float) $r->price * (float) $r->amount * (1 - (float) $r->discount / 100);
        }

        $this->assertEquals(round((float) $venta->total, 2), round($de_los_renglones, 2));
    }

    /**
     * Sin el permiso de la columna de descuento del remito, no se arma la tarjeta.
     *
     * @test
     */
    public function sin_el_permiso_de_descuento_por_articulo_no_se_arma()
    {
        $empleado = User::create([
            'name'       => 'zz-t152 Empleado',
            'email'      => 't152-empleado-' . uniqid() . '@test.local',
            'doc_number' => 'DOC-T152-' . uniqid(),
            'password'   => Hash::make('otra'),
            'owner_id'   => $this->dueno->id,
        ]);

        $permiso = PermissionEmpresa::where('slug', PropuestaVentaIaHelper::PERMISO)->first();

        if (is_null($permiso)) {
            $permiso = PermissionEmpresa::forceCreate(['name' => 'zz-t152 venta', 'slug' => PropuestaVentaIaHelper::PERMISO, 'model_name' => 'sale']);
        }

        $empleado->permissions()->sync([$permiso->id]);
        $empleado->load('permissions');

        list($conversation, $assistant) = $this->conversacion($empleado);

        $respuesta = PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $this->input());

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('descuento a cada artículo', json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * Revisión independiente: el mismo artículo dos veces con descuentos distintos no se funde (se pregunta);
     * con el mismo descuento sí se funde y el total lo respeta.
     *
     * @test
     */
    public function el_mismo_articulo_con_descuentos_distintos_no_se_funde()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        list($conversation, $assistant) = $this->conversacion($this->dueno);

        $input = $this->input();
        $input['items'] = [
            ['articulo_id' => $this->martillo->id, 'cantidad' => 2, 'descuento_porcentaje' => 15],
            ['articulo_id' => $this->martillo->id, 'cantidad' => 1],
        ];

        $respuesta = PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $input);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('descuentos distintos', json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());

        $input['items'][1]['descuento_porcentaje'] = 15;

        $igual = PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $input);

        $this->assertTrue(!empty($igual['ok']), json_encode($igual));

        $resumen = AiMessageAction::findOrFail($igual['tarjeta_id'])->datos['resumen'];
        $precio = round(2000 - 2000 * TestingFerreteriaSeeder::DESCUENTO_EFECTIVO / 100, 2);

        $this->assertCount(1, $resumen['articulos']);
        $this->assertEquals(3.0, $resumen['articulos'][0]['cantidad']);
        $this->assertEquals($this->total_de_vender([[$precio, 3, 15]]), $resumen['total']);
    }

    /**
     * Un descuento fuera de rango es un error; 0 es sin descuento.
     *
     * @test
     */
    public function un_descuento_fuera_de_rango_da_error_y_cero_es_sin_descuento()
    {
        list($conversation, $assistant) = $this->conversacion($this->dueno);

        $input = $this->input();
        $input['items'][0]['descuento_porcentaje'] = 120;

        $respuesta = PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $input);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('entre 0 y 100', json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        $input['items'][0]['descuento_porcentaje'] = 0;
        $input['items'][1]['descuento_porcentaje'] = 0;

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        $sin = PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $input);

        $this->assertTrue(!empty($sin['ok']), json_encode($sin));
        $this->assertNull(AiMessageAction::findOrFail($sin['tarjeta_id'])->datos['payload']['items'][0]['discount']);
    }
}
