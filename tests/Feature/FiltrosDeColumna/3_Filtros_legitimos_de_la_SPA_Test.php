<?php

namespace Tests\Feature\FiltrosDeColumna;

use App\Models\Category;
use App\Models\Image;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Nada de lo que la SPA manda hoy se rompe con la guarda de keys (misión
 * filtros-key-sin-inyeccion, 5/10/2026).
 *
 * Los filtros salen del inventario de la SPA (src/models/*.js pasados por
 * build_table_filters_from_props() de common-vue/mixins/generals.js): mismos keys, mismos tipos,
 * mismo relation_prop_name, y el objeto entero con sus valores "vacíos" (checkbox -1, en_blanco 0,
 * igual_que 0 en select/search). Van por la ruta real que usa el listado (`global-search`), salvo
 * el N° de factura de ventas, que la SPA manda por `search` (store/sale/index.js).
 *
 * @group filtros_key_sin_inyeccion
 */
class Filtros_legitimos_de_la_SPA_Test extends FiltrosDeColumnaTestCase
{
    /**
     * Los filtros de la tabla de artículos, tal como salen de models/article.js
     * ([key, type, relation_prop_name]). `article_properties` no trae type.
     */
    const FILTROS_ARTICULO = [
        ['id', 'number', null],
        ['images', 'images', null],
        ['bar_code', 'text', null],
        ['sku', 'text', null],
        ['provider_code', 'text', null],
        ['name', 'textarea', null],
        ['provider_id', 'search', null],
        ['apply_provider_percentage_gain', 'checkbox', null],
        ['plu', 'text', null],
        ['es_insumo', 'checkbox', null],
        ['cost', 'number', null],
        ['iva_id', 'select', 'percentage'],
        ['aplicar_iva', 'checkbox', null],
        ['cost_in_dollars', 'checkbox', null],
        ['costo_real', 'number', null],
        ['costo_mano_de_obra', 'number', null],
        ['unidades_individuales', 'number', null],
        ['percentage_gain', 'number', null],
        ['percentage_gain_blanco', 'number', null],
        ['price', 'number', null],
        ['final_price', 'number', null],
        ['final_price_blanco', 'number', null],
        ['final_price_updated_at', 'date', null],
        ['previus_final_price', 'number', null],
        ['espesor', 'text', null],
        ['modelo', 'text', null],
        ['pastilla', 'text', null],
        ['diametro', 'text', null],
        ['litros', 'text', null],
        ['contenido', 'text', null],
        ['cm3', 'text', null],
        ['calipers', 'text', null],
        ['juego', 'text', null],
        ['bodega_id', 'select', null],
        ['cepa_id', 'select', null],
        ['origen', 'text', null],
        ['presentacion', 'number', null],
        ['omitir_en_lista_pdf', 'checkbox', null],
        ['stock', 'number', null],
        ['stock_min', 'number', null],
        ['stock_updated_at', 'date', null],
        ['unidad_medida_id', 'select', null],
        ['medida', 'number', null],
        ['category_id', 'search', null],
        ['sub_category_id', 'search', null],
        ['brand_id', 'search', null],
        ['descripcion', 'textarea', null],
        ['online', 'checkbox', null],
        ['featured', 'checkbox', null],
        ['in_offer', 'checkbox', null],
        ['precio_pausado', 'checkbox', null],
        ['disponible_tienda_nube', 'checkbox', null],
        ['seo_title', 'text', null],
        ['seo_description', 'text', null],
        ['precio_promocional', 'number', null],
        ['video_url', 'text', null],
        ['mercado_libre', 'checkbox', null],
        ['meli_listing_type_id', 'select', null],
        ['meli_buying_mode_id', 'select', null],
        ['meli_item_condition_id', 'select', null],
        ['meli_descripcion', 'textarea', null],
        ['unidades_por_bulto', 'text', null],
        ['contenido', 'text', null],
        ['tipo_envase_id', 'select', null],
        ['default_in_vender', 'number', null],
        ['personalizar_price_en_vender', 'checkbox', null],
        ['requires_shipping', 'checkbox', null],
        ['free_shipping', 'checkbox', null],
        ['peso', 'number', null],
        ['alto', 'number', null],
        ['ancho', 'number', null],
        ['profundidad', 'number', null],
        ['article_properties', null, null],
    ];

    /**
     * Los filtros de la tabla de clientes (models/client.js). `limites_credito` es de tipo
     * `display` y NO es una columna de clients.
     */
    const FILTROS_CLIENTE = [
        ['id', 'number', null],
        ['name', 'text', null],
        ['saldo_pesos', 'number', null],
        ['saldo_dolares', 'number', null],
        ['pais_exportacion_id', 'select', null],
        ['address_id', 'select', 'street'],
        ['price_type_id', 'select', null],
        ['limites_credito', 'display', null],
        ['pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar', 'checkbox', null],
        ['seller_id', 'select', null],
        ['description', 'textarea', null],
        ['phone', 'text', null],
        ['email', 'text', null],
        ['provincia_id', 'search', null],
        ['location_id', 'search', null],
        ['address', 'text', null],
        ['link_google_maps', 'text', null],
        ['iva_condition_id', 'select', null],
        ['cuit', 'number', null],
        ['dni', 'number', null],
        ['razon_social', 'text', null],
        ['client_reputation_id', 'select', null],
    ];

    /** @var string Prefijo único de los artículos de cada test. */
    protected $prefijo;

    /** @var array<string,int> ids de los artículos de prueba: a1, a2, a3. */
    protected $articulos = [];

    /** @var array<string,int> ids de las categorías: b (la más vieja), a, c (borrada). */
    protected $categorias = [];

    /** @var int */
    protected $proveedor_id;

    /**
     * Tres artículos del dueño con valores distintos en cada columna que se filtra:
     *
     *  a1: stock 5,  featured 1,    categoría "B" (la de id más bajo), IVA 27, stock_updated 10/1, con proveedor e imagen
     *  a2: stock 15, featured 0,    categoría "A",                     IVA 21, stock_updated 10/3
     *  a3: stock 25, featured NULL, categoría "C" BORRADA,             IVA 5,  stock_updated NULL
     *
     * Por nombre de categoría el orden es a2, a1, a3; por el id del FK sería a1, a2, a3. Por
     * porcentaje de IVA (texto) es "21", "27", "5" → a2, a1, a3; por el id del FK, 1, 2, 4 → a1, a2, a3.
     *
     * @return void
     */
    protected function sembrar_articulos()
    {
        $this->prefijo = 'ZZSPA' . uniqid();

        foreach (['b' => 'B', 'a' => 'A', 'c' => 'C'] as $clave => $letra) {
            $categoria = new Category();
            $categoria->name = 'ZZ ' . $letra . ' categoria filtros key';
            $categoria->user_id = $this->dueno->id;
            $categoria->save();

            $this->categorias[$clave] = (int) $categoria->id;
        }

        $this->proveedor_id = (int) DB::table('providers')->insertGetId([
            'user_id'    => $this->dueno->id,
            'name'       => 'Proveedor filtros key',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->articulos['a1'] = $this->articulo_de($this->dueno->id, $this->prefijo . ' alfa', [
            'bar_code'         => $this->prefijo . '-BC1',
            'stock'            => 5,
            'featured'         => 1,
            'category_id'      => $this->categorias['b'],
            'iva_id'           => 1,
            'provider_id'      => $this->proveedor_id,
            'stock_updated_at' => '2026-01-10 10:00:00',
        ]);

        $this->articulos['a2'] = $this->articulo_de($this->dueno->id, $this->prefijo . ' beta', [
            'bar_code'         => $this->prefijo . '-BC2',
            'stock'            => 15,
            'featured'         => 0,
            'category_id'      => $this->categorias['a'],
            'iva_id'           => 2,
            'provider_id'      => null,
            'stock_updated_at' => '2026-03-10 10:00:00',
        ]);

        $this->articulos['a3'] = $this->articulo_de($this->dueno->id, $this->prefijo . ' gamma', [
            'bar_code'         => $this->prefijo . '-BC3',
            'stock'            => 25,
            'featured'         => null,
            'category_id'      => $this->categorias['c'],
            'iva_id'           => 4,
            'provider_id'      => null,
            'stock_updated_at' => null,
        ]);

        Category::find($this->categorias['c'])->delete();

        Image::create(['hosting_url' => 'https://ejemplo.test/filtros-key.webp', 'imageable_id' => $this->articulos['a1'], 'imageable_type' => 'article']);
    }

    /**
     * Los ids que devuelve el listado de artículos (global-search, como la SPA) con esos filtros,
     * acotado por texto a los artículos de la prueba. EN EL ORDEN en que vienen.
     *
     * @param  array  $filtros
     * @return int[]
     */
    protected function ids_del_listado(array $filtros)
    {
        $respuesta = $this->postJson('api/global-search/article', [
            'query_value'    => $this->prefijo,
            'props'          => [['text' => 'nombre', 'key' => 'name', 'keyword_mode' => 'todas']],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => $filtros,
            'conector'       => 'or',
        ]);

        $this->assertSame(200, $respuesta->getStatusCode(), 'global-search/article dio ' . $respuesta->getStatusCode() . ': ' . substr((string) $respuesta->getContent(), 0, 300));

        return $this->ids_de($respuesta);
    }

    /**
     * Los ids de los artículos de prueba pedidos, ordenados de menor a mayor (para comparar
     * conjuntos sin depender del orden).
     *
     * @param  string[]  $claves  'a1', 'a2', 'a3'
     * @return int[]
     */
    protected function ids(array $claves)
    {
        $ids = [];
        foreach ($claves as $clave) {
            $ids[] = $this->articulos[$clave];
        }
        sort($ids);

        return $ids;
    }

    /**
     * @param  array  $filtros
     * @return int[]  ordenados de menor a mayor
     */
    protected function conjunto(array $filtros)
    {
        $ids = $this->ids_del_listado($filtros);
        sort($ids);

        return $ids;
    }

    /** @test */
    public function text_que_contenga_e_igual_que()
    {
        $this->sembrar_articulos();

        // name es textarea en models/article.js; "que contenga" parte por espacios.
        $this->assertSame($this->ids(['a2']), $this->conjunto([$this->filtro_spa('name', 'textarea', ['que_contenga' => $this->prefijo . ' bet'])]));
        $this->assertSame($this->ids(['a2']), $this->conjunto([$this->filtro_spa('bar_code', 'text', ['igual_que' => $this->prefijo . '-BC2'])]));
        $this->assertSame($this->ids(['a1']), $this->conjunto([$this->filtro_spa('bar_code', 'text', ['que_contenga' => '-BC1'])]));
    }

    /** @test */
    public function number_con_los_tres_operadores_y_num_de_articulo()
    {
        $this->sembrar_articulos();

        $this->assertSame($this->ids(['a1']), $this->conjunto([$this->filtro_spa('stock', 'number', ['menor_que' => '10'])]));
        $this->assertSame($this->ids(['a2']), $this->conjunto([$this->filtro_spa('stock', 'number', ['igual_que' => '15'])]));
        $this->assertSame($this->ids(['a3']), $this->conjunto([$this->filtro_spa('stock', 'number', ['mayor_que' => '20'])]));

        // `num` de artículo se sigue mapeando a `id` (los artículos tienen las dos columnas).
        $this->assertSame($this->ids(['a2']), $this->conjunto([$this->filtro_spa('num', 'number', ['igual_que' => (string) $this->articulos['a2']])]));
    }

    /** @test */
    public function select_y_search_igual_que_en_blanco_y_no_en_blanco_con_la_relacion_borrada()
    {
        $this->sembrar_articulos();

        $this->assertSame($this->ids(['a1']), $this->conjunto([$this->filtro_spa('provider_id', 'search', ['igual_que' => $this->proveedor_id])]));
        $this->assertSame($this->ids(['a2']), $this->conjunto([$this->filtro_spa('iva_id', 'select', ['igual_que' => 2], 'percentage')]));

        // a3 apunta a una categoría borrada: el listado la muestra vacía, así que es "en blanco".
        $this->assertSame($this->ids(['a3']), $this->conjunto([$this->filtro_spa('category_id', 'search', ['en_blanco' => 1])]));
        $this->assertSame($this->ids(['a1', 'a2']), $this->conjunto([$this->filtro_spa('category_id', 'search', ['no_en_blanco' => 1])]));

        // Proveedor en blanco: a2 y a3 no tienen.
        $this->assertSame($this->ids(['a2', 'a3']), $this->conjunto([$this->filtro_spa('provider_id', 'search', ['en_blanco' => 1])]));
    }

    /** @test */
    public function date_con_los_tres_operadores_y_en_blanco()
    {
        $this->sembrar_articulos();

        $this->assertSame($this->ids(['a1']), $this->conjunto([$this->filtro_spa('stock_updated_at', 'date', ['menor_que' => '2026-02-01'])]));
        $this->assertSame($this->ids(['a2']), $this->conjunto([$this->filtro_spa('stock_updated_at', 'date', ['igual_que' => '2026-03-10'])]));
        $this->assertSame($this->ids(['a2']), $this->conjunto([$this->filtro_spa('stock_updated_at', 'date', ['mayor_que' => '2026-02-01'])]));
        $this->assertSame($this->ids(['a3']), $this->conjunto([$this->filtro_spa('stock_updated_at', 'date', ['en_blanco' => 1])]));
    }

    /** @test */
    public function checkbox_cero_y_uno_e_imagenes()
    {
        $this->sembrar_articulos();

        $this->assertSame($this->ids(['a1']), $this->conjunto([$this->filtro_spa('featured', 'checkbox', ['checkbox' => 1])]));
        // NULL cuenta como desactivado.
        $this->assertSame($this->ids(['a2', 'a3']), $this->conjunto([$this->filtro_spa('featured', 'checkbox', ['checkbox' => 0])]));

        $this->assertSame($this->ids(['a1']), $this->conjunto([$this->filtro_spa('images', 'images', ['no_en_blanco' => 1])]));
        $this->assertSame($this->ids(['a2', 'a3']), $this->conjunto([$this->filtro_spa('images', 'images', ['en_blanco' => 1])]));
    }

    /** @test */
    public function ordenar_de_por_la_columna_visible_de_la_relacion_y_por_columna_propia()
    {
        $this->sembrar_articulos();

        // Categoría por nombre (A, B, C), no por id del FK (B, A, C).
        $this->assertSame(
            [$this->articulos['a2'], $this->articulos['a1'], $this->articulos['a3']],
            $this->ids_del_listado([$this->filtro_spa('category_id', 'search', ['ordenar_de' => 'ASC'])])
        );

        // IVA por porcentaje ("21", "27", "5"), no por id (1, 2, 4).
        $this->assertSame(
            [$this->articulos['a2'], $this->articulos['a1'], $this->articulos['a3']],
            $this->ids_del_listado([$this->filtro_spa('iva_id', 'select', ['ordenar_de' => 'ASC'], 'percentage')])
        );

        // Columna propia, descendente.
        $this->assertSame(
            [$this->articulos['a3'], $this->articulos['a2'], $this->articulos['a1']],
            $this->ids_del_listado([$this->filtro_spa('stock', 'number', ['ordenar_de' => 'DESC'])])
        );
    }

    /** @test */
    public function el_numero_de_factura_de_ventas_trae_solo_la_venta_propia()
    {
        $otro = $this->otro_dueno();
        $cbte = (string) mt_rand(70000000, 79999999);
        $ahora = date('Y-m-d H:i:s');

        $propia = DB::table('sales')->insertGetId(['user_id' => $this->dueno->id, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $ajena = DB::table('sales')->insertGetId(['user_id' => $otro->id, 'created_at' => $ahora, 'updated_at' => $ahora]);

        DB::table('afip_tickets')->insert(['sale_id' => $propia, 'cbte_numero' => $cbte, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('afip_tickets')->insert(['sale_id' => $ajena, 'cbte_numero' => $cbte, 'created_at' => $ahora, 'updated_at' => $ahora]);

        // El filtro tal como lo arma store/sale/index.js (su key no es una columna de sales).
        $respuesta = $this->postJson('api/search/sale', [
            'filters' => [[
                'key'          => 'afip_ticket_cbte_numero',
                'type'         => 'afip_ticket_cbte_numero',
                'text'         => 'N° de factura',
                'que_contenga' => $cbte,
            ]],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([(int) $propia], $this->ids_de($respuesta));
    }

    /** @test */
    public function un_pedido_con_todos_los_filtros_inertes_anda_aunque_haya_keys_que_no_son_columna()
    {
        $this->sembrar_articulos();

        // Artículos: todos los filtros de la tabla sin tocar (incluido article_properties, sin type).
        $inertes = [];
        foreach (self::FILTROS_ARTICULO as $prop) {
            $inertes[] = $this->filtro_spa($prop[0], $prop[1], [], $prop[2]);
        }

        $this->assertSame($this->ids(['a1', 'a2', 'a3']), $this->conjunto($inertes));

        // Y con un criterio puesto en uno solo, ese es el único que filtra.
        $con_uno = $inertes;
        foreach ($con_uno as $i => $filtro) {
            if ($filtro['key'] === 'stock') {
                $con_uno[$i]['mayor_que'] = '10';
            }
        }
        $this->assertSame($this->ids(['a2', 'a3']), $this->conjunto($con_uno));

        // Clientes: incluye limites_credito (tipo display, no es columna), por search y global-search.
        $texto = 'ZZINERTES' . uniqid();
        $cliente = $this->cliente_de($this->dueno->id, $texto);

        $filtros_cliente = [];
        foreach (self::FILTROS_CLIENTE as $prop) {
            $criterio = $prop[0] === 'name' ? ['que_contenga' => $texto] : [];
            $filtros_cliente[] = $this->filtro_spa($prop[0], $prop[1], $criterio, $prop[2]);
        }

        $respuesta = $this->postJson('api/search/client', ['filters' => $filtros_cliente]);
        $respuesta->assertStatus(200);
        $this->assertSame([(int) $cliente->id], $this->ids_de($respuesta));

        $respuesta = $this->postJson('api/global-search/client', [
            'query_value'    => '',
            'props'          => [],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => $filtros_cliente,
            'conector'       => 'or',
        ]);
        $respuesta->assertStatus(200);
        $this->assertSame([(int) $cliente->id], $this->ids_de($respuesta));
    }

    /** @test */
    public function la_masiva_por_filtro_con_la_tabla_entera_de_filtros_resuelve_solo_lo_filtrado()
    {
        Queue::fake();

        $this->sembrar_articulos();

        // El filter_form de una masiva es state.filters entero: los inertes (con keys que no son
        // columna) también viajan.
        $filter_form = [];
        foreach (self::FILTROS_ARTICULO as $prop) {
            $criterio = $prop[0] === 'name' ? ['que_contenga' => $this->prefijo . ' alfa'] : [];
            $filter_form[] = $this->filtro_spa($prop[0], $prop[1], $criterio, $prop[2]);
        }

        $respuesta = $this->putJson('api/update/article', [
            'from_filter' => 1,
            'filter_form' => $filter_form,
            'update_form' => [
                ['type' => 'number', 'key' => 'increment_cost', 'value' => 5],
            ],
            'models_id'   => [],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame(1, (int) $respuesta->json('queued_count'), 'La masiva tenía que resolver solo el artículo filtrado.');
    }

    /** @test */
    public function un_order_relation_prop_que_no_es_columna_de_la_relacionada_ordena_por_el_fk()
    {
        $texto = 'ZZORDENFK' . uniqid();

        // afip_information no tiene `name`, y la SPA manda order_relation_prop 'name' por defecto
        // para el select de models/address.js. Antes: 500 al ordenar.
        $mayor = DB::table('addresses')->insertGetId(['user_id' => $this->dueno->id, 'street' => $texto . ' uno', 'default_afip_information_id' => 900002]);
        $menor = DB::table('addresses')->insertGetId(['user_id' => $this->dueno->id, 'street' => $texto . ' dos', 'default_afip_information_id' => 900001]);

        $respuesta = $this->postJson('api/global-search/address', [
            'query_value'    => '',
            'props'          => [],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => [
                $this->filtro_spa('street', 'text', ['que_contenga' => $texto]),
                $this->filtro_spa('default_afip_information_id', 'select', ['ordenar_de' => 'ASC']),
            ],
            'conector'       => 'or',
        ]);

        $this->assertSame(200, $respuesta->getStatusCode(), substr((string) $respuesta->getContent(), 0, 300));
        $this->assertSame([(int) $menor, (int) $mayor], $this->ids_de($respuesta));
    }
}
