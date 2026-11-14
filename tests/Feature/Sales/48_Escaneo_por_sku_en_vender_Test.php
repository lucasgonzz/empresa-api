<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Ronda 5 de la prueba gratuita (14/11/2026, ítem E9b): en Vender, el campo «Cod barras» encuentra
 * también el artículo cuyo SKU (el código propio del dueño, donde la carga asistida guarda su código
 * desde la 4.3.58) es EXACTAMENTE lo tipeado. «104» + Enter daba «No se encontro articulo».
 *
 * Contrato de `GET vender/buscar-articulo-por-codido/{code}`:
 *  - el código de barras (y la variante, y los tickets de balanza) siguen teniendo prioridad: el SKU es
 *    lo último que se intenta, así que solo cambia un código que hasta hoy no encontraba nada;
 *  - igualdad exacta tras trim, sin distinguir mayúsculas, sin LIKE;
 *  - un SKU repetido (dos artículos activos del dueño) no agrega ninguno;
 *  - solo artículos activos, no borrados y del dueño;
 *  - con código de proveedor o número interno el campo lee otra cosa y no busca por SKU.
 *
 * Usuario fresco por test (mismo patrón que 41_Escaneo_de_codigo_de_barras_de_variante_Test).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, enum ni readonly.
 */
class Escaneo_por_sku_en_vender_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @param  string $sufijo
     * @return \App\Models\User
     */
    private function usuario_de_test($sufijo)
    {
        return User::create([
            'name'     => 'Comercio escaneo sku ' . $sufijo,
            'email'    => 'escaneo-sku-' . $sufijo . '-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * @param  \App\Models\User $user
     * @param  string           $slug
     * @return void
     */
    private function dar_extension($user, $slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => $slug,
                'name' => $slug,
            ]);
        }

        $user->extencions()->attach($extencion->id);
        $user->load('extencions');
    }

    /**
     * @param  \App\Models\User $user
     * @param  string           $name
     * @param  array            $extra
     * @return \App\Models\Article
     */
    private function articulo($user, $name, $extra = [])
    {
        return Article::create(array_merge([
            'name'        => $name,
            'user_id'     => $user->id,
            'status'      => 'active',
            'final_price' => 100,
        ], $extra));
    }

    /**
     * @param  string $code
     * @return array
     */
    private function escanear($code)
    {
        $res = $this->getJson('api/vender/buscar-articulo-por-codido/' . $code);

        $res->assertStatus(200);

        return $res->json();
    }

    /**
     * @group sales
     * @group vender-scan-sku
     * @test
     */
    public function un_sku_exacto_y_unico_devuelve_su_articulo()
    {
        $user = $this->usuario_de_test('s1');
        $this->actingAs($user, 'web');

        $serrucho = $this->articulo($user, 'Serrucho', ['sku' => '104', 'bar_code' => '7790000000104']);
        $this->articulo($user, 'Martillo', ['sku' => '1040']);

        $body = $this->escanear('104');

        $this->assertNotNull($body['article']);
        $this->assertEquals($serrucho->id, $body['article']['id']);
        $this->assertFalse($body['has_variants']);
    }

    /**
     * @group sales
     * @group vender-scan-sku
     * @test
     */
    public function el_codigo_de_barras_le_gana_al_sku()
    {
        $user = $this->usuario_de_test('s2');
        $this->actingAs($user, 'web');

        $por_codigo = $this->articulo($user, 'Por código de barras', ['bar_code' => '104']);
        $this->articulo($user, 'Por SKU', ['sku' => '104']);

        $body = $this->escanear('104');

        $this->assertEquals($por_codigo->id, $body['article']['id']);
    }

    /**
     * @group sales
     * @group vender-scan-sku
     * @test
     */
    public function el_sku_no_distingue_mayusculas()
    {
        $user = $this->usuario_de_test('s3');
        $this->actingAs($user, 'web');

        $martillo = $this->articulo($user, 'Martillo', ['sku' => 'F-101']);

        $this->assertEquals($martillo->id, $this->escanear('f-101')['article']['id']);
        $this->assertEquals($martillo->id, $this->escanear('F-101')['article']['id']);
    }

    /**
     * @group sales
     * @group vender-scan-sku
     * @test
     */
    public function un_sku_repetido_no_agrega_ninguno()
    {
        $user = $this->usuario_de_test('s4');
        $this->actingAs($user, 'web');

        $this->articulo($user, 'Uno', ['sku' => '200']);
        $this->articulo($user, 'Otro', ['sku' => '200']);
        // Repetido solo por mayúsculas: también es ambiguo.
        $this->articulo($user, 'Tres', ['sku' => 'ab-1']);
        $this->articulo($user, 'Cuatro', ['sku' => 'AB-1']);

        $this->assertNull($this->escanear('200')['article']);
        $this->assertNull($this->escanear('AB-1')['article']);
    }

    /**
     * @group sales
     * @group vender-scan-sku
     * @test
     */
    public function un_sku_parcial_no_agrega()
    {
        $user = $this->usuario_de_test('s5');
        $this->actingAs($user, 'web');

        $this->articulo($user, 'Serrucho', ['sku' => '1045']);
        $this->articulo($user, 'Pinza', ['sku' => 'X104']);

        $this->assertNull($this->escanear('104')['article']);
        $this->assertNull($this->escanear('10')['article']);
    }

    /**
     * @group sales
     * @group vender-scan-sku
     * @test
     */
    public function el_sku_de_otro_duenio_inactivo_o_borrado_no_agrega()
    {
        $user = $this->usuario_de_test('s6');
        $otro = $this->usuario_de_test('s6-otro');
        $this->actingAs($user, 'web');

        $this->articulo($otro, 'De otro comercio', ['sku' => '301']);
        $this->articulo($user, 'Dado de baja', ['sku' => '302', 'status' => 'inactive']);
        $borrado = $this->articulo($user, 'Borrado', ['sku' => '303']);
        $borrado->delete();

        $this->assertNull($this->escanear('301')['article']);
        $this->assertNull($this->escanear('302')['article']);
        $this->assertNull($this->escanear('303')['article']);
    }

    /**
     * Un activo y uno dado de baja con el mismo SKU: no es ambiguo, se agrega el activo.
     *
     * @group sales
     * @group vender-scan-sku
     * @test
     */
    public function un_inactivo_con_el_mismo_sku_no_vuelve_ambiguo_al_activo()
    {
        $user = $this->usuario_de_test('s7');
        $this->actingAs($user, 'web');

        $activo = $this->articulo($user, 'Activo', ['sku' => '400']);
        $this->articulo($user, 'Viejo', ['sku' => '400', 'status' => 'inactive']);

        $this->assertEquals($activo->id, $this->escanear('400')['article']['id']);
    }

    /**
     * Con variantes, el artículo encontrado por SKU sigue el mismo camino que por código de barras:
     * se abre el selector.
     *
     * @group sales
     * @group vender-scan-sku
     * @test
     */
    public function un_sku_de_un_articulo_con_variantes_abre_el_selector()
    {
        $user = $this->usuario_de_test('s8');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $remera = $this->articulo($user, 'Remera', ['sku' => 'R-1']);
        ArticleVariant::create([
            'article_id'          => $remera->id,
            'variant_description' => 'roja M',
            'oculta'              => false,
            'stock'               => 3,
        ]);

        $body = $this->escanear('R-1');

        $this->assertEquals($remera->id, $body['article']['id']);
        $this->assertTrue($body['has_variants']);
        $this->assertCount(1, $body['variants']);
    }

    /**
     * Con «código de proveedor en Vender» el campo busca provider_code: no se le agrega el SKU.
     *
     * @group sales
     * @group vender-scan-sku
     * @test
     */
    public function con_codigo_de_proveedor_no_busca_por_sku()
    {
        $user = $this->usuario_de_test('s9');
        $this->dar_extension($user, 'codigo_proveedor_en_vender');
        $this->actingAs($user, 'web');

        $this->articulo($user, 'Serrucho', ['sku' => '104']);

        $this->assertNull($this->escanear('104')['article']);
    }
}
