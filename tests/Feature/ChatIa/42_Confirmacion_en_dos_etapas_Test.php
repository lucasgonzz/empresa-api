<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Brand;
use App\Models\ExtencionEmpresa;
use App\Models\Pending;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-omnisciente — arreglo de los 🔴 2 y 🔴 3 del chequeo adversarial: confirmar una
 * tarjeta del ABM genérico o una venta ya NO corre adentro de la transacción del ejecutor.
 *
 * Lo que había: `EjecutorAccionesIaHelper::ejecutar_confirmacion()` envolvía todo en un
 * `DB::transaction`, y adentro de esa transacción corrían el HTTP síncrono a Tienda Nube de
 * `CategoryController` y el `SaleController::store()` entero — cuyo `DB::commit()`, anidado, sólo
 * decrementa el contador. O sea que el `RELEASE_LOCK` del candado anti-duplicados, el
 * `SendSaleWhatsappJob` y el evento de la demo salían ANTES de que la venta existiera para nadie
 * más: el escenario de la venta duplicada que ese candado existe para impedir.
 *
 * Lo que protege este archivo:
 *
 * - Que mientras corre la carga NO haya ninguna transacción abierta por el ejecutor, ni para la
 *   venta ni para el ABM genérico — y que para los demás tipos siga habiéndola, o sea que el
 *   cambio es SOLO para los cuatro nuevos.
 * - Que el doble clic siga sin ejecutar dos veces, ahora con la reserva 'en_curso' en lugar del
 *   `lockForUpdate` sostenido, y que el segundo clic siga siendo un 409.
 * - Que un rechazo del controller deje la tarjeta con su `error_mensaje`, de vuelta en
 *   'propuesta', y nada escrito.
 *
 * ⚠️ LO QUE ESTE ARCHIVO NO PUEDE PROBAR, dicho para que nadie lo lea de más: la suite corre con
 * `DatabaseTransactions`, así que TODO el test vive adentro de una transacción que nunca comitea.
 * Acá no se puede demostrar que la venta esté comiteada cuando se sueltan sus efectos — eso, en
 * una corrida de test, es falso para los dos caminos. Lo que sí se mide, y es la causa del
 * defecto, es el NIVEL de anidamiento: con el arreglo, la transacción de `SaleController` es la
 * única que el confirmar abre (nivel base + 1), así que en producción —donde no hay wrapper de
 * test— su `DB::commit()` es un commit de verdad. Antes era nivel base + 2 y sólo decrementaba.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 *
 * @group chat-ia
 */
class Confirmacion_en_dos_etapas_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

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
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Andamiaje
    // ---------------------------------------------------------------------

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
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $herramienta
     * @param  array  $input
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
     * Deja el assistant 'listo' y confirma la tarjeta por el endpoint real.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  int  $tarjeta_id
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
     * Un artículo del dueño con precio, costo y stock.
     *
     * @param  string  $nombre
     * @param  float  $precio
     * @param  float  $stock
     * @return Article
     */
    protected function articulo_de_prueba($nombre, $precio, $stock)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => $precio,
            'cost'        => $precio / 2,
            'stock'       => $stock,
            'iva_id'      => 2,
        ]);
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    /**
     * 🔴 LA VENTA CORRE CON LA TRANSACCIÓN DEL EJECUTOR YA CERRADA.
     *
     * La única transacción que abre el confirmar mientras la venta se registra es la de
     * `SaleController::store()`: nivel base + 1. Con el código anterior eran dos (la del ejecutor
     * y la del controller adentro), y por eso el `DB::commit()` de `store()` no comiteaba nada y
     * el candado se soltaba sobre una venta que todavía no existía.
     *
     * @test
     */
    public function la_venta_corre_con_la_transaccion_del_ejecutor_cerrada()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        $martillo = $this->articulo_de_prueba('zz-c42 Martillo dos etapas', 1000, 10);

        list($conversation, $assistant) = $this->conversacion();

        $propuesta = PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'items'          => [['articulo_id' => $martillo->id, 'cantidad' => 2]],
            'cobro'          => 'contado',
            'metodo_de_pago' => 'Transferencia',
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ]);

        $this->assertTrue(!empty($propuesta['ok']), 'La propuesta tenía que quedar armada: ' . json_encode($propuesta));

        $base = (int) DB::transactionLevel();

        // El nivel en el instante exacto en que se escribe la fila de `sales`, que es adentro de
        // la transacción que abrió `SaleController::store()`. Si esa transacción es la primera que
        // abre el confirmar, el nivel es base + 1 y su `DB::commit()` comitea de verdad (en
        // producción, donde el `base` es 0). Si el ejecutor tuviera la suya abierta, sería base + 2
        // y ese commit sólo bajaría el contador: es el defecto que este arreglo cierra.
        $nivel_de_la_venta = null;

        Sale::creating(function () use (&$nivel_de_la_venta) {
            if (is_null($nivel_de_la_venta)) {
                $nivel_de_la_venta = (int) DB::transactionLevel();
            }
        });

        $movimientos_antes = $this->max_id_movimiento_caja();

        $respuesta = $this->confirmar($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $respuesta->assertStatus(200);
        $this->assertEquals('confirmada', $respuesta->json('model.estado'));

        $venta = Sale::find($respuesta->json('model.resultado.venta_id'));

        $this->assertNotNull($venta, 'La venta tenía que quedar registrada');
        $this->ventas_creadas_por_escenarios[] = (int) $venta->id;
        $this->assertEquals((int) $venta->num, (int) $respuesta->json('model.resultado.numero'));

        $this->assertSame(
            $base + 1,
            $nivel_de_la_venta,
            'La transacción de SaleController tiene que ser la única que abre el confirmar: si hay una del ejecutor arriba, su DB::commit() no comitea y el candado se suelta sobre una venta que no existe.'
        );

        // Y no queda nada abierto después.
        $this->assertSame($base, (int) DB::transactionLevel());
    }

    /**
     * 🔴 UNA CARGA GENÉRICA TAMBIÉN, Y UNA TAREA —que no es de las nuevas— SIGUE COMO ESTABA.
     *
     * Es la prueba de que el camino de dos etapas es sólo para los cuatro tipos nuevos: se mira el
     * nivel de transacción en el momento exacto en que cada carga escribe su fila.
     *
     * @test
     */
    public function la_carga_generica_corre_sin_transaccion_del_ejecutor_y_la_tarea_sigue_adentro()
    {
        $base = (int) DB::transactionLevel();

        $nivel_del_alta = null;

        Brand::creating(function () use (&$nivel_del_alta) {
            $nivel_del_alta = (int) DB::transactionLevel();
        });

        list($conversation, $assistant) = $this->conversacion();

        $alta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'brand',
            'datos'   => ['name' => 'zz-c42 Marca de dos etapas'],
        ]);

        $this->assertTrue($alta['ok'], json_encode($alta));

        $this->confirmar($conversation, $assistant, $alta['tarjeta_id'])->assertStatus(200);

        $this->assertSame($base, $nivel_del_alta, 'El alta genérica no puede correr adentro de una transacción del ejecutor.');

        // Y el camino de siempre, intacto: la tarea se escribe con la transacción del ejecutor abierta.
        $nivel_de_la_tarea = null;

        Pending::creating(function () use (&$nivel_de_la_tarea) {
            $nivel_de_la_tarea = (int) DB::transactionLevel();
        });

        list($conversation2, $assistant2) = $this->conversacion();

        $tarea = $this->herramienta($conversation2, $assistant2, 'proponer_tarea', [
            'detalle' => 'zz-c42 Llamar al contador',
            'fecha'   => Carbon::tomorrow()->format('Y-m-d'),
        ]);

        $this->assertTrue($tarea['ok'], json_encode($tarea));

        $this->confirmar($conversation2, $assistant2, $tarea['tarjeta_id'])->assertStatus(200);

        $this->assertSame($base + 1, $nivel_de_la_tarea, 'La tarea tiene que seguir corriendo adentro de la transacción del ejecutor.');
    }

    /**
     * 🔴 EL DOBLE CLIC SIGUE SIN EJECUTAR DOS VECES, y el segundo sigue siendo un 409.
     *
     * El segundo clic entra MIENTRAS el primero ejecuta: se dispara desde el evento `created` del
     * modelo que está creando el controller, que es el único momento en que las dos
     * confirmaciones se superponen de verdad en un test de un solo proceso. Ve la tarjeta
     * reservada ('en_curso') y sale sin ejecutar nada.
     *
     * En producción los dos clics son dos requests con dos conexiones: el segundo espera el
     * candado de fila hasta que la etapa 1 del primero comitea la reserva, y entonces ve lo mismo
     * que ve acá. La reserva se comitea ANTES de ejecutar justamente para eso.
     *
     * @test
     */
    public function un_doble_clic_sobre_una_tarjeta_generica_no_ejecuta_dos_veces()
    {
        list($conversation, $assistant) = $this->conversacion();

        $alta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'brand',
            'datos'   => ['name' => 'zz-c42 Marca del doble clic'],
        ]);

        $this->assertTrue($alta['ok'], json_encode($alta));

        $tarjeta_id = (int) $alta['tarjeta_id'];
        $segundo_clic = null;

        Brand::created(function () use (&$segundo_clic, $conversation, $tarjeta_id) {

            if (!is_null($segundo_clic)) {
                return;
            }

            $segundo_clic = EjecutorAccionesIaHelper::confirmar($conversation, $tarjeta_id, Auth::user(), function () {
                return 1;
            });
        });

        $primero = $this->confirmar($conversation, $assistant, $tarjeta_id);

        $primero->assertStatus(200);
        $this->assertEquals('confirmada', $primero->json('model.estado'));

        $this->assertNotNull($segundo_clic, 'El segundo clic no llegó a correr: el test no probó nada.');
        $this->assertSame(409, $segundo_clic['status']);
        $this->assertSame('accion_resuelta', $segundo_clic['body']['code']);
        $this->assertSame(EjecutorAccionesIaHelper::MENSAJE_EN_CURSO, $segundo_clic['body']['message']);

        $this->assertSame(
            1,
            DB::table('brands')->where('user_id', $this->dueno->id)->where('name', 'zz-c42 Marca del doble clic')->count(),
            'La marca se creó dos veces: el candado contra el segundo clic no funcionó.'
        );
    }

    /**
     * 🔴 UN RECHAZO DEL CONTROLLER DEJA LA TARJETA CON SU ERROR, DE VUELTA EN 'propuesta', Y NADA
     * ESCRITO.
     *
     * El rechazo se simula desde `creating` —o sea, antes del INSERT— con la misma excepción que
     * tira un controller que corta por su cuenta (`HttpResponseException`, lo que hace un
     * `abort()` o un `validate()` fallado). Lo que se prueba es el camino del ejecutor, que es el
     * que cambió: `llamar_al_controller` la traduce a un 422, la reserva se suelta y el motivo
     * queda guardado afuera de toda transacción.
     *
     * El mismo camino con un controller de verdad está en el test 39 (el 422 del límite de crédito
     * de `SaleController::store`), que sigue verde con este cambio.
     *
     * @test
     */
    public function un_rechazo_del_controller_deja_la_tarjeta_con_su_error_y_nada_escrito()
    {
        list($conversation, $assistant) = $this->conversacion();

        $alta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'brand',
            'datos'   => ['name' => 'zz-c42 Marca rechazada'],
        ]);

        $this->assertTrue($alta['ok'], json_encode($alta));

        Brand::creating(function () {
            throw new HttpResponseException(response()->json(['message' => 'La pantalla no la quiso'], 422));
        });

        $respuesta = $this->confirmar($conversation, $assistant, $alta['tarjeta_id']);

        $respuesta->assertStatus(422);
        $this->assertSame('La pantalla no la quiso', $respuesta->json('message'));

        $tarjeta = AiMessageAction::find($alta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado(), 'La tarjeta tiene que volver a ser confirmable.');
        $this->assertSame('La pantalla no la quiso', $tarjeta->error_mensaje);
        $this->assertNull($tarjeta->resultado);
        $this->assertNull($tarjeta->resuelta_at);

        $this->assertSame(
            0,
            DB::table('brands')->where('user_id', $this->dueno->id)->where('name', 'zz-c42 Marca rechazada')->count(),
            'Un rechazo no puede dejar nada escrito.'
        );
    }

    /**
     * Los tipos que van por el camino de dos etapas son exactamente los que llaman a un controller
     * con transacción propia, y ninguno de los viejos. Si mañana se agrega un tipo que llama a un
     * controller, este test recuerda dónde se decide.
     *
     * ⚠️ La lista se toca SOLO cuando se suma un tipo a propósito. El 22/9/2026 (misión
     * asistente-capacidades-y-hilos) entró el PRESUPUESTO, por el mismo motivo que los otros
     * cuatro: `BudgetController::store()` abre su propia transacción con `DB::beginTransaction()`
     * —que anidada sólo decrementa el contador— y emite `sendAddModelNotification`. Las dos cargas
     * de stock de esa misma misión NO entraron, y eso también es una decisión: ni
     * `StockMovementController::crear()` ni `ArticleController::update_addresses_stock()` abren
     * transacción propia, y lo único que despachan son filas de cola.
     *
     * @test
     */
    public function los_tipos_de_dos_etapas_son_exactamente_los_que_llaman_a_un_controller()
    {
        $this->assertSame(
            [
                AiMessageAction::TIPO_ALTA,
                AiMessageAction::TIPO_EDICION,
                AiMessageAction::TIPO_BAJA,
                AiMessageAction::TIPO_VENTA,
                AiMessageAction::TIPO_PRESUPUESTO,
            ],
            EjecutorAccionesIaHelper::TIPOS_DE_DOS_ETAPAS
        );

        $viejos = [
            AiMessageAction::TIPO_GASTO,
            AiMessageAction::TIPO_PAGO,
            AiMessageAction::TIPO_TAREA_NUEVA,
            AiMessageAction::TIPO_TAREA_EDITAR,
            AiMessageAction::TIPO_TAREA_COMPLETAR,
            AiMessageAction::TIPO_COMBO,
            AiMessageAction::TIPO_OFERTA,
            AiMessageAction::TIPO_COMPRA_CON_FACTURA,
            AiMessageAction::TIPO_FOTO_SUCURSAL,
            AiMessageAction::TIPO_IMAGENES_CATEGORIAS,
            AiMessageAction::TIPO_IMAGEN_CATEGORIA,
            AiMessageAction::TIPO_IMAGENES_ARTICULOS,
            AiMessageAction::TIPO_ACTUALIZACION_MASIVA,
            AiMessageAction::TIPO_DISENO_PDF,
        ];

        foreach ($viejos as $tipo) {
            $this->assertNotContains($tipo, EjecutorAccionesIaHelper::TIPOS_DE_DOS_ETAPAS, $tipo);
        }
    }
}
