<?php

namespace Tests\Feature\CategoryProposals;

use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalNombreHelper;
use App\Models\Article;
use App\Models\CategoryProposal;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalNode;
use App\Models\CategoryProposalRun;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Base de los tests de la categorización con IA en tres modelos (misión categorizacion-tres-modelos,
 * 5/10/2026).
 *
 * 🔴 SOLO LA EDITA EL ORQUESTADOR. Los constructores que necesiten más ayudas las ponen en su propio
 * archivo de test o en un trait propio: dos constructores en el mismo worktree no pueden pisarse esta
 * clase.
 *
 * Cada test arranca con DOS comercios nuevos (el dueño del test y un "vecino" con sus propios datos:
 * es lo que prueba la tenencia) y con la clave de `admin-sync` fijada por `config()`.
 *
 * 🔴 `app.USER_ID` se fija al comercio del test: el `.env.testing` del slot trae USER_ID=500 y
 * `AsistenteCanalHelper::dueno()` (el dueño de los endpoints `admin-sync`) resolvería al fixture 500
 * en vez de a este comercio. Para escribir como el vecino, `como_dueno_de_admin_sync($this->vecino)`.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
abstract class CategoryProposalsTestCase extends EmpresaTestCase
{
    /** La clave de `admin-sync` de mentira que fija `setUp()` en `config('services.admin_api.api_key')`. */
    const CLAVE_DE_ADMIN = 'CLAVE-DE-ADMIN-DE-PRUEBA';

    /** @var \App\Models\User Comercio nuevo de cada test (el que actúa). */
    protected $owner;

    /** @var \App\Models\User Segundo comercio con sus propios datos, para las pruebas de tenencia. */
    protected $vecino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner  = $this->crear_comercio('Comercio de categorias');
        $this->vecino = $this->crear_comercio('Comercio vecino de categorias');

        config([
            'services.admin_api.api_key'         => self::CLAVE_DE_ADMIN,
            'services.admin_api.require_api_key' => false,
            'app.USER_ID'                        => $this->owner->id,
            'catalogo_ia.tope_articulos'         => 10000,
        ]);

        $this->actuar_como($this->owner);
    }

    /**
     * Un comercio nuevo (dueño: `owner_id` NULL). El DatabaseTransactions de EmpresaTestCase lo borra.
     *
     * @param  string $nombre
     * @return \App\Models\User
     */
    protected function crear_comercio($nombre)
    {
        return User::create([
            'name'         => $nombre,
            'company_name' => $nombre,
            'email'        => 'categorias-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Un empleado del comercio dado (`owner_id` apunta al dueño).
     *
     * @param  \App\Models\User $dueno
     * @param  bool $con_admin_access  Un empleado con `admin_access` NO puede gestionar propuestas.
     * @return \App\Models\User
     */
    protected function crear_empleado_de($dueno, $con_admin_access = false)
    {
        return User::create([
            'name'         => 'Empleado de '.$dueno->name,
            'company_name' => $dueno->company_name,
            'email'        => 'empleado-categorias-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $dueno->id,
            'admin_access' => $con_admin_access ? 1 : 0,
        ]);
    }

    /**
     * Autentica para los requests que siguen (rutas del SPA), opcionalmente con la sesión del acceso
     * maestro.
     *
     * 🔴 El forgetGuards() no es decorativo: Sanctum cachea el usuario que resolvió la primera vez
     * dentro del mismo container (memoria `tests-el-guard-queda-en-sanctum-despues-de-un-request`).
     *
     * @param  \App\Models\User $user
     * @param  bool $maestro
     * @return void
     */
    protected function actuar_como($user, $maestro = false)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');

        if ($maestro) {
            $this->withSession([ImageAssignmentRunHelper::CLAVE_DE_SESION_MAESTRO => true]);
        }
    }

    /**
     * Hace que `admin-sync` opere sobre el comercio dado (fija `app.USER_ID`).
     *
     * @param  \App\Models\User $comercio
     * @return void
     */
    protected function como_dueno_de_admin_sync($comercio)
    {
        config(['app.USER_ID' => $comercio->id]);
    }

    /**
     * Los headers de un pedido de la skill a `admin-sync/catalogo/*`.
     *
     * @param  bool        $con_clave  false = sin el header de la clave.
     * @param  string|null $clave      Otra clave (para probar una clave mala).
     * @return array
     */
    protected function cabeceras_de_admin($con_clave = true, $clave = null)
    {
        $cabeceras = ['Accept' => 'application/json'];

        if ($con_clave) {
            $cabeceras['X-Admin-Api-Key'] = is_null($clave) ? self::CLAVE_DE_ADMIN : $clave;
        }

        return $cabeceras;
    }

    /**
     * Un artículo vivo y activo de un comercio (por defecto, el del test).
     *
     * @param  string $nombre
     * @param  array  $extra  Columnas extra de `articles` (bar_code, provider_code, category_id, ...).
     * @param  \App\Models\User|null $dueno
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, array $extra = [], $dueno = null)
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        return Article::create(array_merge([
            'name'    => $nombre,
            'user_id' => $dueno->id,
            'status'  => 'active',
        ], $extra));
    }

    /**
     * Varios artículos de una vez.
     *
     * @param  array $nombres
     * @param  \App\Models\User|null $dueno
     * @return \App\Models\Article[]  En el mismo orden que los nombres.
     */
    protected function crear_articulos(array $nombres, $dueno = null)
    {
        $articulos = [];

        foreach ($nombres as $nombre) {
            $articulos[] = $this->crear_articulo($nombre, [], $dueno);
        }

        return $articulos;
    }

    /**
     * Siembra DIRECTO en las tablas una corrida con sus propuestas, nodos e ítems (sin pasar por la
     * ingesta): sirve para probar elegir, revisar y volver atrás sin depender de la skill.
     *
     * Formato de `$spec`:
     *   [
     *     'estado' => 'lista',                       // estado de la corrida (default `lista`)
     *     'articulos_total' => 3,                    // default: cantidad de ítems distintos
     *     'propuestas' => [
     *       'A' => [                                 // la clave de la tarjeta
     *         'tipo' => 'nueva', 'nombre' => 'Por rubro', 'resumen' => '...', 'descripcion' => '...',
     *         // Árbol simple: categoría => [subcategorías]. Extendido (propuestas `mantener`):
     *         //   'Bisagras' => ['subs' => ['Comunes'], 'existing_category_id' => 12,
     *         //                  'existing_subs' => ['Comunes' => 30]]
     *         'arbol' => ['Bisagras' => ['Comunes', 'De cierre suave'], 'Correderas' => []],
     *         // Ítems: [artículo, categoría|null, subcategoría|null, confianza, motivo|null]
     *         'items' => [[$art1, 'Bisagras', 'Comunes', 'segura', null],
     *                     [$art2, 'Correderas', null, 'dudosa', 'no queda claro'],
     *                     [$art3, null, null, 'ninguna', 'nombre ambiguo']],
     *       ],
     *     ],
     *   ]
     *
     * @param  array $spec
     * @param  \App\Models\User|null $dueno  Por defecto el comercio del test.
     * @return array  ['run' => CategoryProposalRun, 'propuestas' => [clave => ['proposal', 'nodos', 'items']]]
     *                `nodos` se indexa por 'Categoría' y por 'Categoría/Subcategoría'.
     */
    protected function sembrar_corrida(array $spec = [], $dueno = null)
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        $propuestas_spec = isset($spec['propuestas']) ? $spec['propuestas'] : [];

        // Cantidad de artículos distintos (para articulos_total).
        $ids = [];
        foreach ($propuestas_spec as $p) {
            foreach ((isset($p['items']) ? $p['items'] : []) as $fila) {
                $ids[$fila[0]->id] = true;
            }
        }

        $run = CategoryProposalRun::create([
            'user_id'         => $dueno->id,
            'estado'          => isset($spec['estado']) ? $spec['estado'] : CategoryProposalRun::ESTADO_LISTA,
            'origen'          => CategoryProposalRun::ORIGEN_SKILL,
            'articulos_total' => isset($spec['articulos_total']) ? $spec['articulos_total'] : count($ids),
        ]);

        $salida = ['run' => $run, 'propuestas' => []];
        $orden = 0;

        foreach ($propuestas_spec as $clave => $p) {
            $orden++;

            $proposal = CategoryProposal::create([
                'run_id'      => $run->id,
                'user_id'     => $dueno->id,
                'clave'       => $clave,
                'tipo'        => isset($p['tipo']) ? $p['tipo'] : CategoryProposal::TIPO_NUEVA,
                'nombre'      => isset($p['nombre']) ? $p['nombre'] : 'Sistema '.$clave,
                'resumen'     => isset($p['resumen']) ? $p['resumen'] : null,
                'descripcion' => isset($p['descripcion']) ? $p['descripcion'] : null,
                'orden'       => $orden,
            ]);

            $nodos = [];
            $orden_nodo = 0;

            foreach ((isset($p['arbol']) ? $p['arbol'] : []) as $categoria => $valor) {
                $orden_nodo++;

                $extendido = is_array($valor) && array_key_exists('subs', $valor);
                $subs      = $extendido ? $valor['subs'] : (is_array($valor) ? $valor : []);

                $nodo_categoria = CategoryProposalNode::create([
                    'proposal_id'          => $proposal->id,
                    'user_id'              => $dueno->id,
                    'parent_id'            => null,
                    'nombre'               => $categoria,
                    'clave_nombre'         => CategoryProposalNombreHelper::clave_de($categoria),
                    'orden'                => $orden_nodo,
                    'existing_category_id' => $extendido && isset($valor['existing_category_id']) ? $valor['existing_category_id'] : null,
                ]);
                $nodos[$categoria] = $nodo_categoria;

                $orden_sub = 0;
                foreach ($subs as $sub) {
                    $orden_sub++;

                    $nodos[$categoria.'/'.$sub] = CategoryProposalNode::create([
                        'proposal_id'              => $proposal->id,
                        'user_id'                  => $dueno->id,
                        'parent_id'                => $nodo_categoria->id,
                        'nombre'                   => $sub,
                        'clave_nombre'             => CategoryProposalNombreHelper::clave_de($sub),
                        'orden'                    => $orden_sub,
                        'existing_category_id'     => $extendido && isset($valor['existing_category_id']) ? $valor['existing_category_id'] : null,
                        'existing_sub_category_id' => $extendido && isset($valor['existing_subs'][$sub]) ? $valor['existing_subs'][$sub] : null,
                    ]);
                }
            }

            $items = [];
            foreach ((isset($p['items']) ? $p['items'] : []) as $fila) {
                list($articulo, $categoria, $subcategoria) = $fila;
                $confianza = isset($fila[3]) ? $fila[3] : CategoryProposalItem::CONFIANZA_SEGURA;
                $motivo    = isset($fila[4]) ? $fila[4] : null;

                $items[] = CategoryProposalItem::create([
                    'proposal_id' => $proposal->id,
                    'user_id'     => $dueno->id,
                    'article_id'  => $articulo->id,
                    'node_id'     => is_null($categoria) ? null : $nodos[$categoria]->id,
                    'sub_node_id' => is_null($subcategoria) ? null : $nodos[$categoria.'/'.$subcategoria]->id,
                    'confianza'   => $confianza,
                    'motivo'      => $motivo,
                    'estado'      => CategoryProposalItem::ESTADO_PROPUESTA,
                ]);
            }

            $salida['propuestas'][$clave] = ['proposal' => $proposal, 'nodos' => $nodos, 'items' => $items];
        }

        return $salida;
    }
}
