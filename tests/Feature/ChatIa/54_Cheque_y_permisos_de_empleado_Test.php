<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\OpcionesDeCargaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PagosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPermisoEmpleadoIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ExtencionEmpresa;
use App\Models\PermissionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-capacidades-y-hilos (22/9/2026) — el cobro/pago con CHEQUE y el PERMISO de un
 * empleado.
 *
 * De dónde sale cada test, en la conversación de demo3 del 22/9:
 *  - #46 "los pagos con cheque no los puedo cargar desde acá" → la fila de cheque de proponer_pago.
 *  - #44 "los permisos de los usuarios se administran desde configuración" → proponer_permiso_de_empleado.
 *
 * Lo que protege:
 *  - 🔴 Que una fila de cheque SIN `current_acount_payment_method_id` no se saltee en silencio:
 *    `PaymentMethodHelper::attach_payment_methods()` la descarta con un `Log::warning` y sigue, así
 *    que el pago quedaría registrado y el cheque no existiría en ningún lado.
 *  - Que el cheque se cree con su número, su banco y sus fechas, y que NO mueva caja.
 *  - 🔴 Que cambiar UN permiso conserve todos los demás (`update()` hace `sync([])` + attach uno por
 *    uno: mandar solo el nuevo le saca el resto).
 *  - 🔴 Que la contraseña del empleado siga funcionando (`update()` hace
 *    `bcrypt($request->visible_password)` SIEMPRE), y que sin `visible_password` cargada la carga se
 *    rechace en vez de dejar a alguien afuera.
 *  - Que esta tarjeta NUNCA se auto-ejecute, ni con el dueño en "directo".
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Cheque_y_permisos_de_empleado_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta para comparar montos. */
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var array<int,ExtencionEmpresa> Extensiones enganchadas por este archivo. */
    protected $extensiones_enganchadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba-p54']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->dar_extension('asistente_ia');

        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
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
     * @param  string  $pedido
     * @param  User|null  $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido = 'Cargalo', $persona = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $persona->id,
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
     * Vuelve a autenticar al dueño en el guard `web` después de una request HTTP: sanctum queda como
     * guard por defecto.
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
     * El método de pago de tipo cheque del catálogo.
     *
     * @return CurrentAcountPaymentMethod
     */
    protected function metodo_cheque()
    {
        foreach (OpcionesDeCargaIaHelper::metodos_de_pago() as $metodo) {

            if (OpcionesDeCargaIaHelper::es_cheque($metodo)) {
                return $metodo;
            }
        }

        $this->fail('El catálogo del fixture tiene que tener un método de pago de tipo cheque.');
    }

    /**
     * Un empleado del dueño, con contraseña visible cargada y los permisos que se le pasen.
     *
     * @param  array<int,string>  $slugs
     * @param  string|null  $visible_password
     * @return User
     */
    protected function empleado(array $slugs, $visible_password = 'clave-p54')
    {
        $empleado = User::create([
            'name'             => 'zz-p54 Brisa ' . uniqid(),
            'email'            => 'empleado-p54-' . uniqid() . '@test.local',
            'password'         => Hash::make(is_null($visible_password) ? 'otra' : $visible_password),
            'visible_password' => $visible_password,
            'owner_id'         => $this->dueno->id,
        ]);

        $empleado->permissions()->sync(self::ids_de($slugs));
        $empleado->load('permissions');

        return $empleado;
    }

    /**
     * Ids de esos slugs, creando el permiso si la base no lo tiene (`permission_empresas` nace
     * vacía en la base de testing).
     *
     * @param  array<int,string>  $slugs
     * @return array<int,int>
     */
    protected static function ids_de(array $slugs)
    {
        $ids = [];

        foreach ($slugs as $slug) {

            $permiso = PermissionEmpresa::where('slug', $slug)->first();

            if (is_null($permiso)) {

                $permiso = PermissionEmpresa::forceCreate([
                    'name'       => 'zz-p54 ' . $slug,
                    'slug'       => $slug,
                    'model_name' => explode('.', $slug)[0],
                ]);
            }

            $ids[] = (int) $permiso->id;
        }

        return $ids;
    }

    // =====================================================================
    // (f) El cheque — el #46
    // =====================================================================

    /**
     * 🔴 EL CAMINO CRÍTICO: un pago con cheque queda con su cheque cargado, y sin mover caja.
     *
     * @test
     */
    public function un_pago_con_cheque_crea_el_cheque_con_sus_datos_y_no_mueve_caja()
    {
        $cliente = Client::where('user_id', $this->dueno->id)->where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $this->assertNotNull($cliente, 'El fixture tiene que tener el cliente de cuenta corriente.');

        $metodo = $this->metodo_cheque();

        $vencimiento = Carbon::today()->addDays(30);

        $cheques_antes = Cheque::where('user_id', $this->dueno->id)->count();
        $movimientos_antes = $this->max_id_movimiento_caja();

        list($conversation, $assistant) = $this->conversacion('Me pagó con un cheque del Banco Nación');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_pago', [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 100000,
            'pagos' => [[
                'metodo_de_pago_id' => $metodo->id,
                'cheque'            => [
                    'numero'     => '00123456',
                    'banco'      => 'Banco Nación',
                    'fecha_pago' => $vencimiento->format('Y-m-d'),
                ],
            ]],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $http->assertStatus(200);

        $this->actuar_como_el_dueno();

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado_guardado(), (string) $accion->error_mensaje);

        $this->assertSame($cheques_antes + 1, Cheque::where('user_id', $this->dueno->id)->count(), 'No se creó el cheque.');

        $cheque = Cheque::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        $this->assertSame('00123456', (string) $cheque->numero);
        $this->assertSame('Banco Nación', (string) $cheque->banco);
        $this->assertSame($vencimiento->format('Y-m-d'), Carbon::parse($cheque->fecha_pago)->format('Y-m-d'));
        $this->assertSame((int) $cliente->id, (int) $cheque->client_id);
        $this->assertEqualsWithDelta(100000, (float) $cheque->amount, self::DELTA);

        // 🔴 Un cheque NO mueve caja al cargarse: la plata se mueve con /cheque/cobrar.
        $this->assertNull($cheque->caja_id);
        $this->assertSame($movimientos_antes, $this->max_id_movimiento_caja(), 'El cheque movió una caja y no tenía que moverla.');

        // Y el pago sí quedó en la cuenta corriente, con su fila de método de pago.
        $pago = CurrentAcount::where('client_id', $cliente->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($pago);
        $this->assertEqualsWithDelta(100000, (float) $pago->haber, self::DELTA);
    }

    /**
     * 🔴 LA FILA DE CHEQUE LLEVA EL `current_acount_payment_method_id`, Y ESO NO ES UN DETALLE.
     *
     * `PaymentMethodHelper::attach_payment_methods()` saltea EN SILENCIO (`Log::warning` + continue)
     * toda fila que no lo traiga: el pago quedaría registrado, el cheque no se crearía y no habría
     * ningún error en ningún lado. Este test fija que la fila que arma el helper siempre lo lleva,
     * y que la clave se llama así.
     *
     * @test
     */
    public function la_fila_de_cheque_lleva_el_id_del_metodo_y_todas_las_claves_que_lee_el_backend()
    {
        $metodo = $this->metodo_cheque();

        $fila = PagosIaHelper::fila_de_cheque($metodo->id, 5000, 1, [
            'numero'        => '999',
            'banco'         => 'Banco Test',
            'fecha_emision' => '2026-09-22',
            'fecha_pago'    => '2026-10-22',
            'es_echeq'      => 0,
            'notes'         => null,
        ]);

        $this->assertArrayHasKey('current_acount_payment_method_id', $fila);
        $this->assertSame((int) $metodo->id, $fila['current_acount_payment_method_id']);

        // Las seis que lee ChequeHelper::crear_cheque(), con SU nombre.
        foreach (['numero', 'banco', 'cheque_banco_id', 'fecha_emision', 'fecha_pago', 'es_echeq', 'notes'] as $clave) {
            $this->assertArrayHasKey($clave, $fila, 'Falta la clave ' . $clave . ' que lee ChequeHelper::crear_cheque().');
        }

        // 🔴 Y el caja_id en 0: un cheque no entra a ninguna caja al cargarse.
        $this->assertSame(0, $fila['caja_id']);
    }

    /**
     * 🔴 POR QUÉ ESE ID IMPORTA TANTO: el backend saltea la fila EN SILENCIO cuando falta.
     *
     * Este test NO prueba el asistente: prueba `PaymentMethodHelper::attach_payment_methods()` crudo,
     * para dejar medido el modo de fallar. Una fila sin `current_acount_payment_method_id` se
     * descarta con un `Log::warning` y el loop sigue: el pago queda registrado, el cheque NO se crea
     * y no hay ningún error en ningún lado. Si el helper del asistente dejara de mandar esa clave,
     * el síntoma sería "cargué el cheque" contra una tabla de cheques vacía — exactamente la clase
     * de mentira que esta misión vino a cerrar.
     *
     * @test
     */
    public function una_fila_de_cheque_sin_el_id_del_metodo_se_saltea_en_silencio()
    {
        $cliente = Client::where('user_id', $this->dueno->id)->where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $cheques_antes = Cheque::where('user_id', $this->dueno->id)->count();

        $pago = CurrentAcount::create([
            'client_id' => $cliente->id,
            'user_id'   => $this->dueno->id,
            'haber'     => 1000,
            'debe'      => 0,
            'saldo'     => 0,
        ]);

        $fila = PagosIaHelper::fila_de_cheque($this->metodo_cheque()->id, 1000, 1, [
            'numero'        => '777',
            'banco'         => 'Banco Test',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(10)->format('Y-m-d'),
            'es_echeq'      => 0,
            'notes'         => null,
        ]);

        unset($fila['current_acount_payment_method_id']);

        \App\Http\Controllers\Helpers\PaymentMethodHelper::attach_payment_methods($pago, [$fila]);

        $this->assertSame(
            $cheques_antes,
            Cheque::where('user_id', $this->dueno->id)->count(),
            'Si esto ya crea el cheque, el salteo silencioso dejó de existir y el comentario del helper hay que actualizarlo.'
        );

        $this->assertCount(0, $pago->fresh()->current_acount_payment_methods, 'Tampoco quedó la fila del método de pago.');
    }

    /**
     * Sin número, sin banco o sin fecha de vencimiento no se propone nada: un cheque en blanco no lo
     * puede reconocer nadie, y todas las columnas de `cheques` son nullable.
     *
     * @test
     */
    public function un_cheque_sin_numero_banco_o_vencimiento_se_pregunta()
    {
        $cliente = Client::where('user_id', $this->dueno->id)->where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $metodo = $this->metodo_cheque();

        $casos = [
            ['banco' => 'Banco Nación', 'fecha_pago' => Carbon::today()->addDays(10)->format('Y-m-d')],
            ['numero' => '1', 'fecha_pago' => Carbon::today()->addDays(10)->format('Y-m-d')],
            ['numero' => '1', 'banco' => 'Banco Nación'],
        ];

        foreach ($casos as $indice => $cheque) {

            list($conversation, $assistant) = $this->conversacion('Cargá el cheque');

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_pago', [
                'tipo'  => 'cliente',
                'id'    => $cliente->id,
                'monto' => 1000,
                'pagos' => [['metodo_de_pago_id' => $metodo->id, 'cheque' => $cheque]],
            ]);

            $this->assertFalse(!empty($respuesta['ok']), 'El caso ' . $indice . ' tendría que faltar algo: ' . json_encode($respuesta));
            $this->assertNotEmpty($respuesta['faltan'], 'El caso ' . $indice . ' tiene que preguntar, no errorear.');
            $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        }
    }

    /**
     * Mandarle una caja a un cheque se rechaza con el motivo: la pantalla ni siquiera le dibuja el
     * selector, porque un cheque no entra a ninguna caja hasta que se lo cobra.
     *
     * @test
     */
    public function a_un_cheque_no_se_le_manda_caja()
    {
        $cliente = Client::where('user_id', $this->dueno->id)->where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $metodo = $this->metodo_cheque();
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        list($conversation, $assistant) = $this->conversacion('Cargá el cheque a la caja');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_pago', [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 1000,
            'pagos' => [[
                'metodo_de_pago_id' => $metodo->id,
                'caja_id'           => $caja->id,
                'cheque'            => [
                    'numero'     => '1',
                    'banco'      => 'Banco Nación',
                    'fecha_pago' => Carbon::today()->addDays(10)->format('Y-m-d'),
                ],
            ]],
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertStringContainsString('caja', (string) $respuesta['error']);
    }

    /**
     * El cheque salió de MOTIVOS_NO_USABLES, y la tarjeta de crédito y la retención siguen adentro.
     *
     * @test
     */
    public function el_cheque_salio_de_los_no_usables_y_los_otros_dos_siguen()
    {
        $this->assertArrayNotHasKey('cheque', OpcionesDeCargaIaHelper::MOTIVOS_NO_USABLES);
        $this->assertArrayHasKey('tarjeta_de_credito', OpcionesDeCargaIaHelper::MOTIVOS_NO_USABLES);
        $this->assertArrayHasKey('retencion', OpcionesDeCargaIaHelper::MOTIVOS_NO_USABLES);

        $this->assertNull(OpcionesDeCargaIaHelper::motivo_no_usable($this->metodo_cheque()));
    }

    /**
     * 🔴 PERO EN UNA VENTA NO, Y NO ES UNA PREFERENCIA.
     *
     * El cobro de una venta viaja por el camino del método ÚNICO del select
     * (`current_acount_payment_method_id` + `caja_id`), que NO tiene dónde poner el número, el
     * banco ni las fechas. `attach_payment_methods()` crearía el cheque igual —solo mira el slug
     * del tipo— y todas las columnas de `cheques` son nullable: quedaría un cheque en blanco,
     * imposible de reconciliar y sin ningún error en ningún lado.
     *
     * @test
     */
    public function en_una_venta_el_cheque_sigue_sin_poder_usarse()
    {
        $metodo = $this->metodo_cheque();

        $this->assertNull(OpcionesDeCargaIaHelper::motivo_no_usable($metodo), 'En un pago sí se puede.');
        $this->assertNotNull(OpcionesDeCargaIaHelper::motivo_no_usable_en_venta($metodo), 'En una venta no.');

        // Y los que ya no se podían siguen dando el mismo motivo por los dos caminos.
        foreach (OpcionesDeCargaIaHelper::metodos_de_pago() as $otro) {

            if (OpcionesDeCargaIaHelper::es_cheque($otro)) {
                continue;
            }

            $this->assertSame(
                OpcionesDeCargaIaHelper::motivo_no_usable($otro),
                OpcionesDeCargaIaHelper::motivo_no_usable_en_venta($otro),
                (string) $otro->name
            );
        }

        $articulo = \App\Models\Article::create([
            'name'        => 'zz-p54 Martillo venta cheque',
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 1000,
            'cost'        => 500,
            'stock'       => 10,
            'iva_id'      => 2,
        ]);

        $cheques_antes = Cheque::where('user_id', $this->dueno->id)->count();

        list($conversation, $assistant) = $this->conversacion('Vendé 1 martillo y cobralo con cheque');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_venta', [
            'items'          => [['articulo' => 'zz-p54 Martillo venta cheque', 'cantidad' => 1]],
            'cobro'          => 'contado',
            'metodo_de_pago' => $metodo->name,
        ]);

        $this->assertFalse(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertStringContainsString('Vender', (string) $respuesta['error']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        $this->assertSame($cheques_antes, Cheque::where('user_id', $this->dueno->id)->count());

        // Y tampoco se ofrece entre los métodos que la venta lista.
        $nombres = array_column($respuesta['opciones']['metodos_de_pago'], 'nombre');

        $this->assertNotContains((string) $metodo->name, $nombres);
    }

    // =====================================================================
    // (g) El permiso de un empleado — el #44
    // =====================================================================

    /**
     * 🔴 EL CAMINO CRÍTICO Y LAS DOS TRAMPAS DE UNA: sacarle UN permiso le conserva TODOS los demás
     * y NO le rompe la contraseña.
     *
     * `EmployeeController::update()` hace `sync([])` + un attach por permiso (mandar solo el nuevo
     * le saca el resto) y `password = bcrypt($request->visible_password)` SIEMPRE (con la clave
     * vacía, el empleado no entra más). Es el caso real del #44: sacarle a Brisa "ver ventas".
     *
     * @test
     */
    public function sacarle_un_permiso_conserva_los_demas_y_no_le_rompe_la_contrasena()
    {
        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);

        $empleado = $this->empleado(['sale.index', 'client.index', 'article.index'], 'clave-de-brisa');

        $this->assertCount(3, $empleado->permissions);

        list($conversation, $assistant) = $this->conversacion('Sacale a Brisa el permiso de ver ventas');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_permiso_de_empleado', [
            'empleado' => $empleado->name,
            'permiso'  => 'sale.index',
            'accion'   => 'sacar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $http->assertStatus(200);

        $this->actuar_como_el_dueno();

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado_guardado(), (string) $accion->error_mensaje);

        $recargado = User::find($empleado->id);

        $slugs = [];

        foreach ($recargado->permissions as $permiso) {
            $slugs[] = (string) $permiso->slug;
        }

        sort($slugs);

        $this->assertSame(
            ['article.index', 'client.index'],
            $slugs,
            'El endpoint reemplaza la lista entera: los otros dos permisos tienen que seguir.'
        );

        // 🔴 Y la contraseña sigue siendo la de antes.
        $this->assertSame('clave-de-brisa', (string) $recargado->visible_password);
        $this->assertTrue(Hash::check('clave-de-brisa', $recargado->password), 'El empleado ya no puede entrar con su contraseña.');

        // El texto que el modelo tiene para decir enumera con qué queda.
        $this->assertStringContainsString('Le quedan', $accion->resultado->texto);
    }

    /**
     * Darle un permiso también conserva los que tenía.
     *
     * @test
     */
    public function darle_un_permiso_conserva_los_que_tenia()
    {
        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);

        // `permission_empresas` nace vacía en la base de testing: el que se va a dar tiene que existir.
        self::ids_de(['sale.index']);

        $empleado = $this->empleado(['client.index']);

        list($conversation, $assistant) = $this->conversacion('Dale el permiso de ver ventas');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_permiso_de_empleado', [
            'empleado' => $empleado->name,
            'permiso'  => 'sale.index',
            'accion'   => 'dar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->actuar_como_el_dueno();

        $slugs = [];

        foreach (User::find($empleado->id)->permissions as $permiso) {
            $slugs[] = (string) $permiso->slug;
        }

        sort($slugs);

        $this->assertSame(['client.index', 'sale.index'], $slugs);
    }

    /**
     * 🔴 LA VENTANA ENTRE PROPONER Y CONFIRMAR: UN CAMBIO DE PERMISOS HECHO POR FUERA NO SE PIERDE.
     *
     * `payload` lleva el modelo entero y `update()` pisa cada columna con lo que le llega, así que
     * confirmar con el payload congelado revertiría lo que se haya tocado en el medio — y la
     * verificación posterior, si comparara contra la lista congelada, diría que salió bien.
     *
     * Este caso es el que la guarda de `updated_at` NO puede atrapar: un `sync()` sobre la pivot
     * `permission_empresa_user` no toca `users.updated_at`. Lo cubre el rearmado del payload, que
     * aplica el alta o la baja sobre la lista que el empleado tiene AHORA.
     *
     * @test
     */
    public function un_permiso_dado_por_fuera_entre_proponer_y_confirmar_no_se_pierde()
    {
        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);

        self::ids_de(['provider.index']);

        $empleado = $this->empleado(['sale.index', 'client.index'], 'clave-ventana');

        list($conversation, $assistant) = $this->conversacion('Sacale el permiso de ver ventas');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_permiso_de_empleado', [
            'empleado' => $empleado->name,
            'permiso'  => 'sale.index',
            'accion'   => 'sacar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        /*
         * En el medio, el dueño le da OTRO permiso desde la pantalla de Empleados. Se hace con la
         * pivot a propósito: así no se toca `users.updated_at` y la guarda del 409 no lo tapa, que
         * es justo el caso que tiene que cubrir el rearmado.
         */
        $empleado->permissions()->syncWithoutDetaching(self::ids_de(['provider.index']));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->actuar_como_el_dueno();

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado_guardado(), (string) $accion->error_mensaje);

        $slugs = [];

        foreach (User::find($empleado->id)->permissions as $permiso) {
            $slugs[] = (string) $permiso->slug;
        }

        sort($slugs);

        $this->assertSame(
            ['client.index', 'provider.index'],
            $slugs,
            'Se perdió el permiso que le dieron entre la propuesta y el clic: el payload se confirmó congelado.'
        );
    }

    /**
     * 🔴 Y SI LA FICHA CAMBIÓ, LA TARJETA SE VENCE EN VEZ DE REVERTIRLA.
     *
     * Editar al empleado desde ABM > Empleados toca `users.updated_at`. Confirmar con el payload
     * congelado le devolvería el teléfono (y el nombre, y la sucursal, y el vendedor) al valor
     * viejo, sin que nadie lo vea. Se corta con 409 y la tarjeta queda vencida: el renglón
     * "le quedan" que la persona está mirando ya puede no ser verdad.
     *
     * @test
     */
    public function si_editan_la_ficha_en_el_medio_la_tarjeta_se_vence_y_no_revierte_nada()
    {
        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);

        $empleado = $this->empleado(['sale.index', 'client.index'], 'clave-ficha');

        list($conversation, $assistant) = $this->conversacion('Sacale el permiso de ver ventas');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_permiso_de_empleado', [
            'empleado' => $empleado->name,
            'permiso'  => 'sale.index',
            'accion'   => 'sacar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        /*
         * En el medio, el dueño le cambia el teléfono desde la pantalla.
         *
         * ⚠️ El `updated_at` se adelanta a mano y no es un truco para que el test pase: la columna
         * es `timestamp`, o sea precisión de SEGUNDO, y el test entero corre en milisegundos, así
         * que un save() acá dejaría el mismo segundo que la propuesta y no habría nada que
         * detectar. El caso real es una edición minutos u horas después —la tarjeta vive hasta 24 h
         * (AiMessageAction::HORAS_VENCIMIENTO)—, y eso es lo que se representa.
         */
        $empleado->phone = '11-5555-4444';
        $empleado->updated_at = Carbon::now()->addMinutes(5);
        $empleado->save();

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $http->assertStatus(409);

        $this->actuar_como_el_dueno();

        $recargado = User::find($empleado->id);

        $this->assertSame('11-5555-4444', (string) $recargado->phone, 'El teléfono volvió al valor viejo de la tarjeta.');
        $this->assertCount(2, $recargado->permissions, 'La tarjeta vencida no puede haber tocado los permisos.');

        $this->assertSame(AiMessageAction::ESTADO_VENCIDA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
    }

    /**
     * 🔴 SIN `visible_password` CARGADA, LA CARGA SE RECHAZA. `update()` hace bcrypt() de lo que le
     * llegue: con la clave vacía el empleado se queda afuera del sistema, y eso no lo pidió nadie.
     *
     * @test
     */
    public function sin_contrasena_visible_no_se_le_tocan_los_permisos()
    {
        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);

        $empleado = $this->empleado(['sale.index', 'client.index'], null);

        $password_antes = User::find($empleado->id)->password;

        list($conversation, $assistant) = $this->conversacion('Sacale el permiso de ver ventas');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_permiso_de_empleado', [
            'empleado' => $empleado->name,
            'permiso'  => 'sale.index',
            'accion'   => 'sacar',
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertStringContainsString('contraseña', (string) $respuesta['error']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        $this->assertCount(2, User::find($empleado->id)->permissions, 'No se le tocó ningún permiso.');
        $this->assertSame($password_antes, User::find($empleado->id)->password);
    }

    /**
     * El permiso se puede nombrar por su NOMBRE de pantalla, no solo por el código.
     *
     * @test
     */
    public function el_permiso_se_resuelve_por_su_nombre_de_pantalla()
    {
        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);

        $permiso = PermissionEmpresa::where('slug', 'sale.index')->first();

        if (is_null($permiso)) {
            self::ids_de(['sale.index']);
            $permiso = PermissionEmpresa::where('slug', 'sale.index')->first();
        }

        $empleado = $this->empleado(['client.index']);

        list($conversation, $assistant) = $this->conversacion('Dale el permiso ' . $permiso->name);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_permiso_de_empleado', [
            'empleado' => $empleado->name,
            'permiso'  => $permiso->name,
            'accion'   => 'dar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertStringContainsString($permiso->name, $respuesta['resumen']);
    }

    /**
     * Sacarle un permiso que no tiene, o darle uno que ya tiene, se contesta y no se hace nada.
     *
     * @test
     */
    public function un_cambio_que_no_cambia_nada_se_contesta()
    {
        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);

        $empleado = $this->empleado(['client.index']);

        list($conversation, $assistant) = $this->conversacion('Dale el permiso que ya tiene');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_permiso_de_empleado', [
            'empleado' => $empleado->name,
            'permiso'  => 'client.index',
            'accion'   => 'dar',
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertStringContainsString('ya tiene', (string) $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Un empleado que no es de este negocio no se toca.
     *
     * @test
     */
    public function un_empleado_de_otro_negocio_no_se_encuentra()
    {
        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);

        $otro_dueno = User::create([
            'name'     => 'zz-p54 Otro dueño',
            'email'    => 'otro-dueno-p54-' . uniqid() . '@test.local',
            'password' => Hash::make('x'),
        ]);

        $ajeno = User::create([
            'name'             => 'zz-p54 Empleado ajeno',
            'email'            => 'ajeno-p54-' . uniqid() . '@test.local',
            'password'         => Hash::make('x'),
            'visible_password' => 'x',
            'owner_id'         => $otro_dueno->id,
        ]);

        $this->empleado(['client.index']);

        list($conversation, $assistant) = $this->conversacion('Sacale el permiso');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_permiso_de_empleado', [
            'empleado' => 'zz-p54 Empleado ajeno',
            'permiso'  => 'client.index',
            'accion'   => 'sacar',
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * 🔴 NI EN "DIRECTO" SE HACE SOLA, con las tres guardas medidas: no está en la lista del modo,
     * el filtro la saca igual, y su `case` ni siquiera pasa por la puerta.
     *
     * @test
     */
    public function en_directo_el_permiso_sigue_dejando_tarjeta()
    {
        $this->assertNotContains(AiMessageAction::TIPO_PERMISO_EMPLEADO, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);
        $this->assertNotContains(AiMessageAction::TIPO_PERMISO_EMPLEADO, HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::DIRECTO));
        $this->assertContains(AiMessageAction::TIPO_PERMISO_EMPLEADO, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES);

        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        $desde = strpos($contenido, "case 'proponer_permiso_de_empleado':");

        $this->assertNotFalse($desde, 'proponer_permiso_de_empleado no se despacha.');

        $hasta = strpos($contenido, 'case ', $desde + 10);

        $this->assertStringNotContainsString(
            'quizas_auto_confirmar',
            substr($contenido, $desde, $hasta - $desde),
            'El case pasa por la puerta de auto-confirmación: los permisos SIEMPRE dejan tarjeta.'
        );

        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);

        $this->dueno->agente_confianza = ConfianzaDelAgenteIaHelper::DIRECTO;
        $this->dueno->save();

        $empleado = $this->empleado(['sale.index', 'client.index']);

        list($conversation, $assistant) = $this->conversacion('Sacale ver ventas, no me preguntes nada');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_permiso_de_empleado', [
            'empleado' => $empleado->name,
            'permiso'  => 'sale.index',
            'accion'   => 'sacar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta, 'Una respuesta con "estado" es la de confirmar_del_agente: el permiso no puede haber pasado por ahí.');

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertCount(2, User::find($empleado->id)->permissions, 'El modo directo cambió los permisos solo.');
    }

    /**
     * El payload lleva el modelo entero, con `id` (que es lo que `update()` lee de verdad) y la
     * `visible_password` de hoy.
     *
     * @test
     */
    public function el_payload_lleva_el_modelo_entero()
    {
        $empleado = $this->empleado(['client.index'], 'clave-payload');

        $payload = PropuestaPermisoEmpleadoIaHelper::payload($empleado, PropuestaPermisoEmpleadoIaHelper::permisos_actuales($empleado));

        $this->assertSame((int) $empleado->id, $payload['id'], 'update() lee $request->id, no el {id} de la URL.');
        $this->assertSame('clave-payload', $payload['visible_password']);

        foreach (['name', 'phone', 'doc_number', 'address_id', 'admin_access', 'seller_id', 'permissions'] as $clave) {
            $this->assertArrayHasKey($clave, $payload, 'Falta ' . $clave . ': update() la pisaría con null.');
        }

        $this->assertCount(1, $payload['permissions']);
        $this->assertArrayHasKey('id', $payload['permissions'][0], 'update() lee $permission[\'id\'].');
    }
}
