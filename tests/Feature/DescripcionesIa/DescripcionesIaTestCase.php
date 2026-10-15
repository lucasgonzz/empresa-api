<?php

namespace Tests\Feature\DescripcionesIa;

use App\Models\Article;
use App\Models\ArticleDescriptionProposal;
use App\Models\Description;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Base de los tests de la revisión de descripciones automáticas (misión alertas-descripciones,
 * 14/10/2026): Alertas → Catálogo → Descripciones y lo que el lote guarda.
 *
 * Cada test arranca con DOS comercios nuevos (el dueño del test y un "vecino" con sus propios datos:
 * es lo que prueba la tenencia), autenticado como el dueño.
 *
 * 🔴 `app.USER_ID` se fija al comercio del test: el `.env.testing` del slot trae USER_ID=500 y
 * `UserHelper::userId()` cae ahí cuando no hay sesión.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
abstract class DescripcionesIaTestCase extends EmpresaTestCase
{
    /** @var \App\Models\User Comercio nuevo de cada test (el que actúa). */
    protected $owner;

    /** @var \App\Models\User Segundo comercio con sus propios datos, para las pruebas de tenencia. */
    protected $vecino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner  = $this->crear_comercio('Comercio de descripciones');
        $this->vecino = $this->crear_comercio('Comercio vecino de descripciones');

        config(['app.USER_ID' => $this->owner->id]);

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
            'email'        => 'descripciones-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Un empleado del comercio dado (`owner_id` apunta al dueño).
     *
     * @param  \App\Models\User $dueno
     * @return \App\Models\User
     */
    protected function crear_empleado_de($dueno)
    {
        return User::create([
            'name'         => 'Empleado de '.$dueno->name,
            'company_name' => $dueno->company_name,
            'email'        => 'empleado-descripciones-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $dueno->id,
        ]);
    }

    /**
     * Autentica para los requests que siguen.
     *
     * 🔴 El forgetGuards() no es decorativo: Sanctum cachea el usuario que resolvió la primera vez
     * dentro del mismo container (memoria `tests-el-guard-queda-en-sanctum-despues-de-un-request`).
     *
     * @param  \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Un artículo vivo y activo de un comercio (por defecto, el del test).
     *
     * @param  string $nombre
     * @param  array  $extra  Columnas extra de `articles` (bar_code, provider_code, ...).
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
     * Una propuesta sembrada directo en la tabla.
     *
     * @param  \App\Models\Article $articulo
     * @param  array  $secciones  [[title, content], ...]
     * @param  array  $extra      Columnas a pisar (status, ai_sources, created_at, ...).
     * @return \App\Models\ArticleDescriptionProposal
     */
    protected function sembrar_propuesta($articulo, array $secciones = [], array $extra = [])
    {
        if (empty($secciones)) {
            $secciones = [['Descripción del producto', 'Texto que la IA no pudo confirmar de '.$articulo->name]];
        }

        $propuesta = ArticleDescriptionProposal::create(array_merge([
            'user_id'       => $articulo->user_id,
            'article_id'    => $articulo->id,
            'sections'      => $this->secciones($secciones),
            'ai_confidence' => 'low',
            'ai_sources'    => [['source' => 'google_name', 'url' => 'https://www.ejemplo.com.ar/producto', 'title' => 'Ficha de ejemplo']],
            'status'        => ArticleDescriptionProposal::STATUS_A_REVISAR,
        ], $extra));

        if (isset($extra['created_at'])) {
            DB::table('article_description_proposals')->where('id', $propuesta->id)->update(['created_at' => $extra['created_at']]);
        }

        return $propuesta->fresh();
    }

    /**
     * Una descripción del artículo. Por defecto, de IA ya publicada (confianza alta).
     *
     * @param  \App\Models\Article $articulo
     * @param  string $titulo
     * @param  string $texto
     * @param  array  $extra  ai_generated, ai_confidence, ai_sources, ai_reviewed_at, created_at.
     * @return \App\Models\Description
     */
    protected function sembrar_descripcion($articulo, $titulo, $texto, array $extra = [])
    {
        $description = Description::create(array_merge([
            'title'         => $titulo,
            'content'       => $texto,
            'article_id'    => $articulo->id,
            'ai_generated'  => true,
            'ai_confidence' => 'high',
            'ai_sources'    => [['source' => 'barcode_api', 'url' => 'https://upcitemdb.com/x', 'title' => 'UPC']],
        ], array_diff_key($extra, ['created_at' => true])));

        if (isset($extra['created_at'])) {
            DB::table('descriptions')->where('id', $description->id)->update(['created_at' => $extra['created_at']]);
        }

        return $description->fresh();
    }

    /**
     * Una descripción escrita por una persona.
     *
     * @param  \App\Models\Article $articulo
     * @param  string $texto
     * @return \App\Models\Description
     */
    protected function sembrar_descripcion_humana($articulo, $texto = 'Escrita a mano')
    {
        return $this->sembrar_descripcion($articulo, 'Descripción', $texto, [
            'ai_generated'  => false,
            'ai_confidence' => null,
            'ai_sources'    => null,
        ]);
    }

    /**
     * [[title, content], ...] → [{title, content}, ...]
     *
     * @param  array $pares
     * @return array
     */
    protected function secciones(array $pares)
    {
        $secciones = [];

        foreach ($pares as $par) {
            $secciones[] = ['title' => $par[0], 'content' => $par[1]];
        }

        return $secciones;
    }

    /**
     * La propuesta leída de la base.
     *
     * @param  \App\Models\ArticleDescriptionProposal $propuesta
     * @return \App\Models\ArticleDescriptionProposal
     */
    protected function propuesta_fresca($propuesta)
    {
        return ArticleDescriptionProposal::find($propuesta->id);
    }

    /**
     * Las descripciones de un artículo, por id.
     *
     * @param  \App\Models\Article $articulo
     * @return \Illuminate\Support\Collection
     */
    protected function descripciones_de($articulo)
    {
        return Description::where('article_id', $articulo->id)->orderBy('id')->get();
    }
}
