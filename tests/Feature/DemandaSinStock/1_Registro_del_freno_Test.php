<?php

namespace Tests\Feature\DemandaSinStock;

use App\Models\Address;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\Combo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión demanda-sin-stock-vender — archivo 1: el registro del freno de Vender
 * (POST api/unmet-demand), criterios CA1 a CA4.
 *
 * Cada vez que Vender frena un artículo por falta de stock, la SPA avisa a la
 * API y queda una fila en `unmet_demands`: una señal de que alguien quiso
 * comprar algo que el comercio no tenía. Estos tests le pegan al endpoint real
 * (con sesión de usuario) y verifican el estado resultante en la base, no un
 * mock del helper.
 *
 * Lo que más importa de este archivo:
 *  - multi-tenant: nunca se guarda un artículo de otro dueño ni se atribuye el
 *    freno a un empleado ajeno;
 *  - el antiduplicado de 5 minutos: un vendedor que reintenta no infla la
 *    demanda que después lee el motor de compras.
 */
class Registro_del_freno_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User Dueño del comercio (owner_id null). */
    protected $duenio;

    /** @var User Empleado del dueño. */
    protected $empleado;

    /** @var User Otro empleado del mismo dueño. */
    protected $otro_empleado;

    /** @var User Dueño de otro comercio. */
    protected $otro_duenio;

    /** @var User Empleado del otro comercio. */
    protected $empleado_ajeno;

    /** @var Address Sucursal del dueño. */
    protected $sucursal;

    /** @var Address Segunda sucursal del dueño. */
    protected $otra_sucursal;

    /** @var Article Artículo "Mecha 8" del dueño. */
    protected $mecha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenio = User::create([
            'name'     => 'Duenio demanda D1',
            'email'    => 'demanda-d1-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado demanda D1',
            'email'    => 'demanda-d1-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->duenio->id,
        ]);

        $this->otro_empleado = User::create([
            'name'     => 'Otro empleado demanda D1',
            'email'    => 'demanda-d1-emp2-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->duenio->id,
        ]);

        $this->otro_duenio = User::create([
            'name'     => 'Otro duenio demanda D1',
            'email'    => 'demanda-d1-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->empleado_ajeno = User::create([
            'name'     => 'Empleado ajeno demanda D1',
            'email'    => 'demanda-d1-ajeno-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->otro_duenio->id,
        ]);

        $this->sucursal = Address::create([
            'street'  => 'Sucursal demanda D1',
            'user_id' => $this->duenio->id,
        ]);

        $this->otra_sucursal = Address::create([
            'street'  => 'Otra sucursal demanda D1',
            'user_id' => $this->duenio->id,
        ]);

        $this->mecha = Article::create([
            'name'    => 'Mecha 8',
            'user_id' => $this->duenio->id,
        ]);
    }

    protected function tearDown(): void
    {
        // Los tests que congelan el reloj no pueden contaminar a los demás.
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Arma el body del POST con los datos mínimos de "Mecha 8" y deja pisar
     * cualquier campo.
     *
     * @param array $overrides
     * @return array
     */
    protected function body(array $overrides = [])
    {
        return array_merge([
            'article_id'       => $this->mecha->id,
            'motivo'           => 'sin_stock',
            'cantidad_pedida'  => null,
            'stock_disponible' => 0,
        ], $overrides);
    }

    /**
     * Hace el POST autenticado como $usuario (misma sesión que usa la SPA).
     *
     * @param User $usuario
     * @param array $body
     * @return \Illuminate\Testing\TestResponse
     */
    protected function registrar_como(User $usuario, array $body)
    {
        $this->actingAs($usuario, 'web');

        return $this->postJson('api/unmet-demand', $body);
    }

    /**
     * Filas de unmet_demands del dueño, leídas directo de la base.
     *
     * @param int|null $owner_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas($owner_id = null)
    {
        return DB::table('unmet_demands')
            ->where('user_id', $owner_id ?: $this->duenio->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * CA1: un empleado frena "Mecha 8" sin stock al elegirla (todavía sin
     * cantidad). Queda una fila del dueño, atribuida al empleado.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function registra_sin_stock_con_empleado_y_sucursal()
    {
        $respuesta = $this->registrar_como($this->empleado, $this->body([
            'address_id'  => $this->sucursal->id,
            'employee_id' => 0,
        ]));

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('deduplicado', false);
        $respuesta->assertJsonPath('model.motivo', 'sin_stock');
        $respuesta->assertJsonPath('model.canceled_at', null);

        $filas = $this->filas();
        $this->assertCount(1, $filas);

        $fila = $filas[0];
        $this->assertEquals($respuesta->json('model.id'), $fila->id);
        $this->assertEquals($this->duenio->id, $fila->user_id, 'La fila es del dueño, no del empleado.');
        $this->assertEquals($this->empleado->id, $fila->employee_id, 'Con employee_id 0 se atribuye al usuario autenticado.');
        $this->assertEquals($this->sucursal->id, $fila->address_id);
        $this->assertEquals($this->mecha->id, $fila->article_id);
        $this->assertSame('sin_stock', $fila->motivo);
        $this->assertNull($fila->cantidad_pedida, 'Un freno al elegir todavía no tiene cantidad.');
        $this->assertEquals(0, $fila->stock_disponible);
        $this->assertNotNull($fila->stock_disponible, 'El stock 0 es un dato, no un null.');
        $this->assertNull($fila->canceled_at);
    }

    /**
     * CA1: cuando frena el dueño, la fila lleva el id del dueño como empleado
     * (no null), para que el asistente pueda decir "lo frenó Fulano".
     *
     * @group demanda-sin-stock
     * @test
     */
    public function el_duenio_queda_como_empleado()
    {
        $respuesta = $this->registrar_como($this->duenio, $this->body());

        $respuesta->assertStatus(201);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertEquals($this->duenio->id, $filas[0]->employee_id);
        $this->assertEquals($this->duenio->id, $filas[0]->user_id);
    }

    /**
     * CA2: el freno por stock que no alcanza guarda lo pedido y lo que había.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function registra_stock_insuficiente_con_cantidad_y_stock()
    {
        $respuesta = $this->registrar_como($this->empleado, $this->body([
            'motivo'           => 'stock_insuficiente',
            'cantidad_pedida'  => 5,
            'stock_disponible' => 2,
        ]));

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('deduplicado', false);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertSame('stock_insuficiente', $filas[0]->motivo);
        $this->assertEquals(5.0, $filas[0]->cantidad_pedida);
        $this->assertEquals(2.0, $filas[0]->stock_disponible);
        $this->assertEquals(5.0, $respuesta->json('model.cantidad_pedida'));
        $this->assertEquals(2.0, $respuesta->json('model.stock_disponible'));
    }

    /**
     * CA2: variante y combo se guardan cuando vienen; los ceros de la SPA
     * (variante 0, sucursal 0) se normalizan a null.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function variante_combo_y_ceros_a_null()
    {
        $variante = ArticleVariant::create(['article_id' => $this->mecha->id]);
        $combo = Combo::create([
            'num'     => 1,
            'name'    => 'Combo demanda D1',
            'user_id' => $this->duenio->id,
        ]);

        // Con variante y combo reales: se guardan tal cual.
        $this->registrar_como($this->empleado, $this->body([
            'article_variant_id' => $variante->id,
            'combo_id'           => $combo->id,
        ]))->assertStatus(201);

        $fila = $this->filas()[0];
        $this->assertEquals($variante->id, $fila->article_variant_id);
        $this->assertEquals($combo->id, $fila->combo_id);

        // Con ceros (lo que manda la SPA cuando no eligió nada): quedan null.
        DB::table('unmet_demands')->where('user_id', $this->duenio->id)->delete();

        $this->registrar_como($this->empleado, $this->body([
            'article_variant_id' => 0,
            'combo_id'           => 0,
            'address_id'         => 0,
            'employee_id'        => 0,
        ]))->assertStatus(201);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertNull($filas[0]->article_variant_id);
        $this->assertNull($filas[0]->combo_id);
        $this->assertNull($filas[0]->address_id);
        $this->assertEquals($this->empleado->id, $filas[0]->employee_id);
    }

    /**
     * CA3: un artículo de otro dueño es 422 y no deja ninguna fila.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function articulo_de_otro_duenio_da_422()
    {
        $ajeno = Article::create([
            'name'    => 'Mecha ajena',
            'user_id' => $this->otro_duenio->id,
        ]);

        $respuesta = $this->registrar_como($this->empleado, $this->body(['article_id' => $ajeno->id]));

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('message', 'El artículo no existe.');

        $this->assertSame(0, DB::table('unmet_demands')->count());
    }

    /**
     * CA3: un motivo fuera de la lista es 422 y no deja ninguna fila.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function motivo_invalido_da_422()
    {
        $respuesta = $this->registrar_como($this->empleado, $this->body(['motivo' => 'otro']));

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('message', 'Motivo inválido.');

        $this->assertSame(0, DB::table('unmet_demands')->count());
    }

    /**
     * CA3: sin article_id es 422 (no un error 500) y no deja ninguna fila.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function sin_article_id_da_422()
    {
        $body = $this->body();
        unset($body['article_id']);

        $respuesta = $this->registrar_como($this->empleado, $body);

        $respuesta->assertStatus(422);

        $this->assertSame(0, DB::table('unmet_demands')->count());
    }

    /**
     * CA3: si el employee_id del body es un usuario de OTRO dueño, no se le
     * atribuye el freno: se guarda el usuario autenticado.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function empleado_ajeno_se_reemplaza_por_el_autenticado()
    {
        $this->registrar_como($this->empleado, $this->body([
            'employee_id' => $this->empleado_ajeno->id,
        ]))->assertStatus(201);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertEquals($this->empleado->id, $filas[0]->employee_id);
    }

    /**
     * Sin sesión no se registra nada (el endpoint vive detrás de auth:sanctum).
     *
     * @group demanda-sin-stock
     * @test
     */
    public function sin_sesion_da_401()
    {
        $this->postJson('api/unmet-demand', $this->body())->assertStatus(401);

        $this->assertSame(0, DB::table('unmet_demands')->count());
    }

    /**
     * CA4: el mismo freno repetido a 1 minuto no duplica: el segundo POST
     * responde 200 con el mismo id y la cantidad mayor de los dos.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function mismo_freno_en_5_minutos_no_duplica_y_toma_la_cantidad_mayor()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00'));

        $primero = $this->registrar_como($this->empleado, $this->body([
            'motivo'           => 'stock_insuficiente',
            'address_id'       => $this->sucursal->id,
            'cantidad_pedida'  => 5,
            'stock_disponible' => 2,
        ]));
        $primero->assertStatus(201);
        $primero->assertJsonPath('deduplicado', false);

        Carbon::setTestNow(Carbon::parse('2026-11-21 10:01:00'));

        $segundo = $this->registrar_como($this->empleado, $this->body([
            'motivo'           => 'stock_insuficiente',
            'address_id'       => $this->sucursal->id,
            'cantidad_pedida'  => 7,
            'stock_disponible' => 1,
        ]));
        $segundo->assertStatus(200);
        $segundo->assertJsonPath('deduplicado', true);
        $this->assertEquals($primero->json('model.id'), $segundo->json('model.id'));

        $filas = $this->filas();
        $this->assertCount(1, $filas, 'Dos frenos iguales a 1 minuto son una sola fila.');
        $this->assertEquals(7.0, $filas[0]->cantidad_pedida, 'Gana la cantidad mayor.');
        $this->assertEquals(1.0, $filas[0]->stock_disponible, 'El stock es el del último freno.');

        // Un tercero con menos cantidad no baja lo ya registrado.
        Carbon::setTestNow(Carbon::parse('2026-11-21 10:02:00'));

        $tercero = $this->registrar_como($this->empleado, $this->body([
            'motivo'          => 'stock_insuficiente',
            'address_id'      => $this->sucursal->id,
            'cantidad_pedida' => 3,
        ]));
        $tercero->assertStatus(200);

        $this->assertCount(1, $this->filas());
        $this->assertEquals(7.0, $this->filas()[0]->cantidad_pedida);
    }

    /**
     * CA4: pasados los 5 minutos el mismo freno es una demanda nueva.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function despues_de_5_minutos_crea_otra()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00'));

        $this->registrar_como($this->empleado, $this->body(['address_id' => $this->sucursal->id]))
            ->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-11-21 10:06:00'));

        $segundo = $this->registrar_como($this->empleado, $this->body(['address_id' => $this->sucursal->id]));
        $segundo->assertStatus(201);
        $segundo->assertJsonPath('deduplicado', false);

        $this->assertCount(2, $this->filas());
    }

    /**
     * CA4: otro empleado, otra sucursal u otra variante son demandas
     * distintas aunque lleguen dentro de la ventana.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function otro_empleado_sucursal_o_variante_crea_otra()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00'));

        $variante = ArticleVariant::create(['article_id' => $this->mecha->id]);

        $this->registrar_como($this->empleado, $this->body(['address_id' => $this->sucursal->id]))
            ->assertStatus(201);

        // Otro empleado.
        $this->registrar_como($this->otro_empleado, $this->body(['address_id' => $this->sucursal->id]))
            ->assertStatus(201);

        // Otra sucursal.
        $this->registrar_como($this->empleado, $this->body(['address_id' => $this->otra_sucursal->id]))
            ->assertStatus(201);

        // Otra variante.
        $this->registrar_como($this->empleado, $this->body([
            'address_id'         => $this->sucursal->id,
            'article_variant_id' => $variante->id,
        ]))->assertStatus(201);

        $this->assertCount(4, $this->filas());
    }

    /**
     * CA4: si la fila previa fue cancelada, no absorbe el freno nuevo: el
     * vendedor dijo "me equivoqué" y la próxima vez es una demanda real.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function cancelada_no_absorbe()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00'));

        $primero = $this->registrar_como($this->empleado, $this->body(['address_id' => $this->sucursal->id]));
        $primero->assertStatus(201);

        DB::table('unmet_demands')
            ->where('id', $primero->json('model.id'))
            ->update(['canceled_at' => Carbon::now()]);

        Carbon::setTestNow(Carbon::parse('2026-11-21 10:01:00'));

        $segundo = $this->registrar_como($this->empleado, $this->body(['address_id' => $this->sucursal->id]));
        $segundo->assertStatus(201);
        $segundo->assertJsonPath('deduplicado', false);
        $this->assertNotEquals($primero->json('model.id'), $segundo->json('model.id'));

        $this->assertCount(2, $this->filas());
    }
}
