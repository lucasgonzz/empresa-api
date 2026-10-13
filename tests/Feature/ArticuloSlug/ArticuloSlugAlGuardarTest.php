<?php

namespace Tests\Feature\ArticuloSlug;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use Tests\EmpresaTestCase;

/**
 * El slug de un artículo es la URL pública de la tienda (`/articulos/<slug>/<commerce_id>`).
 *
 * Misión `articulo-slug-alterna-al-guardar` (12/10/2026). El defecto: `ArticleController::update()`
 * recalculaba el slug en CADA guardado y sin excluir al propio artículo, así que el artículo se
 * encontraba a sí mismo y el slug alternaba `nombre` <-> `nombre-1` en cada edición, rompiendo el
 * link que la tienda ya había compartido. Además `ArticleHelper::slug()` no aplicaba `$ignore_id`
 * dentro del `while` y armaba el candidato siguiente a partir del anterior (con un `substr` por el
 * largo del NOMBRE), lo que generaba slugs corruptos (`zz-tom-and-jer-1`) o `base-1-2`.
 *
 * Los nombres de prueba empiezan con `ZZ ` para no chocar con el fixture.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class ArticuloSlugAlGuardarTest extends EmpresaTestCase
{
    /**
     * Payload mínimo que acepta ArticleController (varias columnas de `articles` son NOT NULL sin
     * default y el controlador las asigna tal cual vienen del request).
     *
     * @param array $props
     * @return array
     */
    protected function payload_articulo(array $props = [])
    {
        return array_merge([
            'name'                           => 'ZZ Slug articulo',
            'cost'                           => 100,
            'iva_id'                         => 2,
            'aplicar_iva'                    => 1,
            'apply_provider_percentage_gain' => 0,
            'cost_in_dollars'                => 0,
            'provider_cost_in_dollars'       => 0,
            'online'                         => 0,
            'in_offer'                       => 0,
            'precio_pausado'                 => 0,
            'default_in_vender'              => 0,
            'personalizar_price_en_vender'   => 0,
            'omitir_en_lista_pdf'            => 0,
            'mercado_libre'                  => 0,
            'disponible_tienda_nube'         => 0,
            'requires_shipping'              => 0,
            'free_shipping'                  => 0,
            'es_insumo'                      => 0,
            'featured'                       => 0,
            'price_types'                    => [],
            'price_type_monedas'             => [],
            'tags'                           => [],
            'addresses'                      => [],
            'childrens'                      => [],
        ], $props);
    }

    /**
     * Da de alta un artículo por el endpoint real y devuelve su id.
     *
     * @param string $nombre
     * @return int
     */
    protected function alta_por_endpoint($nombre)
    {
        $respuesta = $this->postJson('/api/article', $this->payload_articulo(['name' => $nombre]));

        $respuesta->assertStatus(201);

        return (int) $respuesta->json('model.id');
    }

    /**
     * Guarda un artículo por el endpoint real. `ArticleController@update` lee el id del BODY
     * (`$request->id`), no del parámetro de ruta, así que se manda en el payload.
     *
     * @param int $id
     * @param string $nombre
     * @return void
     */
    protected function guardar_por_endpoint($id, $nombre)
    {
        $this->putJson('/api/article/' . $id, $this->payload_articulo([
            'id'   => $id,
            'name' => $nombre,
        ]))->assertStatus(200);
    }

    /**
     * Crea un artículo directo en la base (sin pasar por el endpoint), con el slug que se le diga.
     *
     * @param string $nombre
     * @param string|null $slug
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, $slug)
    {
        $articulo = new Article();

        $articulo->user_id = auth()->user()->id;
        $articulo->name    = $nombre;
        $articulo->slug    = $slug;
        $articulo->status  = 'active';
        $articulo->iva_id  = 2;

        $articulo->save();

        return $articulo;
    }

    /**
     * Slug que tiene guardado el artículo en la base (se relee siempre, nunca del request).
     *
     * @param int $id
     * @return string|null
     */
    protected function slug_de($id)
    {
        return Article::where('id', $id)->value('slug');
    }

    /**
     * Caso 1: guardar dos veces sin cambiar el nombre no mueve el slug (reproduce el defecto:
     * antes el primer PUT lo mandaba a `-1` y el segundo lo devolvía).
     *
     * @return void
     */
    public function test_guardar_dos_veces_sin_cambiar_el_nombre_no_cambia_el_slug()
    {
        $id = $this->alta_por_endpoint('ZZ Slug Llave T 10mm');

        $slug_del_alta = $this->slug_de($id);
        $this->assertSame('zz-slug-llave-t-10mm', $slug_del_alta);

        $this->guardar_por_endpoint($id, 'ZZ Slug Llave T 10mm');
        $this->assertSame($slug_del_alta, $this->slug_de($id), 'El primer guardado no puede tocar el slug.');

        $this->guardar_por_endpoint($id, 'ZZ Slug Llave T 10mm');
        $this->assertSame($slug_del_alta, $this->slug_de($id), 'El segundo guardado tampoco.');
    }

    /**
     * Caso 2: un slug con sufijo legítimo (`x-1`, porque otro artículo ya tiene `x`) se conserva
     * al guardar sin tocar el nombre.
     *
     * @return void
     */
    public function test_un_slug_con_sufijo_legitimo_se_conserva_al_guardar()
    {
        $primero = $this->alta_por_endpoint('ZZ Slug Repetido');
        $segundo = $this->alta_por_endpoint('ZZ Slug Repetido');

        $this->assertSame('zz-slug-repetido', $this->slug_de($primero));
        $this->assertSame('zz-slug-repetido-1', $this->slug_de($segundo));

        $this->guardar_por_endpoint($segundo, 'ZZ Slug Repetido');
        $this->assertSame('zz-slug-repetido-1', $this->slug_de($segundo), 'Primer guardado sin tocar el nombre.');

        $this->guardar_por_endpoint($segundo, 'ZZ Slug Repetido');
        $this->assertSame('zz-slug-repetido-1', $this->slug_de($segundo), 'Segundo guardado sin tocar el nombre.');

        // Y el que tenía el slug base no se movió.
        $this->guardar_por_endpoint($primero, 'ZZ Slug Repetido');
        $this->assertSame('zz-slug-repetido', $this->slug_de($primero));
    }

    /**
     * Caso 3: renombrar un artículo al nombre que ya usa OTRO le da sufijo `-1`, y ese sufijo es
     * estable en los guardados siguientes.
     *
     * @return void
     */
    public function test_renombrar_al_nombre_de_otro_recibe_sufijo_y_es_estable()
    {
        $a = $this->alta_por_endpoint('ZZ Slug Alfa');
        $b = $this->alta_por_endpoint('ZZ Slug Beta');

        $this->guardar_por_endpoint($b, 'ZZ Slug Alfa');
        $this->assertSame('zz-slug-alfa-1', $this->slug_de($b), 'Renombrado al nombre de A: recibe -1.');

        $this->guardar_por_endpoint($b, 'ZZ Slug Alfa');
        $this->assertSame('zz-slug-alfa-1', $this->slug_de($b), 'Un segundo guardado igual no lo mueve.');

        $this->assertSame('zz-slug-alfa', $this->slug_de($a), 'El artículo A conserva su slug.');
    }

    /**
     * Caso 4: cambiar el nombre a uno libre hace que el slug siga al nombre.
     *
     * @return void
     */
    public function test_cambiar_el_nombre_a_uno_libre_hace_que_el_slug_siga_al_nombre()
    {
        $id = $this->alta_por_endpoint('ZZ Slug Nombre viejo');
        $this->assertSame('zz-slug-nombre-viejo', $this->slug_de($id));

        $this->guardar_por_endpoint($id, 'ZZ Slug Nombre nuevo');

        $this->assertSame('zz-slug-nombre-nuevo', $this->slug_de($id));
    }

    /**
     * Caso 5: un artículo sin slug (NULL) que se edita sin tocar el nombre recupera su slug
     * (comportamiento que ya existía: una edición le repone el slug).
     *
     * @return void
     */
    public function test_un_articulo_sin_slug_recupera_el_slug_al_guardar_sin_tocar_el_nombre()
    {
        $articulo = $this->crear_articulo('ZZ Slug Sin slug', null);

        $this->assertNull($this->slug_de($articulo->id));

        $this->guardar_por_endpoint($articulo->id, 'ZZ Slug Sin slug');

        $this->assertSame('zz-slug-sin-slug', $this->slug_de($articulo->id));
    }

    /**
     * Caso 6a: `ArticleHelper::slug()` con `$ignore_id` del artículo que ya tiene `x-1` mientras
     * otro tiene `x` devuelve `x-1` (cubre la consulta del `while`, que antes no excluía al propio).
     *
     * @return void
     */
    public function test_slug_helper_ignora_al_propio_articulo_dentro_del_while()
    {
        $this->crear_articulo('ZZ Slug Directo', 'zz-slug-directo');
        $con_sufijo = $this->crear_articulo('ZZ Slug Directo', 'zz-slug-directo-1');

        $this->assertSame('zz-slug-directo-1', ArticleHelper::slug('ZZ Slug Directo', $con_sufijo->id));
    }

    /**
     * Caso 6b: con `x`, `x-1` y `x-2` tomados y el propio siendo `x-2`, devuelve `x-2`.
     *
     * @return void
     */
    public function test_slug_helper_con_tres_tomados_y_el_propio_en_el_ultimo_devuelve_el_propio()
    {
        $this->crear_articulo('ZZ Slug Triple', 'zz-slug-triple');
        $this->crear_articulo('ZZ Slug Triple', 'zz-slug-triple-1');
        $propio = $this->crear_articulo('ZZ Slug Triple', 'zz-slug-triple-2');

        $this->assertSame('zz-slug-triple-2', ArticleHelper::slug('ZZ Slug Triple', $propio->id));

        // Sin ignorar a nadie (un alta), el siguiente libre es el -3.
        $this->assertSame('zz-slug-triple-3', ArticleHelper::slug('ZZ Slug Triple'));
    }

    /**
     * Caso 6c: tres altas con un nombre cuyo slug es MÁS LARGO que el nombre (`@` pasa a `-at-`:
     * `ZZ a@b` mide 6 y su slug `zz-a-at-b` mide 9) dan `base`, `base-1`, `base-2`: sin cortes
     * (`zz-a-a-1`, que es lo que daba el `substr` por el largo del nombre) ni `base-1-2`.
     *
     * @return void
     */
    public function test_nombre_con_slug_de_otro_largo_da_base_y_sufijos_limpios()
    {
        $primero = $this->alta_por_endpoint('ZZ a@b');
        $segundo = $this->alta_por_endpoint('ZZ a@b');
        $tercero = $this->alta_por_endpoint('ZZ a@b');

        $this->assertSame('zz-a-at-b', $this->slug_de($primero));
        $this->assertSame('zz-a-at-b-1', $this->slug_de($segundo));
        $this->assertSame('zz-a-at-b-2', $this->slug_de($tercero));
    }

    /**
     * Caso 7: dar de alta dos veces el mismo nombre da `x` y `x-1` (el alta no cambió).
     *
     * @return void
     */
    public function test_dos_altas_con_el_mismo_nombre_dan_base_y_base_1()
    {
        $primero = $this->alta_por_endpoint('ZZ Slug Alta doble');
        $segundo = $this->alta_por_endpoint('ZZ Slug Alta doble');

        $this->assertSame('zz-slug-alta-doble', $this->slug_de($primero));
        $this->assertSame('zz-slug-alta-doble-1', $this->slug_de($segundo));
    }

    /**
     * Caso 8: un slug guardado como cadena vacía (no solo NULL) también se repone al editar.
     *
     * @return void
     */
    public function test_un_articulo_con_slug_vacio_recupera_el_slug_al_guardar()
    {
        $articulo = $this->crear_articulo('ZZ Slug Vacio', '');

        $this->guardar_por_endpoint($articulo->id, 'ZZ Slug Vacio');

        $this->assertSame('zz-slug-vacio', $this->slug_de($articulo->id));
    }

    /**
     * Caso 9: un artículo que quedó con `-1` y se renombra a un nombre libre pierde el sufijo
     * (el slug sigue al nombre nuevo, que ya no choca con nadie).
     *
     * @return void
     */
    public function test_renombrar_un_articulo_con_sufijo_a_un_nombre_libre_pierde_el_sufijo()
    {
        $this->crear_articulo('ZZ Slug Pierde', 'zz-slug-pierde');
        $con_sufijo = $this->crear_articulo('ZZ Slug Pierde', 'zz-slug-pierde-1');

        $this->guardar_por_endpoint($con_sufijo->id, 'ZZ Slug Libre Nuevo');

        $this->assertSame('zz-slug-libre-nuevo', $this->slug_de($con_sufijo->id));
    }

    /**
     * Caso 10: cambiar solo mayúsculas del nombre no mueve el slug ni lo manda a `-1`
     * (el slug no distingue mayúsculas, así que el propio artículo no cuenta como choque).
     *
     * @return void
     */
    public function test_cambiar_solo_las_mayusculas_del_nombre_no_mueve_el_slug()
    {
        $id = $this->alta_por_endpoint('ZZ Slug Mayus');
        $this->assertSame('zz-slug-mayus', $this->slug_de($id));

        $this->guardar_por_endpoint($id, 'ZZ SLUG MAYUS');

        $this->assertSame('zz-slug-mayus', $this->slug_de($id));
    }

    /**
     * Caso 11: la unicidad es POR DUEÑO. Dos dueños con el mismo nombre de artículo tienen el
     * mismo slug base, sin sufijo, y ninguno afecta al otro.
     *
     * @return void
     */
    public function test_la_unicidad_del_slug_es_por_dueno()
    {
        $dueno_actual = (int) auth()->user()->id;
        $otro_dueno   = $dueno_actual + 100000;

        $this->crear_articulo('ZZ Slug Dueno', 'zz-slug-dueno');

        // El mismo dueño choca y recibe `-1`...
        $this->assertSame('zz-slug-dueno-1', ArticleHelper::slug('ZZ Slug Dueno', null, $dueno_actual));

        // ...pero otro dueño, que no tiene ese slug, lo recibe limpio.
        $this->assertSame('zz-slug-dueno', ArticleHelper::slug('ZZ Slug Dueno', null, $otro_dueno));
    }
}
