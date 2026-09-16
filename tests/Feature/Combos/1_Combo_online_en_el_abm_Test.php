<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\combo\ComboAltaHelper;
use App\Models\Article;
use App\Models\Combo;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `combos.online` en el ABM de combos (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * EL AGUJERO QUE FIJAN ESTOS TESTS, y por que existe este archivo:
 *
 * El alta de un combo dejo de estar en `ComboController::store()` y paso a
 * `ComboAltaHelper::crear()` (mision agente-ia-mano-derecha, f578ba06), porque el asistente de IA
 * tambien da de alta combos. Esta mision habia agregado su `online` justo adentro del bloque que
 * ese refactor se llevo, asi que al mergear hubo conflicto — y el cambio GEMELO de `update()`
 * (`$model->online = $request->online ? 1 : 0;`) mergeo limpio, fuera del conflicto.
 *
 * O sea que la resolucion obvia del conflicto —quedarse con el refactor, que ademas es lo
 * correcto— perdia el `online` SOLO en el alta. El sintoma no es un error: es un combo creado con
 * "Mostrar en la tienda" tildado que nace con `online = 0`, responde 201, queda en el listado, y
 * se publica recien si el dueño lo reabre y lo vuelve a guardar. Editar anda, crear no, no hay
 * excepcion en ningun log y ningun test se pone rojo.
 *
 * Son DOS puntas y por eso hay un test para cada una: si alguna se cae, el agujero vuelve.
 *
 *  - `crear_por_el_endpoint_con_online_en_1_deja_el_combo_publicado`: la punta del controller. Lo
 *    que `$request->only([...])` no nombra no llega al helper.
 *  - `el_helper_del_alta_escribe_el_online_que_le_pasan`: la punta del helper. El `Combo::create`
 *    tiene que incluir la columna.
 *
 * Y el criterio de normalizacion se mira en los dos verbos (`crear_*` y `editar_*`) porque tiene
 * que ser EL MISMO: si crear y editar interpretaran distinto el mismo valor, el combo diria una
 * cosa al nacer y otra al guardarse.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un
 * refresh la vaciaria. Mismo criterio que el resto de la mision.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Combo_online_en_el_abm_Test extends TestCase
{
    use DatabaseTransactions;

    /** Precio del combo de prueba. */
    const PRECIO = 1500;

    /** Costo del combo de prueba. */
    const COSTO = 800;

    /** Unidades del componente que lleva un combo (`article_combo.amount`). */
    const UNIDADES_POR_COMBO = 2;

    /**
     * Autentica al usuario de testing, o saltea el test si la base no lo tiene sembrado.
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = User::find(500);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Articulo componente del combo.
     *
     * @return \App\Models\Article
     */
    protected function articulo_de_testing()
    {
        return Article::create([
            'name'       => 'zz Componente de combo online '.uniqid(),
            'user_id'    => 500,
            'costo_real' => 50,
            'stock'      => 20,
        ]);
    }

    /**
     * Payload de `POST api/combo` en la forma exacta que manda el modal de Combos del Listado:
     * cada articulo con `id` y `pivot.amount` (que es por donde lo lee
     * `GeneralHelper::attachModels`).
     *
     * @param \App\Models\Article $article
     * @param array $overrides Claves extra o pisadas (aca entra `online`, o su ausencia).
     * @return array
     */
    protected function payload_crear($article, $overrides = [])
    {
        return array_merge([
            'name'     => 'zz Combo online '.uniqid(),
            'cost'     => self::COSTO,
            'price'    => self::PRECIO,
            'articles' => [
                [
                    'id'    => $article->id,
                    'pivot' => ['amount' => self::UNIDADES_POR_COMBO],
                ],
            ],
        ], $overrides);
    }

    /**
     * El `online` tal como quedo ESCRITO en la base, nunca el del modelo en memoria: lo que
     * interesa es la fila, no lo que Eloquent tenga cargado.
     *
     * @param int $combo_id
     * @return int|null
     */
    protected function online_en_la_base($combo_id)
    {
        $valor = DB::table('combos')->where('id', $combo_id)->value('online');

        return is_null($valor) ? null : (int) $valor;
    }

    /**
     * 🔴 EL TEST QUE TAPA EL AGUJERO DEL MERGE, punta del controller.
     *
     * Se pone rojo si `online` se cae del `$request->only([...])` de `ComboController::store()`,
     * que es exactamente lo que pasaba al resolver el conflicto quedandose con el refactor.
     *
     * @test
     */
    public function crear_por_el_endpoint_con_online_en_1_deja_el_combo_publicado()
    {
        $this->autenticar();

        $article = $this->articulo_de_testing();

        $respuesta = $this->post('api/combo', $this->payload_crear($article, ['online' => 1]));

        $respuesta->assertStatus(201);

        $combo_id = $respuesta->json('model.id');

        $this->assertNotNull($combo_id, 'el alta tiene que devolver el combo creado');

        $this->assertSame(
            1,
            $this->online_en_la_base($combo_id),
            'un combo creado con "Mostrar en la tienda" tildado tiene que nacer publicado: si esto '
            .'esta en 0, el `online` no llego hasta el Combo::create y editar anda pero crear no'
        );

        // El mismo dato en la respuesta: la SPA pinta el check con lo que le devuelve el alta, asi
        // que un `online` que quedo bien en la base pero no vuelve tambien miente en pantalla.
        $this->assertEquals(1, $respuesta->json('model.online'));
    }

    /**
     * Sin la clave, el combo nace APAGADO. Es la direccion segura y es lo que ve una empresa-spa
     * vieja, sin el check en el ABM: nadie estrena combos en su tienda sin haberlo decidido.
     *
     * @test
     */
    public function crear_por_el_endpoint_sin_mandar_online_deja_el_combo_apagado()
    {
        $this->autenticar();

        $article = $this->articulo_de_testing();

        $payload = $this->payload_crear($article);

        $this->assertArrayNotHasKey('online', $payload, 'este caso es justamente el de la clave ausente');

        $respuesta = $this->post('api/combo', $payload);

        $respuesta->assertStatus(201);

        $this->assertSame(
            0,
            $this->online_en_la_base($respuesta->json('model.id')),
            'sin la clave el combo no se publica solo'
        );
    }

    /**
     * Mandar `online = 0` tambien deja el combo apagado.
     *
     * No es el mismo caso que el anterior y por eso esta aparte: el de arriba tambien pasaria con
     * una normalizacion tipo "si la clave vino, 1". Este obliga a mirar el VALOR.
     *
     * @test
     */
    public function crear_por_el_endpoint_con_online_en_0_deja_el_combo_apagado()
    {
        $this->autenticar();

        $article = $this->articulo_de_testing();

        $respuesta = $this->post('api/combo', $this->payload_crear($article, ['online' => 0]));

        $respuesta->assertStatus(201);

        $this->assertSame(0, $this->online_en_la_base($respuesta->json('model.id')));
    }

    /**
     * 🔴 EL TEST QUE TAPA EL AGUJERO DEL MERGE, punta del helper.
     *
     * Se pone rojo si `online` se cae del `Combo::create` de `ComboAltaHelper::crear()`. Va contra
     * el helper y no contra el endpoint a proposito: asi las dos puntas se caen por separado y el
     * rojo dice cual de las dos fue.
     *
     * @test
     */
    public function el_helper_del_alta_escribe_el_online_que_le_pasan()
    {
        $this->autenticar();

        $article = $this->articulo_de_testing();

        $combo = ComboAltaHelper::crear([
            'name'     => 'zz Combo directo al helper '.uniqid(),
            'cost'     => self::COSTO,
            'price'    => self::PRECIO,
            'online'   => 1,
            'articles' => [
                ['id' => $article->id, 'pivot' => ['amount' => self::UNIDADES_POR_COMBO]],
            ],
        ], 500, 9600);

        $this->assertSame(1, $this->online_en_la_base($combo->id));
    }

    /**
     * 🔴 LA DECISION SOBRE EL CAMINO DEL ASISTENTE DE IA, clavada.
     *
     * `PropuestaComboIaHelper` arma su array con `name`, `cost`, `price` y `articles`, y NO manda
     * `online`. Eso es a proposito: el combo que el asistente arma a partir de una frase dictada
     * nace APAGADO, y el dueño lo publica desde el ABM si quiere. Publicar en la tienda expone
     * precio y receta a los compradores — es una decision comercial, no la consecuencia de haber
     * pedido un combo por chat.
     *
     * Se prueba contra el helper con el MISMO array que arma el asistente (la clave ausente), que
     * es lo que hace falta para que la decision no se vuelva un accidente. Ademas verifica que la
     * clave ausente no rompa el INSERT: `combos.online` es NOT NULL, asi que una asignacion pelada
     * de null moriria aca.
     *
     * Si algun dia el asistente tiene que poder publicar, este test cambia en el mismo diff que la
     * clave — que es justamente el punto.
     *
     * @test
     */
    public function el_combo_que_crea_el_asistente_de_ia_nace_apagado()
    {
        $this->autenticar();

        $article = $this->articulo_de_testing();

        // Calcado de PropuestaComboIaHelper::proponer(): estas cuatro claves y ninguna mas.
        $datos = [
            'name'     => 'zz Combo del asistente '.uniqid(),
            'cost'     => self::COSTO,
            'price'    => self::PRECIO,
            'articles' => [
                ['id' => $article->id, 'pivot' => ['amount' => self::UNIDADES_POR_COMBO]],
            ],
        ];

        $this->assertArrayNotHasKey('online', $datos, 'el asistente no manda la clave, y de eso se trata');

        $combo = ComboAltaHelper::crear($datos, 500, 9601);

        $this->assertSame(
            0,
            $this->online_en_la_base($combo->id),
            'el combo dictado al asistente no se publica solo en la tienda'
        );
    }

    /**
     * Editar prende y apaga, y el valor que queda es el ultimo que mando la pantalla.
     *
     * Este camino nunca estuvo roto —mergeo limpio, fuera del conflicto— y justamente por eso
     * estaba el agujero: crear y editar no decian lo mismo. Queda medido para que el criterio de
     * los dos verbos se pueda comparar de un vistazo.
     *
     * @test
     */
    public function editar_un_combo_lo_prende_y_lo_apaga()
    {
        $user = $this->autenticar();

        $article = $this->articulo_de_testing();

        $combo = Combo::create([
            'num'     => 9602,
            'name'    => 'zz Combo a editar '.uniqid(),
            'price'   => self::PRECIO,
            'cost'    => self::COSTO,
            'user_id' => $user->id,
        ]);

        $combo->articles()->attach($article->id, ['amount' => self::UNIDADES_POR_COMBO]);

        $this->assertSame(0, $this->online_en_la_base($combo->id), 'arranca apagado, que es el default de la columna');

        $payload = [
            'name'     => $combo->name,
            'cost'     => self::COSTO,
            'price'    => self::PRECIO,
            'articles' => [
                ['id' => $article->id, 'pivot' => ['amount' => self::UNIDADES_POR_COMBO]],
            ],
        ];

        $prender = array_merge($payload, ['online' => 1]);

        $this->put('api/combo/'.$combo->id, $prender)->assertStatus(200);

        $this->assertSame(1, $this->online_en_la_base($combo->id), 'prenderlo lo publica');

        $apagar = array_merge($payload, ['online' => 0]);

        $this->put('api/combo/'.$combo->id, $apagar)->assertStatus(200);

        $this->assertSame(0, $this->online_en_la_base($combo->id), 'apagarlo lo saca de la tienda');
    }
}
