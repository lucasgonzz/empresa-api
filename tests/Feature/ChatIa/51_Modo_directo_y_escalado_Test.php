<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Category;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-capacidades-y-hilos (22/9/2026) — P1 (el modo directo) y P2 (las cargas contra
 * el modelo Profundo).
 *
 * 🔴 DE DÓNDE SALE ESTE ARCHIVO. El 22/9/2026, en demo3, Lucas le pidió al agente TRES VECES
 * explícitas que dejara de pedirle confirmación ("Si! No quiero que me pidas tanta confirmación,
 * crea el proveedor y la compra ya") y el mensaje siguiente del agente fue "Quedó pendiente de tu
 * confirmación… Decime que sí y lo creo". Y lo más grave: de las cuatro cargas que ese día dijo
 * haber registrado, NINGUNA existía en la base (cero ventas, cero compras, cero proveedores). Dos
 * defectos distintos que se agravan juntos: confirmar de más, y mentir sobre lo que pasó.
 *
 * Lo que protege:
 *
 *  - Con el dueño en "directo", una VENTA y un PAGO se ejecutan en el acto, sin tarjeta, y el
 *    número que viaja al modelo es el REAL —el que devolvió la ejecución—, no uno redactado.
 *  - 🔴 Una BAJA, una ACTUALIZACIÓN MASIVA y la unificación de bancos siguen dejando tarjeta en
 *    "directo": las tres guardas (la lista del modo, el filtro de NUNCA_AUTO_CONFIRMABLES y el
 *    `case` sin la puerta) y el comportamiento de punta a punta.
 *  - Un error de ejecución llega al modelo con SU MOTIVO REAL (el 422 del límite de crédito), y no
 *    como una propuesta muda que el modelo pueda contar como quiera.
 *  - La auto-confirmación del modo directo NO choca con la guarda MENSAJE_MISMO_TURNO, y esa guarda
 *    sigue intacta para la confirmación por texto.
 *  - El prompt dice lo contrario según el modo, y las dos reglas contra lo inventado van en los tres.
 *  - El escalado de modelo: una consulta de solo lectura se queda en el Ágil; en cuanto el turno
 *    pide una tool de carga, las vueltas siguientes van al Profundo con su `thinking` y su techo; y
 *    con una foto en el pedido manda la VISIÓN por encima del escalado (el Pro de DeepSeek no ve
 *    imágenes y no lo avisa con un error).
 *
 * 🔴 Ningún test sale a la red: Http::fake en todos los que llaman al loop, y las claves son de
 * prueba.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Modo_directo_y_escalado_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta para comparar montos. */
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var array<int,int> Ids de credit_accounts cuyo limite_credito se tocó, con su valor original. */
    protected $limites_a_restaurar = [];

    /** @var array<int,ExtencionEmpresa> Extensiones enganchadas por este archivo. */
    protected $extensiones_enganchadas = [];

    /**
     * El `agente_confianza` que el dueño tenía ANTES de que este archivo lo tocara, para devolverlo
     * en tearDown().
     *
     * 🔴 POR QUÉ SE RESTAURA A MANO Y NO SE CONFÍA EN LA TRANSACCIÓN. `agente_confianza` es un
     * INTERRUPTOR GLOBAL de la cuenta, y este archivo lo mueve en casi todos sus tests. Si una
     * corrida se corta a la mitad —o el rollback de DatabaseTransactions no alcanza por lo que
     * sea— la columna queda en "directo" y contamina a cualquier suite posterior que comparta el
     * fixture. No es hipotético: `tests/Feature/CurrentAcount/4_Pago_por_helper_Test` (fuera del
     * filtro ChatIa, así que nadie lo ve) asume que `proponer_pago` SOLO PROPONE, y con la columna
     * en "directo" la carga se auto-ejecuta en el mismo turno y ese archivo se pone rojo por algo
     * que no tiene nada que ver con él. Dos líneas de tearDown evitan una tarde de diagnóstico.
     *
     * @var string|null
     */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * 🔴 Nunca las claves reales del .env.testing: este archivo no sale a la red. Los ids de
         * modelo son inventados a propósito, para que las aserciones prueben el CABLEADO
         * (config → payload) y no el default de config/services.php.
         */
        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-p51',
            'services.anthropic.model'          => 'claude-general-p51',
            'services.anthropic.model_agil'     => 'claude-agil-p51',
            'services.anthropic.model_profundo' => 'claude-profundo-p51',
            'services.deepseek.api_key'         => 'clave-deepseek-de-prueba-p51',
            'services.deepseek.model'           => 'deepseek-general-p51',
            'services.deepseek.model_agil'      => 'deepseek-flash-p51',
            'services.deepseek.model_profundo'  => 'deepseek-pro-p51',
            'services.deepseek.model_vision'    => 'deepseek-vision-p51',
        ]);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->confianza_original = $this->dueno->agente_confianza;

        $this->dar_extension('asistente_ia');
    }

    protected function tearDown(): void
    {
        /* Lo primero: el interruptor global vuelve a como estaba (ver $confianza_original). */
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

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
     * Engancha una extensión al dueño (creando la fila si la base no la tiene).
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
     * Deja al dueño en un modo de confianza, guardado en la base.
     *
     * 🔴 NO se confía en que la transacción del test lo revierta: `agente_confianza` es un
     * interruptor GLOBAL de la cuenta y el tearDown de este archivo lo devuelve explícitamente a
     * como estaba (ver $confianza_original). Es una columna que, si se escapa, ensucia suites
     * ajenas que ni siquiera están en este filtro.
     *
     * @param  string  $modo
     * @return void
     */
    protected function dueno_en($modo)
    {
        $this->dueno->agente_confianza = $modo;
        $this->dueno->save();
    }

    /**
     * Vuelve a autenticar al dueño en el guard `web` después de una request HTTP: sanctum queda
     * como guard por defecto y la auto-confirmación hace Auth::setUser() sobre el default.
     *
     * @return void
     */
    protected function actuar_como_el_dueno()
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($this->dueno, 'web');
    }

    /**
     * Un artículo del dueño con precio, costo y stock fijos.
     *
     * @param  string  $nombre
     * @param  float  $precio
     * @param  float  $costo
     * @param  float  $stock
     * @return Article
     */
    protected function articulo_de_prueba($nombre, $precio = 2000, $costo = 1000, $stock = 10)
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
     * Conversación del dueño con su assistant 'pendiente' y las acciones habilitadas.
     *
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido = 'Hacelo ya, sin preguntarme')
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
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
     * Ejecuta una herramienta de carga por el despacho real y devuelve lo que ve el modelo.
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
     * El bloque del `case` de una herramienta en HerramientasDeCarga, leído como texto plano (igual
     * que los tests 26 y 36: el switch no es introspectable de otra forma).
     *
     * @param  string  $herramienta
     * @return string
     */
    protected function bloque_del_case($herramienta)
    {
        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        $desde = strpos($contenido, "case '" . $herramienta . "':");

        $this->assertNotFalse($desde, $herramienta . ' no se despacha');

        $hasta = strpos($contenido, 'case ', $desde + 10);

        return substr($contenido, $desde, $hasta - $desde);
    }

    // ---------------------------------------------------------------------
    // P1 · El modo directo
    // ---------------------------------------------------------------------

    /**
     * 🔴 EN "DIRECTO" LA VENTA SE HACE SOLA, Y EL NÚMERO ES EL DE VERDAD.
     *
     * Las dos mitades del mismo test a propósito: que se ejecute sin tarjeta no sirve de nada si
     * después el modelo puede anunciar un número inventado (el 22/9 anunció "la venta número 4882",
     * que existe pero es del 28/10/2025). Lo único que el modelo recibe es `resultado`, y este test
     * lo cruza contra el `num` de la venta que quedó en la base.
     *
     * @test
     */
    public function en_directo_una_venta_se_ejecuta_sin_tarjeta_y_el_numero_es_el_de_la_venta_real()
    {
        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $martillo = $this->articulo_de_prueba('zz-p51 Martillo directo');

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $this->actuar_como_el_dueno();

        $ventas_antes = Sale::where('user_id', $this->dueno->id)->count();

        list($conversation, $assistant) = $this->conversacion('Vendé 2 martillos y no me preguntes');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_venta', [
            'items'          => [['articulo' => 'zz-p51 Martillo directo', 'cantidad' => 2]],
            'cobro'          => 'contado',
            'metodo_de_pago' => 'Transferencia',
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        /* La respuesta es la de confirmar_del_agente: trae `estado` y `resultado`, no una tarjeta a confirmar. */
        $this->assertSame('confirmada', $respuesta['estado'], 'La venta quedó propuesta: el modo directo no ejecutó.');
        $this->assertSame(ConfirmacionPorTextoIaHelper::NOTA_EJECUTADA, $respuesta['nota']);

        $this->assertSame($ventas_antes + 1, Sale::where('user_id', $this->dueno->id)->count(), 'No se creó la venta.');

        $venta = Sale::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        $this->ventas_creadas_por_escenarios[] = (int) $venta->id;

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $tarjeta->estado_guardado());
        $this->assertSame((int) $venta->id, (int) $tarjeta->resultado->venta_id);

        /*
         * 🔴 LA ASERCIÓN QUE IMPORTA: el número que el modelo tiene para contar es el de ESTA venta.
         * Si `resultado` trajera otro, el modelo lo repetiría sin forma de saberlo.
         */
        $this->assertSame('Venta N° ' . (int) $venta->num . ' registrada', $respuesta['resultado']);
        $this->assertStringContainsString((string) (int) $venta->num, $respuesta['resultado']);

        // Y la venta pasó por el camino de la pantalla: renglón en article_sale y stock descontado.
        $this->assertSame(1, DB::table('article_sale')
                                ->where('sale_id', $venta->id)
                                ->where('article_id', $martillo->id)
                                ->count());

        $this->assertEqualsWithDelta(8, (float) Article::find($martillo->id)->stock, self::DELTA);
    }

    /**
     * En "directo" un pago de cuenta corriente también se ejecuta en el acto, y el número de recibo
     * que se le cuenta al modelo es el que quedó en `current_acounts`.
     *
     * @test
     */
    public function en_directo_un_pago_se_ejecuta_sin_tarjeta()
    {
        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->dueno->id);

        $cuenta = CreditAccount::where('model_name', 'client')
                                ->where('model_id', $cliente->id)
                                ->where('moneda_id', 1)
                                ->where('user_id', $this->dueno->id)
                                ->firstOrFail();

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $this->actuar_como_el_dueno();

        list($conversation, $assistant) = $this->conversacion('Cobrale 4000 y listo, no preguntes');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_pago', [
            'tipo'        => 'cliente',
            'id'          => $cliente->id,
            'monto'       => 4000,
            'pagos'       => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
            'descripcion' => 'Cobro directo del test P51',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame('confirmada', $respuesta['estado'], 'El pago quedó propuesto: el modo directo no ejecutó.');

        $pago = CurrentAcount::where('credit_account_id', $cuenta->id)
                                ->whereNotNull('haber')
                                ->orderBy('id', 'DESC')
                                ->first();

        $this->assertNotNull($pago, 'No se registró el pago.');

        $this->cobros_cc_creados_por_escenarios[] = (int) $pago->id;

        $this->assertEqualsWithDelta(4000, (float) $pago->haber, self::DELTA);

        // El recibo que el modelo tiene para decir es el que quedó en la base.
        $this->assertStringContainsString('Pago N° ' . $pago->num_receipt . ' registrado', $respuesta['resultado']);
    }

    /**
     * 🔴 LA BAJA SIGUE DEJANDO TARJETA EN "DIRECTO", con las tres guardas medidas.
     *
     * 31 de las 40 entidades del catálogo no tienen SoftDeletes: borrar una lista de precios deja
     * clientes colgados y no hay vuelta atrás (decisión de Lucas, 22/9/2026).
     *
     * @test
     */
    public function en_directo_una_baja_sigue_dejando_tarjeta()
    {
        // Guarda 1: no está en la lista del modo directo.
        $this->assertNotContains(AiMessageAction::TIPO_BAJA, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);

        // Guarda 2: aunque alguien la sumara por distracción, el filtro la saca.
        $this->assertNotContains(AiMessageAction::TIPO_BAJA, HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::DIRECTO));

        // Guarda 3: su `case` ni siquiera pasa por la puerta.
        $this->assertStringNotContainsString('quizas_auto_confirmar', $this->bloque_del_case('proponer_baja'));

        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $categoria = Category::create(['name' => 'Rubro directo P51', 'user_id' => $this->dueno->id, 'num' => 949]);

        list($conversation, $assistant) = $this->conversacion('Borrá el rubro, no me preguntes nada');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_baja', [
            'entidad'  => 'category',
            'registro' => 'Rubro directo P51',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta, 'Una respuesta con "estado" es la de confirmar_del_agente: la baja no puede haber pasado por ahí.');

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertNotNull(Category::find($categoria->id), 'Se borró el rubro: el modo directo no puede borrar solo.');
    }

    /**
     * 🔴 LA ACTUALIZACIÓN MASIVA Y LA UNIFICACIÓN DE BANCOS, IGUAL: tarjeta en todos los modos.
     *
     * @test
     */
    public function en_directo_la_masiva_y_la_unificacion_de_bancos_siguen_dejando_tarjeta()
    {
        foreach ([AiMessageAction::TIPO_ACTUALIZACION_MASIVA, AiMessageAction::TIPO_UNIFICAR_BANCOS] as $tipo) {
            $this->assertNotContains($tipo, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO, $tipo);
            $this->assertNotContains($tipo, HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::DIRECTO), $tipo);
        }

        foreach (['proponer_actualizacion_masiva', 'proponer_unificar_bancos_de_cheques'] as $herramienta) {
            $this->assertStringNotContainsString('quizas_auto_confirmar', $this->bloque_del_case($herramienta), $herramienta);
        }

        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $articulo = $this->articulo_de_prueba('zz-p51 Tornillo masiva', 300, 100, 5);

        list($conversation, $assistant) = $this->conversacion('Subí el margen de todos, sin preguntar');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_actualizacion_masiva', [
            'filtros' => [['campo' => 'nombre', 'operador' => 'contiene', 'valor' => 'zz-p51 Tornillo masiva']],
            'cambios' => [['campo' => 'margen_de_ganancia', 'operacion' => 'setear', 'valor' => 40]],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta, 'La masiva pasó por la auto-confirmación.');

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertNull(Article::find($articulo->id)->percentage_gain, 'La masiva se aplicó sola.');
    }

    /**
     * 🔴 LAS PROHIBIDAS, DE UNA: ninguna entra en ninguna lista, en ningún modo.
     *
     * Recorre NUNCA_AUTO_CONFIRMABLES entera y no una lista escrita acá, a propósito: cuando una
     * misión suma un tipo prohibido —`permiso_empleado` el mismo 22/9/2026— queda cubierto sin
     * tocar este test.
     *
     * @test
     */
    public function las_prohibidas_no_entran_en_ninguna_lista_de_ningun_modo()
    {
        foreach (ConfianzaDelAgenteIaHelper::MODOS as $modo) {

            $auto = HerramientasDeCarga::auto_confirmables_de($modo);

            foreach (HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES as $tipo) {
                $this->assertNotContains($tipo, $auto, $tipo . ' se auto-ejecuta en modo ' . $modo);
            }
        }

        // "cauteloso" no auto-ejecuta NADA, y un modo que no se entiende tampoco.
        $this->assertSame([], HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::CAUTELOSO));
        $this->assertSame([], HerramientasDeCarga::auto_confirmables_de('un_modo_viejo'));
        $this->assertSame([], HerramientasDeCarga::auto_confirmables_de(''));

        // "resuelto" sigue siendo exactamente la lista de siempre, ni una más.
        $this->assertSame(HerramientasDeCarga::AUTO_CONFIRMABLES, HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::RESUELTO));

        // Y "directo" trae las de "resuelto" más las suyas: ninguna se pierde al prender el modo.
        $directo = HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::DIRECTO);

        foreach (HerramientasDeCarga::AUTO_CONFIRMABLES as $tipo) {
            $this->assertContains($tipo, $directo, $tipo . ' se perdió en el modo directo');
        }

        foreach ([
            AiMessageAction::TIPO_GASTO,
            AiMessageAction::TIPO_PAGO,
            AiMessageAction::TIPO_TAREA_NUEVA,
            AiMessageAction::TIPO_TAREA_EDITAR,
            AiMessageAction::TIPO_TAREA_COMPLETAR,
            AiMessageAction::TIPO_COMBO,
            AiMessageAction::TIPO_OFERTA,
            AiMessageAction::TIPO_COMPRA_CON_FACTURA,
            AiMessageAction::TIPO_FOTO_ARTICULO,
            AiMessageAction::TIPO_ALTA,
            AiMessageAction::TIPO_EDICION,
            AiMessageAction::TIPO_VENTA,
        ] as $tipo) {
            $this->assertContains($tipo, $directo, $tipo . ' no se ejecuta en el modo directo');
        }
    }

    /**
     * El default NO cambió: una cuenta que nunca tocó la configuración sigue en "resuelto", y una
     * columna vacía no auto-ejecuta nada (que es lo que hacía el `!== 'resuelto'` de antes).
     *
     * @test
     */
    public function el_default_sigue_siendo_resuelto_y_una_columna_vacia_no_ejecuta_nada()
    {
        $this->assertSame('resuelto', ConfianzaDelAgenteIaHelper::POR_DEFECTO);
        $this->assertSame(['cauteloso', 'resuelto', 'directo'], ConfianzaDelAgenteIaHelper::MODOS);

        $this->dueno->agente_confianza = '';
        $this->dueno->save();

        $this->assertSame('', ConfianzaDelAgenteIaHelper::guardada($this->dueno->fresh()));
        $this->assertSame('resuelto', ConfianzaDelAgenteIaHelper::con_default($this->dueno->fresh()));
        $this->assertSame('', ConfianzaDelAgenteIaHelper::guardada(null));
        $this->assertFalse(ConfianzaDelAgenteIaHelper::es_directo($this->dueno->fresh()));

        $categoria = Category::create(['name' => 'Rubro sin modo P51', 'user_id' => $this->dueno->id, 'num' => 948]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'category',
            'registro' => 'Rubro sin modo P51',
            'cambios'  => ['percentage_gain' => 12],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta, 'Con la columna vacía no se puede auto-ejecutar nada.');
        $this->assertNull(Category::find($categoria->id)->percentage_gain);
    }

    /**
     * 🔴 UN ERROR DE EJECUCIÓN LLEGA CON SU MOTIVO REAL.
     *
     * El 22/9 el agente escribió "No pude crearlo: el sistema rechazó el alta de Global Sources" y
     * la tarjeta estaba en `propuesta` con `error_mensaje` NULL: no hubo ningún rechazo, nunca se
     * ejecutó, y el motivo lo inventó él. Acá se fuerza un rechazo DE VERDAD (el 422 del límite de
     * crédito, que solo el controller puede juzgar) y se mide que el motivo concreto llega al
     * modelo, en vez de una propuesta muda que pueda contar como quiera.
     *
     * @test
     */
    public function en_directo_un_error_de_ejecucion_llega_al_modelo_con_su_motivo_real()
    {
        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $this->articulo_de_prueba('zz-p51 Martillo a cuenta');

        $cliente = Client::where('user_id', $this->dueno->id)
                            ->where('name', TestingFerreteriaSeeder::CLIENTE_CC)
                            ->firstOrFail();

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->dueno->id);

        $cuenta = CreditAccount::where('model_name', 'client')
                                ->where('model_id', $cliente->id)
                                ->where('moneda_id', 1)
                                ->where('user_id', $this->dueno->id)
                                ->firstOrFail();

        $this->limites_a_restaurar[$cuenta->id] = $cuenta->limite_credito;

        // Un límite que la venta de $ 4.000 supera seguro, sea cual sea el saldo de partida.
        $saldo = (float) CurrentAcountHelper::getSaldo($cuenta->id);
        $cuenta->limite_credito = max(0, $saldo) + 1000;
        $cuenta->save();

        $this->actuar_como_el_dueno();

        $ventas_antes = Sale::where('user_id', $this->dueno->id)->count();

        list($conversation, $assistant) = $this->conversacion('Mandá la venta a la cuenta del cliente');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_venta', [
            'items'   => [['articulo' => 'zz-p51 Martillo a cuenta', 'cantidad' => 2]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CC,
            'cobro'   => 'cuenta_corriente',
        ]);

        /*
         * La respuesta es negativa y trae el motivo CONCRETO. No es una propuesta muda ni un
         * "no se pudo" genérico: el modelo recibe la misma frase que vería la persona en la tarjeta.
         */
        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertNotNull($respuesta['error']);
        $this->assertStringContainsString('límite', mb_strtolower((string) $respuesta['error']));

        $this->assertSame($ventas_antes, Sale::where('user_id', $this->dueno->id)->count(), 'Un 422 del controller no deja venta.');

        // Y el motivo quedó guardado en la tarjeta, que sigue confirmable a mano.
        $tarjeta = AiMessageAction::where('ai_conversation_id', $conversation->id)
                                    ->where('tipo', AiMessageAction::TIPO_VENTA)
                                    ->firstOrFail();

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertStringContainsString('límite', mb_strtolower((string) $tarjeta->error_mensaje));
    }

    /**
     * 🔴 LA AUTO-CONFIRMACIÓN DEL MODO DIRECTO NO CHOCA CON LA GUARDA DEL MISMO TURNO, Y ESA GUARDA
     * SIGUE INTACTA.
     *
     * Son dos caminos distintos y este test los corre uno al lado del otro sobre la MISMA tarjeta:
     * confirmar_del_agente() no pasa por rechazo() (la decisión ya la tomó el dueño al elegir el
     * modo), y confirmar() —el "dale" por texto— sí, así que el mismo turno le sigue rebotando.
     *
     * @test
     */
    public function la_auto_confirmacion_del_modo_directo_no_choca_con_la_guarda_del_mismo_turno()
    {
        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $categoria = Category::create(['name' => 'Rubro mismo turno P51', 'user_id' => $this->dueno->id, 'num' => 947]);

        list($conversation, $assistant) = $this->conversacion('Cambiale el margen a 12, ya');

        /* El assistant sigue 'pendiente': esto es, literalmente, el mismo turno. */
        $this->assertSame('pendiente', $assistant->fresh()->estado);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'category',
            'registro' => 'Rubro mismo turno P51',
            'cambios'  => ['percentage_gain' => 12],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame('confirmada', $respuesta['estado'], 'La guarda del mismo turno frenó la auto-ejecución del modo directo.');
        $this->assertEqualsWithDelta(12, (float) Category::find($categoria->id)->percentage_gain, self::DELTA);

        /*
         * Y la guarda sigue en pie para el otro camino: un confirmar_carga_pendiente del mismo turno
         * rebota igual. (Acá ya está confirmada, así que lo que se mide es que rechazo() corta ANTES
         * de mirar el estado, con el mensaje del mismo turno.)
         */
        $por_texto = ConfirmacionPorTextoIaHelper::confirmar($conversation, $assistant->fresh(), $respuesta['tarjeta_id']);

        $this->assertFalse($por_texto['ok']);
        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_MISMO_TURNO, $por_texto['error']);
    }

    /**
     * 🔴 EN "DIRECTO" LA GUARDA ANTI-DOBLE-REGISTRO SIGUE MANDANDO.
     *
     * `confirmada_parecida` avisa que hay una tarjeta CONFIRMADA con la misma clave, resuelta
     * después del mensaje que disparó esta respuesta. Con la confirmación a mano eso era un aviso;
     * en "directo", auto-ejecutar encima sería cargar el mismo gasto dos veces. Acá se propone dos
     * veces la MISMA carga en el mismo turno: la primera se ejecuta, la segunda tiene que quedar
     * como tarjeta con el aviso.
     *
     * @test
     */
    public function en_directo_una_carga_igual_a_una_recien_confirmada_no_se_ejecuta_de_nuevo()
    {
        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $this->actuar_como_el_dueno();

        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();

        list($conversation, $assistant) = $this->conversacion('Anotá el alquiler, 5000, en efectivo');

        $entrada = [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
            'observaciones'   => 'Gasto repetido P51',
        ];

        $primera = $this->herramienta($conversation, $assistant, 'proponer_gasto', $entrada);

        $this->assertSame('confirmada', $primera['estado'], json_encode($primera));

        $gasto = Expense::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($gasto, 'No se registró el gasto.');

        $this->gastos_creados_por_escenarios[] = (int) $gasto->id;

        /* La MISMA carga, otra vez, en el mismo turno: misma clave (gasto:{subcategoria}). */
        $segunda = $this->herramienta($conversation, $assistant, 'proponer_gasto', $entrada);

        $this->assertTrue(!empty($segunda['ok']), json_encode($segunda));
        $this->assertArrayHasKey('confirmada_parecida', $segunda, 'La guarda anti-doble-registro no se disparó.');
        $this->assertArrayNotHasKey('estado', $segunda, 'La segunda se auto-ejecutó encima de la primera.');

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($segunda['tarjeta_id'])->estado_guardado());

        /* 🔴 Un solo gasto en la base, y una sola tarjeta confirmada. */
        $this->assertSame($gastos_antes + 1, Expense::where('user_id', $this->dueno->id)->count(), 'El gasto se cargó dos veces.');

        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)
                                            ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
                                            ->count());
    }

    /**
     * El prompt dice lo contrario según el modo — y las dos reglas contra lo inventado van en los
     * tres, porque el problema de afirmar cargas que no se hicieron no es exclusivo de ninguno.
     *
     * @test
     */
    public function el_prompt_de_carga_cambia_con_el_modo_y_las_reglas_contra_lo_inventado_van_en_los_tres()
    {
        $service = new AsistenteIaService();

        list($conversation) = $this->conversacion();

        $prompts = [];

        foreach (ConfianzaDelAgenteIaHelper::MODOS as $modo) {
            $this->dueno_en($modo);

            $prompts[$modo] = $service->build_system_prompt($conversation, $this->dueno->fresh(), true);
        }

        /* En "directo" el modelo ejecuta y cuenta lo que hizo. */
        $this->assertStringContainsString('la EJECUTA en el acto', $prompts['directo']);
        $this->assertStringNotContainsString('Vos nunca registrás nada', $prompts['directo']);
        $this->assertStringNotContainsString('Una venta NUNCA se hace sola', $prompts['directo']);

        /* Y las tres que igual dejan tarjeta están nombradas, para que no lo discuta. */
        $this->assertStringContainsString('proponer_baja', $prompts['directo']);
        $this->assertStringContainsString('incluso en "directo"', $prompts['directo']);

        /* En los otros dos, lo de siempre — más la salida para el que pide "sin preguntar". */
        foreach (['cauteloso', 'resuelto'] as $modo) {
            $this->assertStringContainsString('Vos nunca registrás nada', $prompts[$modo], $modo);
            $this->assertStringContainsString('Una venta NUNCA se hace sola', $prompts[$modo], $modo);
            $this->assertStringContainsString('modo directo desde la', $prompts[$modo], $modo);
            $this->assertStringNotContainsString('la EJECUTA en el acto', $prompts[$modo], $modo);
        }

        /*
         * 🔴 Y en los TRES, las dos reglas contra lo que pasó el 22/9: el número sale del resultado
         * de la herramienta, y un fallo se cuenta con el motivo que la herramienta informó.
         */
        foreach (ConfianzaDelAgenteIaHelper::MODOS as $modo) {
            $this->assertStringContainsString('salen SIEMPRE del `resultado`', $prompts[$modo], $modo);
            $this->assertStringContainsString('NUNCA inventes un rechazo del sistema', $prompts[$modo], $modo);
        }
    }

    // ---------------------------------------------------------------------
    // P2 · El escalado al modelo Profundo
    // ---------------------------------------------------------------------

    /**
     * Respuesta `end_turn` del proveedor.
     *
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto = 'Listo.')
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * Respuesta `tool_use` del proveedor, pidiendo UNA herramienta.
     *
     * @param  string  $herramienta
     * @param  array  $input
     * @return array
     */
    protected function tool_use($herramienta, array $input = [])
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_p51_' . uniqid(),
                'name'  => $herramienta,
                'input' => $input,
            ]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * Deja al dueño con DeepSeek en su variante Ágil (el par Flash → Pro es el que pidió Lucas).
     *
     * @return void
     */
    protected function dueno_en_deepseek_agil()
    {
        $this->dueno->agente_proveedor = 'deepseek';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->save();
    }

    /**
     * Los bodies de todos los requests que salieron, en orden.
     *
     * @return array<int, array>
     */
    protected function bodies_enviados()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            $bodies[] = json_decode($par[0]->body(), true);
        }

        return $bodies;
    }

    /**
     * Le cuelga una foto de verdad (un PNG chico en el disco fake) al último mensaje del usuario,
     * con su fila en `ai_message_imagenes`, para que build_messages_payload() arme el bloque
     * `image` como en producción. Misma siembra que el test 47.
     *
     * @param  AiConversation  $conversation
     * @return void
     */
    protected function con_una_foto(AiConversation $conversation)
    {
        Storage::fake('local');

        $mensaje = AiMessage::where('ai_conversation_id', $conversation->id)
                            ->where('rol', 'user')
                            ->orderBy('id', 'DESC')
                            ->first();

        $recurso = imagecreatetruecolor(30, 30);
        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $path = 'asistente_imagenes/' . $this->dueno->id . '/' . $mensaje->id . '/1.webp';

        Storage::disk('local')->put($path, $binario);

        AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->dueno->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * Una consulta de solo lectura NO escala: las dos vueltas van con el Ágil. Es la mitad del
     * sentido del escalado — si escalara siempre, cada "¿cuánto vendí hoy?" saldría al modelo caro.
     *
     * @test
     */
    public function una_consulta_de_solo_lectura_se_queda_en_el_agil()
    {
        $this->dueno_en_deepseek_agil();

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->tool_use('consultar_proveedores', ['busqueda' => '']), 200)
                ->push($this->end_turn('Tenés tres proveedores.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion('¿Qué proveedores tengo?');

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies, 'Tendrían que haber salido dos llamadas: la del tool_use y la del texto.');

        foreach ($bodies as $indice => $body) {
            $this->assertSame(config('services.deepseek.model_agil'), $body['model'], 'La vuelta ' . $indice . ' se fue al Profundo sin que hubiera ninguna carga.');
            $this->assertSame('disabled', $body['thinking']['type']);
            $this->assertSame(AsistenteIaService::MAX_TOKENS, $body['max_tokens']);
        }
    }

    /**
     * 🔴 EN CUANTO EL TURNO PIDE UNA CARGA, LAS VUELTAS SIGUIENTES VAN AL PROFUNDO — incluida la
     * del texto final, que es donde se decide pedir confirmación de más o inventar un número.
     *
     * La PRIMERA sigue siendo la del Ágil: el escalado se decide con lo que el modelo pidió, así
     * que no puede aplicarse antes de que lo pida.
     *
     * @test
     */
    public function cuando_el_turno_pide_una_carga_las_vueltas_siguientes_van_al_profundo()
    {
        $this->dueno_en_deepseek_agil();

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                /* Sin datos: la propuesta contesta "faltan" y no escribe nada. Escala igual. */
                ->push($this->tool_use('proponer_gasto', []), 200)
                ->push($this->tool_use('consultar_opciones_de_carga', []), 200)
                ->push($this->end_turn('¿De cuánto fue?'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion('Anotá la nafta y no me preguntes');

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);

        $this->assertSame(config('services.deepseek.model_agil'), $bodies[0]['model'], 'El turno arranca en el Ágil.');
        $this->assertSame('disabled', $bodies[0]['thinking']['type']);

        /*
         * Y de la segunda en adelante NO VUELVE: la tercera es una consulta de lectura y sigue en el
         * Profundo, que es exactamente lo que pide la regla ("una vez que el turno tocó una carga,
         * no vuelve al Ágil").
         */
        foreach ([1, 2] as $indice) {
            $this->assertSame(config('services.deepseek.model_profundo'), $bodies[$indice]['model'], 'La vuelta ' . $indice . ' no escaló.');
            $this->assertSame('enabled', $bodies[$indice]['thinking']['type'], 'El thinking tiene que viajar con el modelo que corre.');
            $this->assertArrayHasKey('budget_tokens', $bodies[$indice]['thinking']);

            /* 🔴 El techo acompaña al modelo: si el razonamiento cuenta contra max_tokens, con los de siempre cortaría sin texto. */
            $this->assertSame((int) config('services.deepseek.max_tokens_profundo'), $bodies[$indice]['max_tokens']);
            $this->assertGreaterThan($bodies[0]['max_tokens'], $bodies[$indice]['max_tokens']);
        }
    }

    /**
     * El escalado vale igual con Anthropic: el par es Ágil (Haiku) → Profundo (Opus), y los ids
     * salen de config, nunca hardcodeados.
     *
     * @test
     */
    public function con_anthropic_el_escalado_va_del_agil_al_profundo()
    {
        $this->dueno->agente_proveedor = 'anthropic';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->save();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->tool_use('proponer_gasto', []), 200)
                ->push($this->end_turn('¿De cuánto fue?'), 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion('Anotá la nafta');

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);
        $this->assertSame(config('services.anthropic.model_agil'), $bodies[0]['model']);
        $this->assertSame(config('services.anthropic.model_profundo'), $bodies[1]['model']);

        /* Anthropic no recibe ninguna clave `thinking`, exactamente como antes de esta misión. */
        $this->assertArrayNotHasKey('thinking', $bodies[0]);
        $this->assertArrayNotHasKey('thinking', $bodies[1]);
    }

    /**
     * 🔴 CON UNA FOTO MANDA LA VISIÓN, NO EL ESCALADO. `deepseek-v4-pro` no ve imágenes y DeepSeek
     * no lo denuncia con un error: mapea el pedido y contesta como si la foto no estuviera. Si el
     * escalado pisara la guarda de visión, el dueño mandaría la factura y recibiría una respuesta
     * que no la miró — un camino de falla mudo.
     *
     * @test
     */
    public function con_una_foto_el_turno_que_escala_igual_usa_el_modelo_con_vision()
    {
        $this->dueno_en_deepseek_agil();

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->tool_use('proponer_gasto', []), 200)
                ->push($this->end_turn('¿De cuánto fue?'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion('Cargá lo de esta foto');

        $this->con_una_foto($conversation);

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);

        foreach ($bodies as $indice => $body) {
            $this->assertSame(config('services.deepseek.model_vision'), $body['model'], 'La vuelta ' . $indice . ' se fue a un modelo que no ve la foto.');
            $this->assertNotSame(config('services.deepseek.model_profundo'), $body['model']);
        }

        /* Y la foto viajó de verdad: si no hubiera bloque `image`, la aserción de arriba no probaría nada. */
        $this->assertSame('image', $bodies[0]['messages'][0]['content'][0]['type']);

        /* El thinking sí escala, porque Flash también razona: lo que no cambia es el modelo. */
        $this->assertSame('enabled', $bodies[1]['thinking']['type']);
    }

    /**
     * Las tools de carga que prenden el escalado son las que proponen y la que confirma; las
     * consultas y la cancelación, no.
     *
     * @test
     */
    public function es_de_carga_reconoce_las_propuestas_y_la_confirmacion_y_nada_mas()
    {
        foreach (['proponer_gasto', 'proponer_venta', 'proponer_baja', 'confirmar_carga_pendiente'] as $herramienta) {
            $this->assertTrue(HerramientasDeCarga::es_de_carga($herramienta), $herramienta);
        }

        foreach ([
            'consultar_opciones_de_carga',
            'consultar_proveedores',
            'contar_articulos_por_filtro',
            'que_puedo_cargar',
            'cancelar_carga_pendiente',
            'resumir_datos',
            '',
        ] as $herramienta) {
            $this->assertFalse(HerramientasDeCarga::es_de_carga($herramienta), $herramienta);
        }

        /* Toda propuesta declarada queda cubierta por el prefijo, incluidas las que se sumen después. */
        foreach (HerramientasDeCarga::nombres(true) as $nombre) {
            if (strpos($nombre, 'proponer_') === 0) {
                $this->assertTrue(HerramientasDeCarga::es_de_carga($nombre), $nombre);
            }
        }
    }

    /**
     * El PUT de la configuración acepta el modo nuevo y lo guarda en el DUEÑO, y sigue rechazando
     * un valor fuera del enum.
     *
     * @test
     */
    public function el_put_de_configuracion_acepta_directo_y_sigue_rechazando_lo_que_no_existe()
    {
        $r = $this->actingAs($this->dueno, 'sanctum')
                  ->putJson('api/user/asistente-config', ['confianza' => 'directo', 'pensamiento' => 'agil']);

        $r->assertStatus(200)->assertJson(['confianza' => 'directo']);

        $this->assertSame('directo', (string) $this->dueno->fresh()->agente_confianza);

        $this->actingAs($this->dueno, 'sanctum')
             ->putJson('api/user/asistente-config', ['confianza' => 'sin_frenos', 'pensamiento' => 'agil'])
             ->assertStatus(422);

        $this->assertSame('directo', (string) $this->dueno->fresh()->agente_confianza, 'Un valor inválido no puede pisar el guardado.');

        $this->actuar_como_el_dueno();
    }

    /**
     * El que rompe la auto-ejecución no deja al modelo mudo: le dice que quedó como tarjeta y le
     * prohíbe inventar el motivo. Sin esta nota, el modelo en "directo" —al que el prompt le dijo
     * que sus cargas se hacen solas— contaría una carga que no pasó.
     *
     * @test
     */
    public function la_nota_del_fallo_de_auto_ejecucion_dice_que_quedo_como_tarjeta()
    {
        $nota = HerramientasDeCarga::NOTA_AUTO_EJECUCION_FALLIDA;

        $this->assertStringContainsString('No se pudo ejecutar en el acto', $nota);
        $this->assertStringContainsString('quedó como tarjeta', $nota);
        $this->assertStringContainsString('NO digas que ya está cargado ni inventes otro motivo', $nota);

        /* Y la nota de lo EJECUTADO le dice de dónde sale el número. */
        $this->assertStringContainsString('`resultado`', ConfirmacionPorTextoIaHelper::NOTA_EJECUTADA);
        $this->assertStringContainsString('no digas ninguno', ConfirmacionPorTextoIaHelper::NOTA_EJECUTADA);
    }

}
