<?php

namespace Tests\Feature\ArticuloSlug;

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Todo artículo que se da de alta sin slug lo recibe solo (observer `creating`).
 *
 * Misión `tienda-articulos-sin-slug` (26/11/2026). Hay al menos 9 caminos que hacen `Article::create`
 * o `new Article` sin slug (clonado, API externa, Tienda Nube, Mercado Libre, etc.); el backfill sólo
 * arregla lo que ya existe. Estos tests dan de alta con el modelo directo (el mismo camino que usan
 * todos ellos) y verifican el slug resultante en la base.
 *
 * Los nombres empiezan con `ZZ ` para no chocar con el fixture.
 *
 * IMPORTANTE: PHP 7.4 estricto, sin sintaxis de PHP 8 (ver .mision/RESTRICCIONES.md).
 */
class SlugAlCrearArticuloTest extends EmpresaTestCase
{
    /**
     * Crea un dueño nuevo (owner_id null) para aislar los slugs de este test.
     *
     * @return \App\Models\User
     */
    protected function crear_dueno()
    {
        return User::forceCreate([
            'name'         => 'Comercio alta slug',
            'company_name' => 'ZZ Comercio alta slug',
            'email'        => 'alta-slug-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Da de alta un artículo con el modelo directo (sin pasar por ArticleController).
     *
     * @param int $user_id
     * @param string $nombre
     * @param array $extra Atributos adicionales (p. ej. `slug`).
     * @return \App\Models\Article
     */
    protected function alta_directa($user_id, $nombre, array $extra = [])
    {
        return Article::create(array_merge([
            'user_id' => $user_id,
            'name'    => $nombre,
            'status'  => 'active',
            'iva_id'  => 2,
        ], $extra));
    }

    /**
     * Slug guardado hoy en la base (se relee siempre).
     *
     * @param int $id
     * @return string|null
     */
    protected function slug_de($id)
    {
        return DB::table('articles')->where('id', $id)->value('slug');
    }

    /**
     * CA4: un alta sin slug recibe el que sale del nombre.
     *
     * @return void
     */
    public function test_alta_sin_slug_lo_recibe_del_nombre()
    {
        $dueno = $this->crear_dueno();

        $articulo = $this->alta_directa($dueno->id, 'ZZ Alta Sin Slug');

        $this->assertSame('zz-alta-sin-slug', $this->slug_de($articulo->id));
    }

    /**
     * CA4: un segundo alta con el mismo nombre del mismo dueño recibe el sufijo `-1`.
     *
     * @return void
     */
    public function test_segunda_alta_con_el_mismo_nombre_recibe_sufijo()
    {
        $dueno = $this->crear_dueno();

        $primero = $this->alta_directa($dueno->id, 'ZZ Alta Sin Slug');
        $segundo = $this->alta_directa($dueno->id, 'ZZ Alta Sin Slug');

        $this->assertSame('zz-alta-sin-slug', $this->slug_de($primero->id));
        $this->assertSame('zz-alta-sin-slug-1', $this->slug_de($segundo->id));
    }

    /**
     * CA4: un alta que ya trae slug lo conserva tal cual.
     *
     * @return void
     */
    public function test_alta_con_slug_propio_lo_conserva()
    {
        $dueno = $this->crear_dueno();

        $articulo = $this->alta_directa($dueno->id, 'ZZ Alta Con Slug', ['slug' => 'mi-slug-propio']);

        $this->assertSame('mi-slug-propio', $this->slug_de($articulo->id));
    }

    /**
     * CA4: un nombre sin slug posible (`***`) se da de alta sin error y queda con slug NULL.
     *
     * @return void
     */
    public function test_alta_con_nombre_de_simbolos_queda_sin_slug_y_sin_error()
    {
        $dueno = $this->crear_dueno();

        $articulo = $this->alta_directa($dueno->id, '***');

        $this->assertNotNull($articulo->id, 'El alta no puede fallar.');
        $this->assertNull($this->slug_de($articulo->id));
    }
}
