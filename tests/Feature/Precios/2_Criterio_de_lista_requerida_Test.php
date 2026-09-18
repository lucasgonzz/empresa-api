<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria (17/9/2026): el criterio de "esta cuenta tiene que llevar lista
 * en cada venta y presupuesto", que vive en `PriceTypeHelper::requiere_lista_de_precios()` y es
 * el único lugar del back donde se decide.
 *
 * Lo que protege: que la regla se ancle en `users.listas_de_precio` DEL DUEÑO y en nada más. Las
 * tres cosas que la apagan tienen un caso real cada una: el flag en 0 (golonorte, que lleva la
 * lista por línea y manda null o 0 en `sales.price_type_id`), la extensión de rangos (el front no
 * setea lista a propósito) y la cuenta con el flag pero sin una sola lista cargada (no hay qué
 * elegir). Y el empleado: el flag, las extensiones y las listas cuelgan del dueño, así que un
 * empleado de Trama tiene que recibir el mismo 422 que Trama.
 *
 * Los otros dos métodos del helper (`normalizar_price_type_id()` y
 * `resolver_price_type_id_para_guardar()`) se fijan acá también porque son puros: el 0 que se lee
 * como null y el rescate del cliente son decisiones de producto, no detalles de implementación.
 *
 * DatabaseTransactions sobre la base sembrada; `users.listas_de_precio` se guarda en setUp y se
 * restaura en tearDown además del rollback. Sin `actingAs` en el setUp a propósito: el helper
 * recibe el usuario explícito, y un test mide qué pasa sin usuario.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Criterio_de_lista_requerida_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var string Mismo slug que la SPA y `PriceTypeHelper::EXTENCION_RANGOS`. */
    const EXTENCION_RANGOS = 'lista_de_precios_por_rango_de_cantidad_vendida';

    /** @var \App\Models\User */
    protected $user;

    /** @var int|null Valor original de users.listas_de_precio. */
    protected $listas_original = null;

    /** @var \App\Models\PriceType */
    protected $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->listas_original = $this->user->listas_de_precio;

        $this->lista = PriceType::create([
            'name'     => 'zz Lista del criterio',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if (!is_null($this->user) && !is_null($this->listas_original)) {
            User::where('id', self::USER_ID)->update(['listas_de_precio' => $this->listas_original]);
        }

        parent::tearDown();
    }

    /**
     * Prende o apaga las listas de la cuenta y devuelve al dueño RECARGADO: el helper lee el flag
     * y las extensiones del modelo que le pasan, y una instancia vieja tendría la relación
     * `extencions` cacheada.
     *
     * @param  int  $usa
     * @return \App\Models\User
     */
    protected function dueno_con_listas($usa)
    {
        User::where('id', self::USER_ID)->update(['listas_de_precio' => $usa]);

        return User::find(self::USER_ID);
    }

    /**
     * Le da a la cuenta la extensión de rangos, creando la fila del catálogo si hace falta
     * (forceCreate: el modelo no declara $fillable).
     *
     * @return void
     */
    protected function dar_extension_de_rangos()
    {
        $extencion = ExtencionEmpresa::where('slug', self::EXTENCION_RANGOS)->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::EXTENCION_RANGOS,
                'name' => 'Lista de precios por rango de cantidad vendida',
            ]);
        }

        if (!$this->user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $this->user->extencions()->attach($extencion->id);
        }
    }

    /**
     * Un empleado del usuario 500, recién creado (mismo molde que tests/Feature/Horarios/1). Se
     * devuelve RECARGADO por id en cada llamada: la relación `owner` se cachea en la instancia, y
     * un test que cambia el flag del dueño entre dos preguntas necesita un empleado que vuelva a
     * leerlo.
     *
     * @return \App\Models\User
     */
    protected function empleado()
    {
        $empleado = User::create([
            'name'         => 'zz Empleado del criterio',
            'company_name' => 'zz Empresa del criterio',
            'email'        => 'zz-criterio-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => self::USER_ID,
        ]);

        return User::find($empleado->id);
    }

    /**
     * El caso de Trama: flag prendido, listas cargadas, sin la extensión de rangos → se exige.
     *
     * @group precios-por-lista
     * @test
     */
    public function con_el_flag_listas_cargadas_y_sin_la_extension_se_exige_lista()
    {
        $this->assertTrue(PriceTypeHelper::requiere_lista_de_precios($this->dueno_con_listas(1)));
    }

    /**
     * El caso de golonorte: flag apagado → no se exige, aunque la cuenta tenga listas cargadas
     * (las usa por línea, vía `price_type_personalizado_id`).
     *
     * @group precios-por-lista
     * @test
     */
    public function sin_el_flag_no_se_exige_aunque_haya_listas_cargadas()
    {
        $this->assertFalse(PriceTypeHelper::requiere_lista_de_precios($this->dueno_con_listas(0)));
    }

    /**
     * Con la extensión de rangos la lista se decide por cantidad vendida y el front no setea lista
     * de venta: no se exige, aunque el flag esté prendido y haya listas.
     *
     * @group precios-por-lista
     * @test
     */
    public function con_la_extension_de_rangos_no_se_exige()
    {
        $this->dar_extension_de_rangos();

        $this->assertFalse(PriceTypeHelper::requiere_lista_de_precios($this->dueno_con_listas(1)));
    }

    /**
     * Flag prendido pero sin una sola lista cargada: no hay qué elegir, no se exige. Las listas del
     * usuario 500 se borran adentro de la transacción (no hay FK sobre price_type_id).
     *
     * @group precios-por-lista
     * @test
     */
    public function con_el_flag_pero_sin_ninguna_lista_cargada_no_se_exige()
    {
        PriceType::where('user_id', self::USER_ID)->delete();

        $this->assertFalse(PriceTypeHelper::requiere_lista_de_precios($this->dueno_con_listas(1)));
    }

    /**
     * 🔴 Un empleado hereda el criterio de su dueño en los dos sentidos: con el flag del dueño
     * prendido se le exige lista (es el vendedor de Trama que hizo 7 ventas seguidas a costo el
     * 10/9), y con el flag apagado no. El empleado en sí no tiene flag, extensiones ni listas.
     *
     * @group precios-por-lista
     * @test
     */
    public function un_empleado_hereda_el_criterio_de_su_dueno()
    {
        $this->dueno_con_listas(1);

        $this->assertTrue(
            PriceTypeHelper::requiere_lista_de_precios($this->empleado()),
            'El empleado de una cuenta con listas tiene que recibir el mismo criterio que el dueño.'
        );

        $this->dueno_con_listas(0);

        $this->assertFalse(
            PriceTypeHelper::requiere_lista_de_precios($this->empleado()),
            'Y con el flag del dueño apagado, tampoco se le exige al empleado.'
        );
    }

    /**
     * Sin usuario pasado ni autenticado no hay cuenta a la que exigirle nada: false, sin reventar.
     *
     * @group precios-por-lista
     * @test
     */
    public function sin_usuario_no_se_exige()
    {
        $this->assertFalse(PriceTypeHelper::requiere_lista_de_precios(null));
    }

    /**
     * `normalizar_price_type_id()`: lo único que puede ser un id es un entero positivo. null, '',
     * 0 y '0' son "ninguna" (el 0 es lo que manda golonorte); lo no numérico, lo negativo, los
     * booleanos y los arrays también, porque no hay lista que se llame así.
     *
     * @group precios-por-lista
     * @test
     */
    public function normalizar_price_type_id_solo_acepta_enteros_positivos()
    {
        $ninguna = [null, '', 0, '0', 0.0, -1, '-3', 'abc', false, true, [], ['id' => 3]];

        foreach ($ninguna as $valor) {
            $this->assertNull(
                PriceTypeHelper::normalizar_price_type_id($valor),
                'Tendria que leerse como ninguna lista: '.var_export($valor, true)
            );
        }

        $this->assertSame(5, PriceTypeHelper::normalizar_price_type_id(5));
        $this->assertSame(5, PriceTypeHelper::normalizar_price_type_id('5'));
        $this->assertSame(5, PriceTypeHelper::normalizar_price_type_id(5.0));
        $this->assertSame(12, PriceTypeHelper::normalizar_price_type_id(' 12'));
    }

    /**
     * `resolver_price_type_id_para_guardar()`: primero el request, después el cliente, y si no
     * null. El cliente con lista en 0 cuenta como cliente sin lista. Los clientes van sin guardar
     * (el resolvedor solo lee `price_type_id`).
     *
     * @group precios-por-lista
     * @test
     */
    public function resolver_price_type_id_para_guardar_prefiere_el_request_y_despues_el_cliente()
    {
        $cliente_con_lista = new Client(['price_type_id' => 3]);
        $cliente_sin_lista = new Client(['price_type_id' => null]);
        $cliente_con_cero  = new Client(['price_type_id' => 0]);

        $this->assertSame(7, PriceTypeHelper::resolver_price_type_id_para_guardar(7, $cliente_con_lista));
        $this->assertSame(7, PriceTypeHelper::resolver_price_type_id_para_guardar('7', null));

        $this->assertSame(3, PriceTypeHelper::resolver_price_type_id_para_guardar(null, $cliente_con_lista));
        $this->assertSame(3, PriceTypeHelper::resolver_price_type_id_para_guardar(0, $cliente_con_lista));
        $this->assertSame(3, PriceTypeHelper::resolver_price_type_id_para_guardar('', $cliente_con_lista));

        $this->assertNull(PriceTypeHelper::resolver_price_type_id_para_guardar(null, $cliente_sin_lista));
        $this->assertNull(PriceTypeHelper::resolver_price_type_id_para_guardar(null, $cliente_con_cero));
        $this->assertNull(PriceTypeHelper::resolver_price_type_id_para_guardar(null, null));
        $this->assertNull(PriceTypeHelper::resolver_price_type_id_para_guardar(0, null));
    }

    /**
     * Los dos mensajes nombran la lista de precios, dicen qué documento es y piden recargar la
     * página (lo que destraba el catálogo que no llegó y lo que trae el bundle nuevo).
     *
     * @group precios-por-lista
     * @test
     */
    public function los_mensajes_nombran_la_lista_el_documento_y_piden_recargar()
    {
        $venta = PriceTypeHelper::mensaje_sin_lista();
        $presupuesto = PriceTypeHelper::mensaje_sin_lista_presupuesto();

        $this->assertStringContainsString('listas de precios', $venta);
        $this->assertStringContainsString('la venta', $venta);
        $this->assertStringContainsString('recargá la página', $venta);

        $this->assertStringContainsString('listas de precios', $presupuesto);
        $this->assertStringContainsString('el presupuesto', $presupuesto);
        $this->assertStringContainsString('recargá la página', $presupuesto);

        $this->assertNotEquals($venta, $presupuesto);
    }
}
