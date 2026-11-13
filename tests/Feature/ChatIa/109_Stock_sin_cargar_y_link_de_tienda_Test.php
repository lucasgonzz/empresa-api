<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\FichaArticuloIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TiendaDelNegocioIaHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-truvari-pedidos-fallidos (13/11/2026), B3 y B4 — el Coratina y F5 del inventario.
 *
 * B3. El Aceite de Oliva Familia Zuccardi Coratina de Truvari (article 923) tiene `stock` NULL: nunca
 * se le cargó stock. `consultar_stock_de_articulos` lo devolvía como 0 y el asistente le dijo al dueño
 * «está publicado y con stock 0». NULL no es 0: ahora viaja `stock: null` con `stock_sin_cargar: true`,
 * y si el artículo reparte por depósito (address_article), la suma de ese reparto.
 *
 * B4. El link de la tienda de un artículo lo armaba solo la promoción por WhatsApp. Una vez el modelo
 * lo dio (lo armó a mano, bien de casualidad) y después dijo que no tenía herramienta. Ahora la
 * consulta de stock devuelve, AL FINAL de cada fila, `publicado_en_tienda` y `link_tienda` (null si
 * no está publicado, no tiene slug o el negocio no tiene la dirección de la tienda), armado por
 * TiendaDelNegocioIaHelper::link_del_articulo(), el mismo que usa la promoción.
 *
 * 🔴 Las claves nuevas van al final: el canal "sistema:" de admin-api lee por nombre.
 *
 * 🔴 Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class Stock_sin_cargar_y_link_de_tienda_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Truvari P109',
            'email'    => 'truvari-p109-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'online'   => 'https://truvaribebidas.com.ar',
        ]);
    }

    protected function articulo(array $atributos)
    {
        return Article::create(array_merge([
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'final_price' => 17000,
        ], $atributos));
    }

    protected function fila_de($nombre)
    {
        foreach (ConsultasSistemaIaHelper::stock_de_articulos($this->comercio->id, 'p109') as $fila) {
            if ($fila['nombre'] === $nombre) {
                return $fila;
            }
        }

        $this->fail('No vino la fila de ' . $nombre);
    }

    /**
     * 🔴 El Coratina: stock NULL → null y sin cargar, nunca 0. Con stock 0 de verdad, 0 y cargado.
     *
     * @test
     */
    public function el_stock_sin_cargar_no_se_informa_como_cero()
    {
        $this->articulo(['name' => 'Coratina 500 ml p109', 'stock' => null]);
        $this->articulo(['name' => 'Agotado p109', 'stock' => 0]);

        $coratina = $this->fila_de('Coratina 500 ml p109');
        $this->assertNull($coratina['stock']);
        $this->assertTrue($coratina['stock_sin_cargar']);
        $this->assertNull($coratina['stock_en_depositos']);

        $agotado = $this->fila_de('Agotado p109');
        $this->assertSame(0.0, $agotado['stock']);
        $this->assertFalse($agotado['stock_sin_cargar']);
    }

    /**
     * Un artículo con stock NULL pero repartido por depósito: la suma del reparto viaja aparte.
     *
     * @test
     */
    public function con_reparto_por_deposito_viaja_la_suma()
    {
        $local = Address::create(['street' => 'Local p109', 'user_id' => $this->comercio->id]);
        $deposito = Address::create(['street' => 'Deposito p109', 'user_id' => $this->comercio->id]);

        $repartido = $this->articulo(['name' => 'Repartido p109', 'stock' => null]);
        DB::table('address_article')->insert([
            ['article_id' => $repartido->id, 'address_id' => $local->id, 'amount' => 4],
            ['article_id' => $repartido->id, 'address_id' => $deposito->id, 'amount' => 5],
        ]);

        $fila = $this->fila_de('Repartido p109');
        $this->assertNull($fila['stock']);
        $this->assertTrue($fila['stock_sin_cargar']);
        $this->assertEquals(9, $fila['stock_en_depositos']);

        // Por id, la misma forma.
        $por_id = ConsultasSistemaIaHelper::stock_de_articulos($this->comercio->id, '', $repartido->id);
        $this->assertEquals(9, $por_id[0]['stock_en_depositos']);
        $this->assertTrue($por_id[0]['stock_sin_cargar']);
    }

    /**
     * 🔴 F5: el link de la tienda viene en la consulta, y es el mismo que arma la promoción.
     *
     * @test
     */
    public function la_consulta_de_stock_devuelve_el_link_de_la_tienda()
    {
        $publicado = $this->articulo(['name' => 'Nieto Senetiner Brut Nature p109', 'stock' => 9, 'online' => 1, 'slug' => 'nieto-senetiner-brut-nature-p109']);
        $this->articulo(['name' => 'Sin publicar p109', 'stock' => 3, 'online' => 0, 'slug' => 'sin-publicar-p109']);
        $this->articulo(['name' => 'Sin slug p109', 'stock' => 3, 'online' => 1, 'slug' => '']);

        $fila = $this->fila_de('Nieto Senetiner Brut Nature p109');

        $this->assertTrue($fila['publicado_en_tienda']);
        $this->assertSame('https://truvaribebidas.com.ar/articulos/nieto-senetiner-brut-nature-p109/' . $this->comercio->id, $fila['link_tienda']);
        $this->assertSame(
            TiendaDelNegocioIaHelper::link_del_articulo('https://truvaribebidas.com.ar', $publicado, $this->comercio->id),
            $fila['link_tienda']
        );

        $sin_publicar = $this->fila_de('Sin publicar p109');
        $this->assertFalse($sin_publicar['publicado_en_tienda']);
        $this->assertNull($sin_publicar['link_tienda']);

        $sin_slug = $this->fila_de('Sin slug p109');
        $this->assertTrue($sin_slug['publicado_en_tienda']);
        $this->assertNull($sin_slug['link_tienda']);

        // Sin la dirección de la tienda no hay link (y publicado sigue diciendo la verdad).
        $this->assertNull(TiendaDelNegocioIaHelper::link_del_articulo(null, $publicado, $this->comercio->id));
        $this->assertNull(TiendaDelNegocioIaHelper::link_del_articulo('', $publicado, $this->comercio->id));
    }

    /**
     * El reparto por depósito y la ficha de hover tampoco informan como 0 un stock sin cargar. En el
     * reparto viaja null; la ficha sigue numérica (la pinta la SPA) pero con la marca.
     *
     * @test
     */
    public function el_reparto_por_deposito_y_la_ficha_marcan_el_stock_sin_cargar()
    {
        Address::create(['street' => 'Local p109 reparto', 'user_id' => $this->comercio->id]);

        $coratina = $this->articulo(['name' => 'Coratina reparto p109', 'stock' => null]);
        $agotado = $this->articulo(['name' => 'Agotado reparto p109', 'stock' => 0]);

        $sin_cargar = ConsultasSistemaIaHelper::stock_por_deposito($this->comercio->id, '', 0, $coratina->id);
        $this->assertNull($sin_cargar['stock_total_del_articulo']);
        $this->assertTrue($sin_cargar['stock_sin_cargar']);
        $this->assertSame('stock_sin_cargar', array_keys($sin_cargar)[count($sin_cargar) - 1], 'La clave nueva va al final.');

        $en_cero = ConsultasSistemaIaHelper::stock_por_deposito($this->comercio->id, '', 0, $agotado->id);
        $this->assertSame(0.0, $en_cero['stock_total_del_articulo']);
        $this->assertFalse($en_cero['stock_sin_cargar']);

        $ficha = FichaArticuloIaHelper::ficha($coratina->id, $this->comercio->id, $this->comercio);
        $this->assertSame(0.0, $ficha['stock_total'], 'La SPA pinta un número: sigue numérico.');
        $this->assertTrue($ficha['stock_sin_cargar']);

        $this->assertFalse(FichaArticuloIaHelper::ficha($agotado->id, $this->comercio->id, $this->comercio)['stock_sin_cargar']);
    }

    /**
     * Las claves de siempre quedan en su lugar y las nuevas van al final (contrato con admin-api).
     *
     * @test
     */
    public function las_claves_nuevas_van_al_final()
    {
        $this->articulo(['name' => 'Forma p109', 'stock' => 2]);

        $this->assertSame(
            ['id', 'nombre', 'codigo', 'precio', 'stock', 'tiene_imagen', 'stock_sin_cargar', 'stock_en_depositos', 'publicado_en_tienda', 'link_tienda'],
            array_keys($this->fila_de('Forma p109'))
        );
    }
}
