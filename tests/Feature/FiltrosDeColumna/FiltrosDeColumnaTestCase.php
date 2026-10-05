<?php

namespace Tests\Feature\FiltrosDeColumna;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Base de los tests de la misión filtros-key-sin-inyeccion (5/10/2026).
 *
 * El `key` de un filtro de columna sale del pedido. Hasta esa fecha ColumnFiltersHelper armaba el
 * "que contenga" con `whereRaw($filter['key'].' LIKE ?')`, sin paréntesis: un key como
 * "1=1 OR name" dejaba `user_id = <dueño> AND 1=1 OR name LIKE ?` y traía (o borraba, o
 * modificaba) filas de TODOS los comercios de una base compartida.
 *
 * Lo que aporta esta clase:
 *  - el dueño del fixture (el que EmpresaTestCase deja logueado) y un SEGUNDO DUEÑO REAL (owner_id
 *    null) con filas que existen de verdad: la tenencia se prueba contra un id ajeno que existe, no
 *    contra uno inexistente;
 *  - filtro_spa(): el objeto de filtro ENTERO como lo arma la SPA
 *    (build_table_filters_from_props() de common-vue/mixins/generals.js), no escrito mirando el
 *    helper.
 *
 * PHP 7.4: sin match, str_contains, nullsafe, argumentos nombrados, union types ni promoción de
 * constructor.
 */
abstract class FiltrosDeColumnaTestCase extends EmpresaTestCase
{
    /**
     * Los dos keys inyectados que se prueban en cada entrada: el del hallazgo y una variante con
     * paréntesis (los dos son SQL válido detrás de `whereRaw(key.' LIKE ?')`).
     */
    const KEYS_INYECTADOS = [
        '1=1 OR name',
        '(1=1) OR name',
    ];

    /** @var User El dueño del fixture (logueado). */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Otro comercio de la misma base: un dueño real, sin owner_id.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        return User::create([
            'name'     => 'Otro comercio filtros key',
            'email'    => 'filtros-key-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => null,
        ]);
    }

    /**
     * Un filtro tal como lo arma la SPA en build_table_filters_from_props(): todos los campos
     * inicializados (checkbox -1, en_blanco 0, igual_que 0 en select/search y '' en el resto,
     * order_relation_prop solo en select/search), más el criterio que se le ponga encima.
     *
     * @param  string       $key
     * @param  string|null  $type
     * @param  array        $criterio            Lo que la persona cargó en la lupa.
     * @param  string|null  $relation_prop_name  El `relation_prop_name` de la prop (select/search).
     * @return array
     */
    protected function filtro_spa($key, $type, array $criterio = [], $relation_prop_name = null)
    {
        $es_relacion = ($type == 'select' || $type == 'search');
        $label = ucfirst(str_replace('_', ' ', (string) $key));

        $filtro = [
            'key'                 => $key,
            'label'               => $label,
            'text'                => $label,
            'type'                => $type,
            'order_relation_prop' => $es_relacion ? ($relation_prop_name ? $relation_prop_name : 'name') : null,
            'checkbox'            => -1,
            'en_blanco'           => 0,
            'no_en_blanco'        => 0,
            'que_contenga'        => '',
            'igual_que'           => $es_relacion ? 0 : '',
            'menor_que'           => '',
            'mayor_que'           => '',
        ];

        // Una prop sin `type` (ej: article_properties) viaja sin esa clave: JSON.stringify descarta
        // los undefined.
        if (is_null($type)) {
            unset($filtro['type']);
        }

        return array_merge($filtro, $criterio);
    }

    /**
     * Un artículo activo de $user_id, insertado directo (sin pasar por el alta, que recalcula
     * precios y no hace falta acá).
     *
     * @param  int     $user_id
     * @param  string  $nombre
     * @param  array   $extra
     * @return int  id
     */
    protected function articulo_de($user_id, $nombre, array $extra = [])
    {
        $ahora = date('Y-m-d H:i:s');

        return (int) DB::table('articles')->insertGetId(array_merge([
            'user_id'    => (int) $user_id,
            'name'       => $nombre,
            'status'     => 'active',
            'cost'       => 100,
            'stock'      => 10,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], $extra));
    }

    /**
     * Un cliente de $user_id.
     *
     * @param  int     $user_id
     * @param  string  $nombre
     * @param  array   $extra
     * @return Client
     */
    protected function cliente_de($user_id, $nombre, array $extra = [])
    {
        return Client::create(array_merge([
            'name'    => $nombre,
            'user_id' => (int) $user_id,
        ], $extra));
    }

    /**
     * Los ids de una respuesta de `search` (`models` es una lista) o de `global-search` /
     * `search-from-modal` (`models` es un paginador con `data`).
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return int[]
     */
    protected function ids_de($respuesta)
    {
        $models = $respuesta->json('models');

        if (is_array($models) && array_key_exists('data', $models)) {
            $models = $models['data'];
        }

        return array_map('intval', array_column((array) $models, 'id'));
    }

    /**
     * Verifica que la respuesta sea el 422 de la guarda (FiltroDeColumnaInvalidoException), en JSON.
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @param  string                            $contexto  Para el mensaje del assert.
     * @return void
     */
    protected function assert_rechazo_de_la_guarda($respuesta, $contexto)
    {
        $this->assertSame(
            422,
            $respuesta->getStatusCode(),
            $contexto . ': tenía que dar 422 y dio ' . $respuesta->getStatusCode() . ' — ' . substr((string) $respuesta->getContent(), 0, 300)
        );

        $this->assertTrue((bool) $respuesta->json('filtro_invalido'), $contexto . ': el 422 no es el de la guarda de filtros.');
    }
}
