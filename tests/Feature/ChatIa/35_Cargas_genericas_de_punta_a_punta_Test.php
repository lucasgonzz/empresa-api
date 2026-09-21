<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Category;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\ExtencionEmpresa;
use App\Models\Pending;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-omnisciente (21/9/2026, bloque B) — las cargas genéricas de punta a punta: la
 * herramienta arma la tarjeta y `POST .../acciones/{id}/confirmar` la ejecuta POR EL CONTROLLER DE
 * LA PANTALLA.
 *
 * Corre sobre el fixture de la ferretería (TestingFerreteriaSeeder): el proveedor "Buenos Aires"
 * con sus dos descuentos, las ventas sembradas y el dueño. Lo que protege:
 *
 * - Alta de proveedor, cliente, categoría y artículo: la fila la crea el controller (el correlativo
 *   sale de num(), las cuentas corrientes de CreditAccountHelper, el precio final de setFinalPrice),
 *   y `resultado` trae nombre, ruta y `campos_que_no_quedaron` vacío.
 * - Edición de un proveedor CON descuentos, ubicado por nombre: el campo cambia, los descuentos
 *   quedan, el resto de los campos no se pisa con null (el payload es el modelo entero) y los
 *   renglones dicen "antes → después".
 * - Un registro editado entre la tarjeta y el clic corta con 409 y la tarjeta queda vencida.
 * - Baja de una categoría por CategoryController::destroy y de una venta por
 *   SaleController::destroy (la anulación de la pantalla: soft delete).
 * - La edición de un gasto ubicado por su número no le corre la fecha (toArray() serializa las
 *   fechas en ISO con Z; el payload manda la de la base).
 *
 * @group chat-ia
 */
class Cargas_genericas_de_punta_a_punta_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->service = new AsistenteIaService();

        Catalogo::olvidar();
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
            'contenido'          => 'Cargame esto',
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
     * Llama a una herramienta por el mismo camino que el loop del servicio.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param string $herramienta
     * @param array $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Confirma la tarjeta por el endpoint de la pantalla (el mensaje pasa a listo antes: la SPA
     * recién ahí la muestra).
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param int $tarjeta_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * El valor del renglón cuya etiqueta contiene el texto (la etiqueta exacta depende del esquema
     * de lectura, que puede o no estar cargado).
     *
     * @param array $presentacion
     * @param string $parte
     * @return string|null
     */
    protected function renglon(array $presentacion, $parte)
    {
        foreach ($presentacion['renglones'] as $renglon) {
            if (mb_stripos($renglon['etiqueta'], $parte) !== false) {
                return $renglon['valor'];
            }
        }

        return null;
    }

    /**
     * @test
     */
    public function el_alta_de_un_proveedor_deja_la_tarjeta_y_confirmar_lo_crea_por_el_controller()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'provider',
            'datos'   => ['name' => 'Bulonera P35', 'phone' => '351 444 5555', 'cuit' => '30-12345678-9'],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_ALTA, $respuesta['tipo']);
        $this->assertStringContainsString('Nuevo proveedor Bulonera P35', $respuesta['resumen']);
        $this->assertSame('La tarjeta queda para que la persona la confirme. No digas que ya está cargado.', $respuesta['nota']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('propuesta', $tarjeta->estado);
        $this->assertSame('alta:provider:bulonera p35', $tarjeta->clave);
        $this->assertSame('Nuevo proveedor', $tarjeta->presentacion['titulo']);
        $this->assertSame('Bulonera P35', $this->renglon($tarjeta->presentacion, 'nombre'));
        $this->assertSame('351 444 5555', $this->renglon($tarjeta->presentacion, 'fono'));
        $this->assertSame('30-12345678-9', $this->renglon($tarjeta->presentacion, 'CUIT'));
        $this->assertNull($tarjeta->presentacion['aviso']);

        // `datos` es exactamente lo que va al controller: los campos pedidos, los sí/no que la
        // pantalla manda siempre y la clave childrens que store() lee.
        $this->assertSame('provider', $tarjeta->datos['entidad']);
        $this->assertSame('alta', $tarjeta->datos['operacion']);
        $this->assertSame('Bulonera P35', $tarjeta->datos['payload']['name']);
        $this->assertSame(['name' => 'Bulonera P35', 'phone' => '351 444 5555', 'cuit' => '30-12345678-9'], $tarjeta->datos['pedidos']);
        $this->assertArrayHasKey('childrens', $tarjeta->datos['payload']);
        $this->assertArrayHasKey('price_from_cost_mas_iva', $tarjeta->datos['payload']);

        $correlativo_anterior = (int) Provider::where('user_id', $this->dueno->id)->max('num');

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);
        $this->assertSame('confirmada', $confirmacion->json('model.estado'));

        $proveedor = Provider::where('user_id', $this->dueno->id)->where('name', 'Bulonera P35')->first();

        $this->assertNotNull($proveedor, 'El proveedor tenía que quedar creado');
        $this->assertSame($correlativo_anterior + 1, (int) $proveedor->num, 'El correlativo sale de num(): pasó por ProviderController::store()');
        $this->assertSame('351 444 5555', $proveedor->phone);
        $this->assertSame('30-12345678-9', $proveedor->cuit);
        // Las cuentas corrientes las crea el controller (CreditAccountHelper), no el asistente.
        $this->assertGreaterThanOrEqual(1, DB::table('credit_accounts')->where('model_name', 'provider')->where('model_id', $proveedor->id)->count());

        $resultado = $confirmacion->json('model.resultado');

        $this->assertSame('Proveedor Bulonera P35 creado', $resultado['texto']);
        $this->assertSame('provider', $resultado['entidad']);
        $this->assertSame($proveedor->id, $resultado['id']);
        $this->assertSame('Bulonera P35', $resultado['nombre']);
        $this->assertSame('provider', $resultado['ruta']['name']);
        $this->assertSame('Ver en Proveedores', $resultado['ruta']['texto']);
        $this->assertSame([], $resultado['campos_que_no_quedaron']);
    }

    /**
     * @test
     */
    public function el_alta_de_un_cliente_pasa_por_client_controller_y_ucfirst_no_cuenta_como_campo_que_no_quedo()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'clientes',
            'datos'   => ['nombre' => 'juan pérez p35', 'email' => 'juan@p35.test', 'teléfono' => '11 5555 0000'],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        // Las etiquetas también valen como clave: se guardan como columnas.
        $this->assertSame('juan pérez p35', $tarjeta->datos['pedidos']['name']);
        $this->assertSame('11 5555 0000', $tarjeta->datos['pedidos']['phone']);
        $this->assertSame('Nuevo cliente', $tarjeta->presentacion['titulo']);

        $correlativo_anterior = (int) Client::where('user_id', $this->dueno->id)->max('num');

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $cliente = Client::where('user_id', $this->dueno->id)->where('email', 'juan@p35.test')->first();

        $this->assertNotNull($cliente);
        $this->assertSame('Juan pérez p35', $cliente->name, 'ClientController::store() hace ucfirst()');
        $this->assertSame($correlativo_anterior + 1, (int) $cliente->num);
        $this->assertGreaterThanOrEqual(1, DB::table('credit_accounts')->where('model_name', 'client')->where('model_id', $cliente->id)->count());
        $this->assertSame([], $confirmacion->json('model.resultado.campos_que_no_quedaron'), 'La mayúscula de ucfirst no es un campo que no quedó');
        $this->assertSame('client', $confirmacion->json('model.resultado.ruta.name'));
    }

    /**
     * @test
     */
    public function el_alta_de_una_categoria_con_margen_pasa_por_category_controller()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'category',
            'datos'   => ['name' => 'Bulonería P35', 'percentage_gain' => 35],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('Nueva categoría', $tarjeta->presentacion['titulo']);
        $this->assertSame('35 %', $this->renglon($tarjeta->presentacion, 'ganancia'));
        // CategoryController::store() itera price_types sin guarda: la pantalla manda [] y el genérico también.
        $this->assertSame([], $tarjeta->datos['payload']['price_types']);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $categoria = Category::where('user_id', $this->dueno->id)->where('name', 'Bulonería P35')->first();

        $this->assertNotNull($categoria);
        $this->assertGreaterThan(0, (int) $categoria->num, 'num() lo asigna el controller');
        $this->assertEquals(35, (float) $categoria->percentage_gain);
        $this->assertSame('abm', $confirmacion->json('model.resultado.ruta.name'));
        $this->assertSame(['view' => 'articulos', 'sub_view' => 'categorias'], $confirmacion->json('model.resultado.ruta.params'));
    }

    /**
     * @test
     */
    public function el_alta_de_un_articulo_resuelve_la_categoria_y_el_proveedor_por_nombre_y_el_precio_lo_calcula_el_controller()
    {
        $categoria = Category::create(['name' => 'Tornillería P35', 'user_id' => $this->dueno->id, 'num' => 900]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => [
                'name'            => 'Tornillo P35 8x40',
                'cost'            => 100,
                'percentage_gain' => 50,
                'category_id'     => 'Tornillería P35',
                'provider_id'     => TestingFerreteriaSeeder::PROVIDER_BSAS,
                'aplicar_iva'     => 'no',
                'stock_min'       => 10,
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $proveedor = Provider::where('user_id', $this->dueno->id)->where('name', TestingFerreteriaSeeder::PROVIDER_BSAS)->first();

        // Las relaciones viajan resueltas como *_id en el payload...
        $this->assertSame($categoria->id, $tarjeta->datos['payload']['category_id']);
        $this->assertSame($proveedor->id, $tarjeta->datos['payload']['provider_id']);
        $this->assertSame(0, $tarjeta->datos['payload']['aplicar_iva']);
        // ...y en la tarjeta se ven por su nombre.
        $valores = array_column($tarjeta->presentacion['renglones'], 'valor');
        $this->assertContains('Tornillería P35', $valores);
        $this->assertContains(TestingFerreteriaSeeder::PROVIDER_BSAS, $valores);
        $this->assertContains('$ 100', $valores);
        $this->assertContains('50 %', $valores);
        $this->assertContains('No', $valores);
        // Las claves que ArticleController::store() itera sin guarda.
        $this->assertSame([], $tarjeta->datos['payload']['tags']);
        $this->assertSame([], $tarjeta->datos['payload']['price_types']);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Tornillo P35 8x40')->first();

        $this->assertNotNull($articulo);
        $this->assertSame($categoria->id, (int) $articulo->category_id);
        $this->assertSame($proveedor->id, (int) $articulo->provider_id);
        $this->assertEquals(10, (float) $articulo->stock_min);
        $this->assertNotEmpty($articulo->slug, 'El slug lo escribe el controller');
        $this->assertNotNull($articulo->final_price, 'El precio final lo calcula ArticleHelper::setFinalPrice, desde el controller');
        $this->assertGreaterThan(0, (float) $articulo->final_price);
        $this->assertSame([], $confirmacion->json('model.resultado.campos_que_no_quedaron'));
        $this->assertSame('article', $confirmacion->json('model.resultado.ruta.name'));
        $this->assertSame('Artículo Tornillo P35 8x40 creado', $confirmacion->json('model.resultado.texto'));
    }

    /**
     * 🔴 El payload de la edición es el modelo entero más los cambios: un proveedor con descuentos
     * editado por nombre no los pierde y ningún otro campo se pisa con null.
     *
     * @test
     */
    public function editar_un_proveedor_con_descuentos_por_nombre_cambia_el_campo_y_no_pierde_nada()
    {
        $proveedor = Provider::where('user_id', $this->dueno->id)->where('name', TestingFerreteriaSeeder::PROVIDER_BSAS)->first();

        $this->assertGreaterThanOrEqual(2, $proveedor->provider_discounts()->count(), 'El fixture trae al proveedor con descuentos');

        $descuentos_antes = $proveedor->provider_discounts()->count();
        $margen_antes = $proveedor->percentage_gain;

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'provider',
            'registro' => TestingFerreteriaSeeder::PROVIDER_BSAS,
            'cambios'  => ['phone' => '351 555 0000'],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_EDICION, $respuesta['tipo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('Editar proveedor: ' . TestingFerreteriaSeeder::PROVIDER_BSAS, $tarjeta->presentacion['titulo']);
        $this->assertCount(1, $tarjeta->presentacion['renglones'], 'Solo lo que cambia');
        $this->assertSame('(vacío) → 351 555 0000', $tarjeta->presentacion['renglones'][0]['valor']);
        $this->assertSame('edicion:provider:' . $proveedor->id, $tarjeta->clave);
        $this->assertSame(['phone' => '351 555 0000'], $tarjeta->datos['cambios']);
        $this->assertSame($proveedor->id, $tarjeta->datos['id']);
        $this->assertNotNull($tarjeta->referencia_updated_at);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $proveedor->refresh();

        $this->assertSame('351 555 0000', $proveedor->phone);
        $this->assertSame(TestingFerreteriaSeeder::PROVIDER_BSAS, $proveedor->name);
        $this->assertEquals($margen_antes, $proveedor->percentage_gain, 'El margen no se pisó con null');
        $this->assertSame($descuentos_antes, $proveedor->provider_discounts()->count(), 'Los descuentos siguen');
        $this->assertSame('Proveedor ' . TestingFerreteriaSeeder::PROVIDER_BSAS . ' actualizado', $confirmacion->json('model.resultado.texto'));
        $this->assertSame([], $confirmacion->json('model.resultado.campos_que_no_quedaron'));
        $this->assertCount(1, $confirmacion->json('model.resultado.cambios_aplicados'));
    }

    /**
     * @test
     */
    public function editar_sin_nada_distinto_es_un_error_y_no_deja_tarjeta()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'provider',
            'registro' => TestingFerreteriaSeeder::PROVIDER_BSAS,
            'cambios'  => ['name' => TestingFerreteriaSeeder::PROVIDER_BSAS],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('ya está así', $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * 🔴 El mecanismo de tarjeta vencida: si el registro cambió después de armar la tarjeta,
     * confirmar da 409 y la tarjeta pasa a vencida sin escribir nada.
     *
     * @test
     */
    public function un_registro_editado_entre_la_tarjeta_y_el_clic_corta_con_409_y_la_tarjeta_queda_vencida()
    {
        $proveedor = Provider::create(['name' => 'Ferretera P35 carrera', 'user_id' => $this->dueno->id, 'num' => 901, 'phone' => '1']);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'provider',
            'registro' => $proveedor->id,
            'cambios'  => ['phone' => '2'],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        // Alguien lo edita desde la pantalla en el medio.
        DB::table('providers')->where('id', $proveedor->id)->update(['phone' => '3', 'updated_at' => Carbon::now()->addMinutes(2)->format('Y-m-d H:i:s')]);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(409);
        $this->assertSame('vencida', $confirmacion->json('model.estado'));
        $this->assertStringContainsString('cambió después de armar la tarjeta', $confirmacion->json('message'));
        $this->assertSame('3', $proveedor->fresh()->phone, 'No se pisó lo que cambió la pantalla');
    }

    /**
     * @test
     */
    public function la_baja_de_una_categoria_pasa_por_category_controller_destroy()
    {
        $categoria = Category::create(['name' => 'Pinturería P35', 'user_id' => $this->dueno->id, 'num' => 902]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_baja', [
            'entidad'  => 'category',
            'registro' => 'Pinturería P35',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_BAJA, $respuesta['tipo']);
        $this->assertStringContainsString('subcategorías', $respuesta['aviso']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('Borrar categoría: Pinturería P35', $tarjeta->presentacion['titulo']);
        $this->assertStringContainsString('sin categoría', $tarjeta->presentacion['aviso']);
        $this->assertSame('baja:category:' . $categoria->id, $tarjeta->clave);
        $this->assertSame(['entidad' => 'category', 'operacion' => 'baja', 'id' => $categoria->id, 'nombre' => 'Pinturería P35'], $tarjeta->datos);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);
        $this->assertNull(Category::find($categoria->id), 'Category usa soft delete: find() ya no la ve');
        $this->assertNotNull(DB::table('categories')->where('id', $categoria->id)->value('deleted_at'));
        $this->assertSame('Categoría Pinturería P35 borrada', $confirmacion->json('model.resultado.texto'));
        $this->assertNull($confirmacion->json('model.resultado.ruta'));
    }

    /**
     * @test
     */
    public function la_baja_de_una_venta_por_su_numero_pasa_por_sale_controller_destroy()
    {
        $venta = Sale::where('user_id', $this->dueno->id)
                    ->whereNull('deleted_at')
                    ->where('is_consolidacion_facturacion', 0)
                    ->orderBy('id', 'DESC')
                    ->first();

        $this->assertNotNull($venta, 'El fixture trae ventas');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_baja', [
            'entidad'  => 'sale',
            'registro' => (int) $venta->num,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('Borrar venta: Venta N° ' . $venta->num, $tarjeta->presentacion['titulo']);
        $this->assertStringContainsString('Se anula la venta', $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('vuelve al stock', $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('NO se compensa', $tarjeta->presentacion['aviso']);
        $this->assertSame((int) $venta->id, $tarjeta->datos['id']);
        $this->assertSame('Venta N° ' . $venta->num, $this->renglon($tarjeta->presentacion, 'venta'));
        $this->assertNotNull($this->renglon($tarjeta->presentacion, 'Total'));

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);
        $this->assertNotNull(DB::table('sales')->where('id', $venta->id)->value('deleted_at'), 'SaleController::destroy hace soft delete');
        $this->assertSame('Venta N° ' . $venta->num . ' anulada', $confirmacion->json('model.resultado.texto'));
        $this->assertNull($confirmacion->json('model.resultado.ruta'));

        // Una venta se ubica por número, no por texto.
        $por_texto = $this->herramienta($conversation, $assistant, 'proponer_baja', ['entidad' => 'sale', 'registro' => 'la de ayer']);
        $this->assertFalse($por_texto['ok']);
        $this->assertStringContainsString('número', $por_texto['error']);
    }

    /**
     * @test
     */
    public function la_baja_de_una_tarea_por_su_detalle_pasa_por_pending_controller_destroy()
    {
        $tarea = Pending::create([
            'detalle'           => 'Llamar al contador P35',
            'fecha_realizacion' => Carbon::today()->addDays(3)->format('Y-m-d'),
            'user_id'           => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_baja', [
            'entidad'  => 'pending',
            'registro' => 'llamar al contador',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('Borrar tarea de la agenda: Llamar al contador P35', $tarjeta->presentacion['titulo']);
        $this->assertSame($tarea->id, $tarjeta->datos['id']);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertSame(0, DB::table('pendings')->where('id', $tarea->id)->count(), 'PendingController::destroy la borra');

        // Crearla o cambiarla por acá no: tienen su herramienta.
        $alta = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'pending', 'datos' => ['detalle' => 'x']]);
        $this->assertFalse($alta['ok']);
        $this->assertStringContainsString('proponer_tarea', $alta['error']);
    }

    /**
     * 🔴 toArray() serializa las fechas en ISO con Z; ExpenseController::update() reasigna
     * created_at desde el request. El payload manda la fecha como está en la base.
     *
     * @test
     */
    public function editar_un_gasto_por_su_numero_no_le_corre_la_fecha()
    {
        $concepto = ExpenseConcept::where('user_id', $this->dueno->id)->first();

        $gasto = Expense::create([
            'num'                => 950,
            'expense_concept_id' => $concepto->id,
            'amount'             => 1200,
            'moneda_id'          => 1,
            'user_id'            => $this->dueno->id,
            'created_at'         => '2026-09-10 15:30:00',
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'gastos',
            'registro' => 950,
            'cambios'  => ['observations' => 'Editado desde el chat P35'],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('Editar gasto: Gasto N° 950', $tarjeta->presentacion['titulo']);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $fila = DB::table('expenses')->where('id', $gasto->id)->first();

        $this->assertSame('Editado desde el chat P35', $fila->observations);
        $this->assertSame('2026-09-10 15:30:00', $fila->created_at, 'La fecha no se corrió tres horas');
        $this->assertEquals(1200, (float) $fila->amount);
        $this->assertSame((int) $concepto->id, (int) $fila->expense_concept_id);
        $this->assertSame('Gasto N° 950 actualizado', $confirmacion->json('model.resultado.texto'));

        // Y la fecha SÍ se puede cambiar (es lo que edita la pantalla de Gastos): queda ese día.
        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'expense',
            'registro' => 950,
            'cambios'  => ['created_at' => '2026-09-12'],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('10/09/2026 → 12/09/2026', AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['renglones'][0]['valor']);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertSame('2026-09-12', substr(DB::table('expenses')->where('id', $gasto->id)->value('created_at'), 0, 10));
    }
}
