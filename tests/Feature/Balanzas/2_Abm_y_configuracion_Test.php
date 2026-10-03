<?php

namespace Tests\Feature\Balanzas;

use App\Http\Controllers\CommonLaravel\AuthController;
use App\Models\Article;
use App\Models\Balanza;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión balanzas-configurables (3/10/2026) — el ABM de balanzas (`api/balanza`) y la
 * configuración del dueño (`PUT api/user/{id}` con `tickets_de_balanza`), contra los endpoints
 * reales.
 *
 * Lo que se protege:
 *   - El ABM es por dueño: el alta es del dueño aunque el request diga otra cosa, el listado trae
 *     solo lo suyo y un id ajeno es 404.
 *   - El prefijo se normaliza (solo dígitos) y no se puede repetir para el mismo dueño (422 con el
 *     mensaje del plan). Vacío o de más de 6 dígitos tampoco (422).
 *   - El artículo, si viene, tiene que ser del dueño (ni ajeno, ni inexistente, ni borrado: 422).
 *   - `tickets_de_balanza` lo guarda SOLO el dueño, solo con un valor de la lista blanca, y un
 *     request sin la clave o con null no le borra la elección. El empleado (aun administrador) no
 *     le cambia nada al dueño: `ModelForm` postea el modelo entero, con la columna propia del
 *     empleado.
 *
 * @group balanzas
 */
class Abm_y_configuracion_Test extends EmpresaTestCase
{
    /** @var \App\Models\User */
    protected $dueno;

    /**
     * Toma al dueño del fixture de testing (TestingFerreteriaSeeder): las balanzas y la
     * configuración de cada test se le cargan a él, adentro de la transacción del test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Ayudas
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Un artículo del dueño.
     *
     * @param  string  $nombre
     * @param  array   $extra
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, $extra = [])
    {
        return Article::create(array_merge([
            'name'    => 'zz Balanzas ABM ' . $nombre . ' ' . uniqid(),
            'user_id' => $this->dueno->id,
            'status'  => 'active',
        ], $extra));
    }

    /**
     * Otro comercio (dueño), para verificar el scope.
     *
     * @return \App\Models\User
     */
    protected function otro_dueno()
    {
        return User::create([
            'name'     => 'Otro comercio balanzas ABM',
            'email'    => 'balanzas-abm-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Lo que manda el formulario del ABM de balanzas.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_balanza($overrides = [])
    {
        return array_merge([
            'nombre'     => 'Balanza carnicería',
            'prefijo'    => '22',
            'article_id' => null,
            'tipo_dato'  => 'importe',
            'digitos'    => '',
        ], $overrides);
    }

    /**
     * Exactamente lo que postea `ModelForm` desde Configuración: el modelo entero (leído de la
     * base, con todas sus columnas) más lo que se cambia.
     *
     * @param  \App\Models\User  $user
     * @param  array  $overrides
     * @return array
     */
    protected function payload_usuario($user, $overrides = [])
    {
        return array_merge($user->fresh()->toArray(), $overrides);
    }

    /**
     * `tickets_de_balanza` del dueño, leído directo de la tabla.
     *
     * @return string|null
     */
    protected function modo_del_dueno()
    {
        return DB::table('users')->where('id', $this->dueno->id)->value('tickets_de_balanza');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  ABM
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * El alta deja solo los dígitos del prefijo, es del dueño aunque el request mande otro
     * `user_id`, y devuelve el modelo completo (con su artículo).
     *
     * @test
     */
    public function el_alta_normaliza_el_prefijo_y_es_del_dueno()
    {
        $carniceria = $this->crear_articulo('Carniceria', ['price' => 0]);
        $otro = $this->otro_dueno();

        $response = $this->postJson('api/balanza', $this->payload_balanza([
            'prefijo'    => ' 22 ',
            'article_id' => $carniceria->id,
            'user_id'    => $otro->id,
        ]));

        $response->assertStatus(201);

        $this->assertSame('22', $response->json('model.prefijo'));
        $this->assertSame($this->dueno->id, $response->json('model.user_id'), 'La balanza es del dueño logueado, no del user_id del request.');
        $this->assertSame('Balanza carnicería', $response->json('model.nombre'));
        $this->assertSame($carniceria->id, $response->json('model.article_id'));
        $this->assertEquals($carniceria->id, $response->json('model.article.id'), 'El modelo vuelve con su artículo (fullModel).');
        $this->assertSame('importe', $response->json('model.tipo_dato'));
        $this->assertNull($response->json('model.digitos'), 'Dígitos vacío = el default por tipo.');

        // Con guiones y espacios, y de peso con 5 dígitos.
        $response = $this->postJson('api/balanza', $this->payload_balanza([
            'nombre'    => 'Balanza fiambrería',
            'prefijo'   => '22-03',
            'tipo_dato' => 'peso',
            'digitos'   => '5',
        ]));

        $response->assertStatus(201);

        $this->assertSame('2203', $response->json('model.prefijo'), '22 y 2203 se pisan y está permitido: gana el más largo al leer.');
        $this->assertSame('peso', $response->json('model.tipo_dato'));
        $this->assertSame(5, $response->json('model.digitos'));

        $guardada = Balanza::find($response->json('model.id'));

        $this->assertSame('2203', $guardada->prefijo);
        $this->assertSame($this->dueno->id, $guardada->user_id);
    }

    /**
     * Prefijo repetido para el mismo dueño -> 422 con el mensaje del plan, en el alta y en la
     * edición. Editar una balanza sin cambiarle el prefijo no choca consigo misma, y el prefijo de
     * OTRO dueño no cuenta.
     *
     * @test
     */
    public function el_prefijo_repetido_da_422()
    {
        $primera = $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => '22']));
        $primera->assertStatus(201);

        $repetida = $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => '2-2', 'nombre' => 'Otra']));

        $repetida->assertStatus(422);
        $this->assertSame('Ya tenés otra balanza con el código 22', $repetida->json('message'));
        $this->assertEquals(1, Balanza::where('user_id', $this->dueno->id)->where('prefijo', '22')->count(), 'El 422 no puede haber guardado nada.');

        // Edición que choca con otra.
        $segunda = $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => '23', 'nombre' => 'Segunda']));
        $segunda->assertStatus(201);

        $edicion = $this->putJson('api/balanza/' . $segunda->json('model.id'), $this->payload_balanza(['prefijo' => '22', 'nombre' => 'Segunda']));

        $edicion->assertStatus(422);
        $this->assertSame('Ya tenés otra balanza con el código 22', $edicion->json('message'));
        $this->assertSame('23', Balanza::find($segunda->json('model.id'))->prefijo);

        // Edición que conserva su propio prefijo.
        $this->putJson('api/balanza/' . $primera->json('model.id'), $this->payload_balanza(['prefijo' => '22', 'nombre' => 'Renombrada']))
             ->assertStatus(200)
             ->assertJsonPath('model.nombre', 'Renombrada');

        // El mismo prefijo en OTRO comercio no cuenta.
        $otro = $this->otro_dueno();
        Balanza::create(['user_id' => $otro->id, 'prefijo' => '24', 'tipo_dato' => 'importe']);

        $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => '24', 'nombre' => 'Tercera']))->assertStatus(201);
    }

    /**
     * Un prefijo que queda vacío (se quedaría con todos los códigos no encontrados) o de más de 6
     * dígitos -> 422, sin guardar nada.
     *
     * @test
     */
    public function un_prefijo_vacio_o_de_mas_de_seis_digitos_da_422()
    {
        $antes = Balanza::where('user_id', $this->dueno->id)->count();

        foreach (['abc', '', '1234567'] as $prefijo) {

            $response = $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => $prefijo]));

            $response->assertStatus(422);
            $this->assertNotEmpty($response->json('message'), 'Prefijo "' . $prefijo . '": el 422 tiene que decir por qué.');
        }

        $this->assertEquals($antes, Balanza::where('user_id', $this->dueno->id)->count());
    }

    /**
     * 🔴 El artículo de la balanza tiene que ser del dueño. En una base compartida, un POST o PUT
     * armado a mano con el `article_id` de OTRO comercio se guardaba, y el ABM le mostraba ese
     * artículo ajeno. Ahora: ajeno, inexistente o borrado -> 422 con el mensaje, y no se guarda
     * nada. Sin artículo (null) se sigue aceptando, y con uno propio la edición pasa.
     *
     * @test
     */
    public function el_articulo_tiene_que_ser_del_dueno()
    {
        $mensaje = 'El artículo elegido no existe o no es de este comercio.';

        $otro = $this->otro_dueno();
        $ajeno = $this->crear_articulo('Ajeno', ['user_id' => $otro->id]);

        $antes = Balanza::count();

        // Alta con el artículo de otro comercio.
        $alta = $this->postJson('api/balanza', $this->payload_balanza(['article_id' => $ajeno->id]));

        $alta->assertStatus(422);
        $this->assertSame($mensaje, $alta->json('message'));
        $this->assertEquals($antes, Balanza::count(), 'El 422 no puede haber guardado nada.');

        // Alta con un artículo que no existe.
        $inexistente = (int) Article::withTrashed()->max('id') + 1000;

        $this->postJson('api/balanza', $this->payload_balanza(['article_id' => $inexistente]))
             ->assertStatus(422)
             ->assertJsonPath('message', $mensaje);

        // Alta con un artículo propio pero borrado.
        $borrado = $this->crear_articulo('Borrado');
        $borrado->delete();

        $this->postJson('api/balanza', $this->payload_balanza(['article_id' => $borrado->id]))
             ->assertStatus(422)
             ->assertJsonPath('message', $mensaje);

        $this->assertEquals($antes, Balanza::count(), 'Ninguno de los tres 422 guardó nada.');

        // Sin artículo: se sigue aceptando.
        $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => '21', 'article_id' => null]))
             ->assertStatus(201);

        // Edición: con el artículo ajeno, 422 y la balanza queda como estaba.
        $propio = $this->crear_articulo('Propio');

        $balanza_id = $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => '23', 'article_id' => $propio->id]))
                           ->assertStatus(201)
                           ->json('model.id');

        $edicion = $this->putJson('api/balanza/' . $balanza_id, $this->payload_balanza(['prefijo' => '23', 'article_id' => $ajeno->id]));

        $edicion->assertStatus(422);
        $this->assertSame($mensaje, $edicion->json('message'));
        $this->assertSame($propio->id, Balanza::find($balanza_id)->article_id, 'La balanza sigue con su artículo.');

        // Y con otro artículo propio, la edición pasa.
        $otro_propio = $this->crear_articulo('Otro propio');

        $this->putJson('api/balanza/' . $balanza_id, $this->payload_balanza(['prefijo' => '23', 'article_id' => $otro_propio->id]))
             ->assertStatus(200)
             ->assertJsonPath('model.article_id', $otro_propio->id);
    }

    /**
     * El listado trae solo las del dueño, y un id ajeno es 404 para show, edición y baja. La baja
     * de una propia la saca del listado.
     *
     * @test
     */
    public function el_index_trae_solo_las_del_dueno()
    {
        $otro = $this->otro_dueno();
        $ajena = Balanza::create(['user_id' => $otro->id, 'nombre' => 'Ajena', 'prefijo' => '22', 'tipo_dato' => 'importe']);

        $propia_22 = $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => '22']))->json('model.id');
        $propia_23 = $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => '23', 'nombre' => 'Verdulería']))->json('model.id');

        $response = $this->getJson('api/balanza');
        $response->assertStatus(200);

        $ids = [];
        foreach ($response->json('models') as $model) {
            $ids[] = $model['id'];
            $this->assertSame($this->dueno->id, $model['user_id']);
        }

        $this->assertEquals([$propia_22, $propia_23], $ids, 'Solo las del dueño, ordenadas por prefijo.');

        $this->getJson('api/balanza/' . $propia_22)->assertStatus(200)->assertJsonPath('model.prefijo', '22');

        $this->getJson('api/balanza/' . $ajena->id)->assertStatus(404);
        $this->putJson('api/balanza/' . $ajena->id, $this->payload_balanza(['prefijo' => '29']))->assertStatus(404);
        $this->deleteJson('api/balanza/' . $ajena->id)->assertStatus(404);
        $this->assertSame('22', $ajena->fresh()->prefijo, 'La balanza ajena no se toca.');

        $this->deleteJson('api/balanza/' . $propia_22)->assertStatus(200);
        $this->assertNull(Balanza::find($propia_22));

        $ids = [];
        foreach ($this->getJson('api/balanza')->json('models') as $model) {
            $ids[] = $model['id'];
        }

        $this->assertEquals([$propia_23], $ids);
    }

    /**
     * Baja en recursos-iniciales, idéntica a su endpoint (la SPA la necesita para leer sin conexión).
     *
     * @test
     */
    public function recursos_iniciales_incluye_las_balanzas()
    {
        $this->postJson('api/balanza', $this->payload_balanza(['prefijo' => '22']))->assertStatus(201);

        $response = $this->postJson('api/recursos-iniciales', ['models' => ['balanza']]);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('no_soportados'));
        $this->assertEquals([], $response->json('con_error'));
        $this->assertCount(1, $response->json('models.balanza.models'));
        $this->assertEquals($this->getJson('api/balanza')->json('models'), $response->json('models.balanza.models'));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Configuración del dueño
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * El dueño guarda `tickets_de_balanza` desde Configuración (el PUT del modelo entero), con
     * cualquiera de los tres valores de la lista, y la respuesta lo trae.
     *
     * @test
     */
    public function el_dueno_guarda_tickets_de_balanza()
    {
        foreach (['balanzas', 'plu', 'ninguno'] as $modo) {

            $response = $this->putJson('api/user/' . $this->dueno->id, $this->payload_usuario($this->dueno, [
                'tickets_de_balanza' => $modo,
            ]));

            $response->assertStatus(200);

            $this->assertSame($modo, $this->modo_del_dueno(), 'El PUT de configuración tiene que persistir ' . $modo . ' en el dueño.');
            $this->assertSame($modo, $response->json('model.tickets_de_balanza'), 'La respuesta trae el usuario con la configuración nueva.');
        }
    }

    /**
     * Un valor fuera de la lista, null, vacío o la clave ausente NO cambian lo que el dueño eligió.
     *
     * @test
     */
    public function un_valor_fuera_de_lista_o_null_se_ignora()
    {
        DB::table('users')->where('id', $this->dueno->id)->update(['tickets_de_balanza' => 'plu']);

        foreach (['cualquiera', null, '', 'PLU'] as $valor) {

            $this->putJson('api/user/' . $this->dueno->id, $this->payload_usuario($this->dueno, [
                'tickets_de_balanza' => $valor,
            ]))->assertStatus(200);

            $this->assertSame('plu', $this->modo_del_dueno(), 'El valor ' . var_export($valor, true) . ' no puede cambiar la configuración.');
        }

        // Sin la clave (un request viejo).
        $payload = $this->payload_usuario($this->dueno);
        unset($payload['tickets_de_balanza']);

        $this->putJson('api/user/' . $this->dueno->id, $payload)->assertStatus(200);

        $this->assertSame('plu', $this->modo_del_dueno(), 'Un request sin la clave no puede borrar la configuración.');
    }

    /**
     * 🔴 Un empleado (administrador: pasa el middleware solo_administrador) que guarda
     * Configuración NO le cambia nada al dueño, aunque su modelo traiga la columna con otro valor.
     * Y el empleado recibe la configuración del dueño adentro de `owner` (set_employee_props).
     *
     * @test
     */
    public function el_empleado_no_le_cambia_nada_al_dueno()
    {
        DB::table('users')->where('id', $this->dueno->id)->update(['tickets_de_balanza' => 'balanzas']);

        $empleado = User::create([
            'name'         => 'Empleado balanzas',
            'email'        => 'balanzas-empleado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->dueno->id,
            'admin_access' => 1,
        ]);

        $this->actingAs($empleado, 'web');

        foreach (['ninguno', 'plu'] as $valor) {

            $this->putJson('api/user/' . $empleado->id, $this->payload_usuario($empleado, [
                'tickets_de_balanza' => $valor,
            ]))->assertStatus(200);

            $this->assertSame('balanzas', $this->modo_del_dueno(), 'El empleado no puede pisarle al dueño cómo se leen las balanzas.');
        }

        $this->assertNull(DB::table('users')->where('id', $empleado->id)->value('tickets_de_balanza'), 'Tampoco se escribe en la fila del empleado, que nadie lee.');

        // Lo que recibe la SPA del empleado al loguearse: la configuración del dueño en `owner`.
        $con_props = (new AuthController())->set_employee_props(User::find($empleado->id));

        $this->assertSame('balanzas', $con_props->toArray()['owner']['tickets_de_balanza']);
    }
}
