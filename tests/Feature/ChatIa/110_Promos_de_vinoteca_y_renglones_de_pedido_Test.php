<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ResumenDeDatosIaHelper;
use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-truvari-pedidos-fallidos (13/11/2026), B5 y B6 — F6 y F7 del inventario.
 *
 * B5 (F6). «¿El Combo Pinot Noir está habilitado en la tienda?» → «las herramientas no me muestran
 * los combos ni si están publicados». En Truvari lo que el dueño llama combo o promo es una
 * PROMOCIÓN DE VINOTECA (`promocion_vinotecas`, el botón Promos del Listado), que en el esquema era
 * una entidad derivada sin descripción y sin "combo" en ningún lado. Ahora es curada, se encuentra
 * buscando "combo" o "promo", trae si está publicada y, publicada con slug, el link de su ficha en la
 * tienda (tienda-api resuelve `/articulos/{slug}/{comercio}` también para una promo con online = 1).
 *
 * B6 (F7). El pedido N° 77 de la tienda, por $188.000, mostró un solo renglón de $48.000 («los totales
 * no cierran»): el resto era una promo, que vive en `order_promocion_vinoteca` y no en
 * `article_order`. Ahora hay dos hijas más —renglon_de_pedido_promo y renglon_de_pedido_combo— con
 * su importe, y la descripción de renglon_de_pedido dice que el total es la suma de las tres.
 *
 * 🔴 Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class Promos_de_vinoteca_y_renglones_de_pedido_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        EsquemaDeDatosIaHelper::olvidar();

        $this->comercio = User::create([
            'name'     => 'Truvari P110',
            'email'    => 'truvari-p110-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'online'   => 'truvaribebidas.com.ar',
        ]);
    }

    protected function promo($nombre, $online, $slug)
    {
        return DB::table('promocion_vinotecas')->insertGetId([
            'name'        => $nombre,
            'slug'        => $slug,
            'stock'       => 4,
            'final_price' => 70000,
            'online'      => $online,
            'user_id'     => $this->comercio->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    protected function entidades_de($buscar)
    {
        $nombres = [];

        foreach (EsquemaDeDatosIaHelper::lista_para_el_modelo($buscar) as $fila) {
            $nombres[] = $fila['entidad'];
        }

        return $nombres;
    }

    /**
     * La promo de vinoteca es una entidad curada que se encuentra por "combo" y por "promo".
     *
     * @test
     */
    public function la_promo_de_vinoteca_es_curada_y_se_encuentra_por_combo_y_por_promo()
    {
        $declaracion = EsquemaDeDatosIaHelper::declaracion('promocion_vinoteca');

        $this->assertNotNull($declaracion);
        $this->assertTrue($declaracion['curada']);
        $this->assertStringContainsString('Promos', $declaracion['descripcion']);
        $this->assertStringContainsString('combo', mb_strtolower($declaracion['descripcion']));

        foreach (['name', 'final_price', 'stock', 'online', 'slug', 'created_at'] as $campo) {
            $this->assertContains($campo, $declaracion['campos_por_defecto'], $campo);
        }

        $this->assertContains('promocion_vinoteca', $this->entidades_de('combo'));
        $this->assertContains('promocion_vinoteca', $this->entidades_de('promo'));

        // El combo dice si está publicado y remite a las promos de vinoteca.
        $combo = EsquemaDeDatosIaHelper::declaracion('combo');
        $this->assertContains('online', $combo['campos_por_defecto']);
        $this->assertStringContainsString('promocion_vinoteca', $combo['descripcion']);
    }

    /**
     * 🔴 «¿El Combo Pinot Noir está habilitado en la tienda?»: la consulta lo dice y trae el link de su
     * ficha; una promo sin publicar viene sin link.
     *
     * @test
     */
    public function consultar_las_promos_dice_si_estan_publicadas_y_trae_el_link()
    {
        $this->promo('Combo Pinot Noir p110', 1, 'combo-pinot-noir-p110');
        $this->promo('Combo Malbec p110', 0, 'combo-malbec-p110');

        $resultado = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'promocion_vinoteca', [
            ['campo' => 'name', 'operador' => 'contiene', 'valor' => 'p110'],
        ], ['campo' => 'name', 'direccion' => 'asc']);

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertSame(2, $resultado['registros_encontrados']);

        $por_nombre = [];
        foreach ($resultado['registros'] as $registro) {
            $por_nombre[$registro['name']] = $registro;
        }

        $pinot = $por_nombre['Combo Pinot Noir p110'];
        $this->assertSame('https://truvaribebidas.com.ar/articulos/combo-pinot-noir-p110/' . $this->comercio->id, $pinot['link_tienda']);

        $this->assertNull($por_nombre['Combo Malbec p110']['link_tienda']);
    }

    /**
     * 🔴 El pedido N° 77: un renglón de artículo de $48.000 y una promo de $140.000. Las tres hijas
     * de renglón de pedido suman el total del pedido.
     *
     * @test
     */
    public function los_renglones_del_pedido_suman_el_total_con_promos_y_combos()
    {
        $articulo = Article::create(['name' => 'Casa Ambrosia Sauvignon Blanc p110', 'user_id' => $this->comercio->id, 'status' => 'active']);
        $promo = $this->promo('Combo Pinot Noir p110', 1, 'combo-pinot-noir-p110');
        $combo = DB::table('combos')->insertGetId(['num' => 990110, 'name' => 'Combo Picada p110', 'price' => 20000, 'user_id' => $this->comercio->id]);

        $pedido = DB::table('orders')->insertGetId([
            'num'             => 990077,
            'buyer_id'        => 1,
            'deliver'         => 0,
            'order_status_id' => 1,
            'status'          => 'unconfirmed',
            'user_id'         => $this->comercio->id,
            'total'           => 208000,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('article_order')->insert(['order_id' => $pedido, 'article_id' => $articulo->id, 'amount' => 1, 'price' => 48000]);
        DB::table('order_promocion_vinoteca')->insert(['order_id' => $pedido, 'promocion_vinoteca_id' => $promo, 'amount' => 2, 'price' => 70000]);
        DB::table('order_combo')->insert(['order_id' => $pedido, 'combo_id' => $combo, 'amount' => 1, 'price' => 20000]);

        $filtro = [['campo' => 'numero_pedido', 'operador' => 'igual', 'valor' => 990077]];
        $suma = [['funcion' => 'suma', 'campo' => 'importe']];

        $articulos = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'renglon_de_pedido', $filtro, [], $suma);
        $promos = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'renglon_de_pedido_promo', $filtro, [], $suma);
        $combos = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'renglon_de_pedido_combo', $filtro, [], $suma);

        $this->assertEquals(48000, $articulos['total_general']['suma_importe']);
        $this->assertEquals(140000, $promos['total_general']['suma_importe']);
        $this->assertEquals(20000, $combos['total_general']['suma_importe']);
        $this->assertEquals(208000, $articulos['total_general']['suma_importe'] + $promos['total_general']['suma_importe'] + $combos['total_general']['suma_importe']);

        // Listar el renglón de la promo trae su nombre (no un id suelto).
        $lista = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'renglon_de_pedido_promo', $filtro);
        $this->assertArrayNotHasKey('error', $lista, json_encode($lista));
        $this->assertSame(1, $lista['registros_encontrados']);
        $this->assertContains('Combo Pinot Noir p110', array_values($lista['registros'][0]));

        // La descripción del renglón de artículos ya no deja creer que es todo el pedido.
        $this->assertStringContainsString('renglon_de_pedido_promo', EsquemaDeDatosIaHelper::declaracion('renglon_de_pedido')['descripcion']);

        foreach (['renglon_de_pedido_promo', 'renglon_de_pedido_combo'] as $entidad) {
            $declaracion = EsquemaDeDatosIaHelper::declaracion($entidad);
            $this->assertTrue(EsquemaDeDatosIaHelper::es_calculado($declaracion, 'importe'), $entidad);
            $this->assertContains('importe', $declaracion['campos_por_defecto'], $entidad);
        }
    }

    /**
     * Los renglones de pedido de otro negocio no entran: el scope va por el pedido.
     *
     * @test
     */
    public function los_renglones_de_promo_de_otro_negocio_no_entran()
    {
        $ajeno = User::create(['name' => 'Ajeno P110', 'email' => 'ajeno-p110-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);

        $pedido = DB::table('orders')->insertGetId([
            'num' => 990078, 'buyer_id' => 1, 'deliver' => 0, 'order_status_id' => 1, 'status' => 'unconfirmed',
            'user_id' => $ajeno->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_promocion_vinoteca')->insert(['order_id' => $pedido, 'promocion_vinoteca_id' => 1, 'amount' => 1, 'price' => 999]);

        $resultado = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'renglon_de_pedido_promo', [], [], [['funcion' => 'conteo']]);

        $this->assertEquals(0, $resultado['total_general']['conteo']);
    }
}
