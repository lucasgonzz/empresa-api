<?php

namespace Tests\Feature\VinculacionDeInventario;

use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\InventoryLinkage;
use App\Models\InventoryLinkageScope;
use App\Models\User;
use App\Models\ExtencionEmpresa;
use App\Services\MercadoLibre\ProductService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Precios\RecalculoEnLote\RecalculoEnLoteTestCase;

/**
 * Base de los tests de la misión `vinculacion-iva-copia-d2m` (PLAN D2-M R3.1, 21/11/2026).
 *
 * Arma el escenario Fénix → Galván: el proveedor (Fénix) y el cliente (Galván) son cuentas legacy RI
 * con `aplicar_iva_al_costo = 1`; Galván tiene a Fénix como proveedor con 10 % de margen; Fénix tiene
 * a Galván como cliente y una vinculación entre los dos. La fuente cuesta 4828 con IVA 21 %.
 *
 * Los números de los tests son la especificación del PLAN: está prohibido ajustar un valor esperado
 * para que coincida con lo que devuelve el sistema.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types,
 * promoción de constructor, propiedades de sólo lectura, enum ni atributos.
 */
abstract class VinculacionDeInventarioTestCase extends RecalculoEnLoteTestCase
{
    /** Precio final de la fuente y sus derivados (CA1). */
    const BRUTO = 4828.00;
    const NETO = 3990.082645;
    const FINAL = 5310.80;
    const FINAL_INFLADO = 6426.07;

    /** Nombre del comando de la misión. */
    const COMANDO = 'vinculacion:costo-neto-de-copias';

    /** @var \App\Models\User */
    protected $fenix;

    /** @var \App\Models\User */
    protected $galvan;

    /** @var \App\Models\Provider Fénix como proveedor de Galván. */
    protected $proveedor_de_galvan;

    /** @var \App\Models\Client Galván como cliente de Fénix. */
    protected $cliente_de_fenix;

    /** @var \App\Models\InventoryLinkage */
    protected $vinculacion;

    /** @var array Eventos de DB::listen (sentencias y nivel de transacción). */
    protected $sentencias = [];

    protected function setUp(): void
    {
        parent::setUp();

        \App\Observers\ArticleObserver::resetear_cache_gate();
    }

    /**
     * Arma el escenario. Deja `actingAs` en Fénix (el que dispara el helper en producción).
     *
     * @param  array $flags_galvan Flags de la cuenta de Galván (condición fiscal, tilde, etc.).
     * @param  bool  $interruptor  Estado del interruptor de la vinculación.
     * @return void
     */
    protected function escenario(array $flags_galvan = [], $interruptor = true)
    {
        $legacy_ri = [
            'aplicar_iva_al_costo'            => 1,
            'usar_condicion_fiscal_en_costeo' => 0,
            'condicion_iva_precios'           => User::CONDICION_RRII,
        ];

        $this->galvan = $this->crear_dueno(array_merge($legacy_ri, $flags_galvan));
        $this->fenix = $this->crear_dueno($legacy_ri);

        $this->proveedor_de_galvan = $this->crear_proveedor($this->galvan, [
            'name'                  => 'zz Fenix (proveedor de Galvan)',
            'percentage_gain'       => 10,
            'comercio_city_user_id' => $this->fenix->id,
        ]);

        $this->cliente_de_fenix = Client::create([
            'user_id'               => $this->fenix->id,
            'name'                  => 'zz Galvan (cliente de Fenix)',
            'comercio_city_user_id' => $this->galvan->id,
        ]);

        $this->vinculacion = $this->crear_vinculacion($this->cliente_de_fenix, $interruptor);

        $this->actingAs(User::find($this->fenix->id), 'web');
    }

    /**
     * Crea una vinculación del dueño de la fuente hacia un cliente. `inventory_linkage_scope_id` no
     * tiene default en la tabla (mismo armado que ChatIa/44_Foto_de_articulo_Test).
     *
     * @param  \App\Models\Client $cliente
     * @param  bool               $interruptor
     * @return \App\Models\InventoryLinkage
     */
    protected function crear_vinculacion($cliente, $interruptor = true)
    {
        $alcance = InventoryLinkageScope::firstOrCreate(['name' => 'zz Todo el catalogo']);

        $datos = [
            'user_id'                    => $cliente->user_id,
            'client_id'                  => $cliente->id,
            'inventory_linkage_scope_id' => $alcance->id,
        ];

        $vinculacion = InventoryLinkage::create($datos);

        if ($interruptor) {
            DB::table('inventory_linkages')->where('id', $vinculacion->id)->update(['copia_en_costo_neto' => 1]);
        }

        return InventoryLinkage::find($vinculacion->id);
    }

    /**
     * La fuente de Fénix con el precio final dado (se escribe por DB: no dispara ningún recálculo).
     *
     * @param  float $final_price
     * @return \App\Models\Article
     */
    protected function crear_fuente($final_price = self::BRUTO)
    {
        $fuente = $this->crear_articulo($this->fenix, ['name' => 'zz Fuente ' . uniqid()]);

        $this->fijar_precio_de_fuente($fuente->id, $final_price);

        return Article::find($fuente->id);
    }

    /**
     * Deja la fuente coherente con su propia cadena de precios (Fénix es legacy RI con la tilde, IVA
     * 21 %, sin margen): costo neto, costo_real = precio final. Así un PUT de la fuente sin cambios
     * vuelve a calcular el mismo `final_price` y no lo mueve.
     *
     * @param  int   $id
     * @param  float $final_price
     * @return void
     */
    protected function fijar_precio_de_fuente($id, $final_price)
    {
        DB::table('articles')->where('id', $id)->update([
            'final_price' => $final_price,
            'cost'        => round($final_price / 1.21, 6),
            'costo_real'  => $final_price,
            'aplicar_iva' => 1,
            'iva_id'      => self::IVA_21,
        ]);
    }

    /**
     * Helper de vinculación tal como lo arman los llamadores reales.
     *
     * @param  \App\Models\InventoryLinkage|null $vinculacion
     * @return \App\Http\Controllers\Helpers\InventoryLinkageHelper
     */
    protected function helper($vinculacion = null)
    {
        return new InventoryLinkageHelper($vinculacion ?: $this->vinculacion, $this->fenix->id);
    }

    /**
     * La copia de una fuente en Galván, releída de la base (con papelera).
     *
     * @param  \App\Models\Article $fuente
     * @param  int|null            $user_id
     * @return \App\Models\Article|null
     */
    protected function copia_de($fuente, $user_id = null)
    {
        return Article::withTrashed()
                        ->where('user_id', $user_id ?: $this->galvan->id)
                        ->where('provider_article_id', $fuente->id)
                        ->first();
    }

    /**
     * Crea la copia por la vía del alta de la vinculación (checkArticle con copia inexistente) y la
     * devuelve releída.
     *
     * @param  \App\Models\Article $fuente
     * @return \App\Models\Article
     */
    protected function crear_copia($fuente)
    {
        $this->helper()->checkArticle($fuente);

        return $this->copia_de($fuente);
    }

    /**
     * Pone una copia en un estado dado por DB (sin recalcular nada).
     *
     * @param  \App\Models\Article $copia
     * @param  array               $columnas
     * @return \App\Models\Article
     */
    protected function fijar($copia, array $columnas)
    {
        DB::table('articles')->where('id', $copia->id)->update($columnas);

        return Article::withTrashed()->find($copia->id);
    }

    /**
     * Una copia "expuesta" como las 596 de hoy: costo bruto, costo_real igual, aplicar_iva 1, marca 0.
     *
     * @param  \App\Models\Article $fuente
     * @return \App\Models\Article
     */
    protected function copia_expuesta($fuente)
    {
        $copia = $this->crear_copia($fuente);

        $columnas = [
            'aplicar_iva' => 1,
            'cost'        => $fuente->final_price,
            'costo_real'  => $fuente->final_price,
            'final_price' => self::FINAL,
        ];

        if (Schema::hasColumn('articles', 'costo_neto_de_vinculacion')) {
            $columnas['costo_neto_de_vinculacion'] = 0;
        }

        return $this->fijar($copia, $columnas);
    }

    /**
     * La fuente pasa a F' sin disparar checkArticle, y se devuelve releída (el helper no la relee).
     *
     * @param  \App\Models\Article $fuente
     * @param  float               $final_price
     * @return \App\Models\Article
     */
    protected function mover_fuente($fuente, $final_price = 6050.00)
    {
        $this->fijar_precio_de_fuente($fuente->id, $final_price);

        return Article::find($fuente->id);
    }

    /**
     * Foto de todo lo que un recálculo puede escribir fuera de la fila de la copia: cuenta de filas
     * de las tablas de efectos más la fila entera de cada copia indicada (PLAN, Archivos).
     *
     * @param  array $copias_ids
     * @return array
     */
    protected function foto_efectos(array $copias_ids = [])
    {
        $foto = [];

        foreach (['price_changes', 'price_change_price_type', 'article_price_type', 'sync_to_meli_articles', 'sync_to_t_n_articles', 'jobs'] as $tabla) {
            $foto[$tabla] = DB::table($tabla)->count();
        }

        foreach ($copias_ids as $id) {
            $foto['articulo_' . $id] = (array) DB::table('articles')->where('id', $id)->first();
        }

        return $foto;
    }

    /**
     * Corre el comando de la misión y devuelve [código de salida, salida].
     *
     * @param  array $parametros
     * @return array
     */
    protected function comando(array $parametros)
    {
        $codigo = Artisan::call(self::COMANDO, $parametros);

        return [$codigo, Artisan::output()];
    }

    /**
     * Escucha las sentencias SQL con su nivel de transacción (CA15e).
     *
     * @return void
     */
    protected function escuchar_sentencias()
    {
        $this->sentencias = [];

        DB::listen(function ($consulta) {
            $this->sentencias[] = [
                'sql'      => strtolower($consulta->sql),
                'bindings' => $consulta->bindings,
                'nivel'    => DB::transactionLevel(),
            ];
        });
    }

    /**
     * Valores guardados de una copia, redondeados como los comparan los CA.
     *
     * @param  int $id
     * @return array
     */
    protected function valores($id)
    {
        $fila = DB::table('articles')->where('id', $id)->first();

        return [
            'cost'       => round((float) $fila->cost, 6),
            'costo_real' => round((float) $fila->costo_real, 6),
            'final'      => round((float) $fila->final_price, 2),
            'marca'      => $this->marca_de($id),
            'aplicar_iva' => $fila->aplicar_iva,
        ];
    }
    /**
     * Payload de un PUT de la ficha desde las columnas reales del artículo (mismo criterio que
     * Costeo/CostoBrutoEnElAbmTest::payload): los arrays de relaciones y `cost_incluye_iva` viajan
     * siempre, como en la SPA.
     *
     * @param  \App\Models\Article $article
     * @param  array               $overrides
     * @return array
     */
    protected function payload_ficha($article, array $overrides = [])
    {
        $article = Article::find($article->id);

        return array_merge($article->getAttributes(), [
            'id'                 => $article->id,
            'aplicar_iva'        => $article->aplicar_iva,
            'cost_incluye_iva'   => 0,
            'price_types'        => [],
            'price_type_monedas' => [],
            'tags'               => [],
        ], $overrides);
    }
    /**
     * La marca de una copia. Si la columna todavía no existe devuelve 0: los CA "guarda" (marca 0 =
     * comportamiento de hoy) tienen que poder correr y pasar sobre el código sin la migración.
     *
     * @param  int $id
     * @return int
     */
    protected function marca_de($id)
    {
        if (!Schema::hasColumn('articles', 'costo_neto_de_vinculacion')) {
            return 0;
        }

        return (int) DB::table('articles')->where('id', $id)->value('costo_neto_de_vinculacion');
    }
    /**
     * Vende una unidad de la copia desde la cuenta de Galván (POST api/sale, mismo armado que
     * Sales/26_Factura_E_No_Deja_La_Ganancia_En_Null_Test) y devuelve la venta. Deja `actingAs` en
     * Fénix al terminar.
     *
     * @param  \App\Models\Article $copia
     * @param  float               $precio
     * @return \App\Models\Sale
     */
    protected function vender($copia, $precio = self::FINAL)
    {
        $this->actingAs(User::find($this->galvan->id), 'web');

        $respuesta = $this->postJson('api/sale', [
            'client_id'                        => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'sub_total'                        => $precio,
            'total'                            => $precio,
            'terminada'                        => 1,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [[
                'is_article'   => true,
                'id'           => $copia->id,
                'price_vender' => $precio,
                'amount'       => 1,
                'costo_real'   => Article::find($copia->id)->costo_real,
            ]],
        ]);

        $this->actingAs(User::find($this->fenix->id), 'web');

        if ($respuesta->getStatusCode() !== 201) {
            $this->fail('POST api/sale devolvió ' . $respuesta->getStatusCode() . ': ' . $respuesta->getContent());
        }

        return \App\Models\Sale::find($respuesta->json('model.id'));
    }

    /**
     * Escribe un archivo con ids (uno por línea) y devuelve su ruta (para `--ids` de E1).
     *
     * @param  array $ids
     * @return string
     */
    protected function ids_en_archivo(array $ids)
    {
        $ruta = sys_get_temp_dir() . '/vinciva_ids_' . uniqid() . '.txt';
        file_put_contents($ruta, implode("\n", $ids) . "\n");

        return $ruta;
    }

    /**
     * Carpeta temporal para el TSV de auditoría de E1 (`--salida`).
     *
     * @return string
     */
    protected function carpeta_de_salida()
    {
        $carpeta = sys_get_temp_dir() . '/vinciva_salida_' . uniqid();
        mkdir($carpeta);

        return $carpeta;
    }

    /**
     * Corre E1 sobre una lista de copias de Galván.
     *
     * @param  array $ids
     * @param  bool  $aplicar
     * @return array [código, salida]
     */
    protected function convertir($ids, $aplicar = true)
    {
        $parametros = [
            '--cliente' => $this->galvan->id,
            '--ids'     => $this->ids_en_archivo($ids),
            '--salida'  => $this->carpeta_de_salida(),
        ];

        if ($aplicar) {
            $parametros['--aplicar'] = true;
        }

        return $this->comando($parametros);
    }
    /** @var array Valores previos de USA_TIENDA_NUBE para restaurar. */
    protected $flag_previo = null;

    /**
     * Prende las salidas a Tienda Nube y Mercado Libre de Galván y deja la copia "publicada" en las dos
     * (CA9): la lista «Precio final», `tiendanube_product_id`, `disponible_tienda_nube`, ML habilitado y
     * con publicación. Hay que llamarlo después de crear la copia y antes de simular o aplicar.
     *
     * @param  \App\Models\Article $copia
     * @return void
     */
    protected function prender_salidas($copia)
    {
        $this->flag_previo = [
            'server' => array_key_exists('USA_TIENDA_NUBE', $_SERVER) ? $_SERVER['USA_TIENDA_NUBE'] : null,
            'env'    => array_key_exists('USA_TIENDA_NUBE', $_ENV) ? $_ENV['USA_TIENDA_NUBE'] : null,
            'putenv' => getenv('USA_TIENDA_NUBE'),
        ];

        $_SERVER['USA_TIENDA_NUBE'] = 'true';
        $_ENV['USA_TIENDA_NUBE'] = 'true';
        putenv('USA_TIENDA_NUBE=true');

        $extension = ExtencionEmpresa::where('slug', 'usa_mercado_libre')->first();

        if (!$extension) {
            $extension = ExtencionEmpresa::forceCreate(['name' => 'usa_mercado_libre', 'slug' => 'usa_mercado_libre']);
        }

        User::find($this->galvan->id)->extencions()->syncWithoutDetaching([$extension->id]);
        ProductService::olvidar_cache_del_modulo();

        $this->crear_lista($this->galvan, 'Precio final', null, 1);

        DB::table('articles')->where('id', $copia->id)->update([
            'tiendanube_product_id'  => 700000 + (int) $copia->id,
            'disponible_tienda_nube' => 1,
            'mercado_libre'          => 1,
            'meli_category_id'       => 'MLA1234',
            'me_li_id'               => 'MLA' . $copia->id,
            'stock'                  => 5,
        ]);

        // Mercado Libre sólo encola con al menos una imagen (ProductService::add_article_to_sync).
        \App\Models\Image::create([
            'hosting_url'    => 'https://test.local/zz-' . $copia->id . '.jpg',
            'imageable_id'   => $copia->id,
            'imageable_type' => 'article',
        ]);
    }

    protected function tearDown(): void
    {
        if (!is_null($this->flag_previo)) {
            foreach (['server' => '_SERVER', 'env' => '_ENV'] as $clave => $global) {
                if (is_null($this->flag_previo[$clave])) {
                    unset($GLOBALS[$global]['USA_TIENDA_NUBE']);
                } else {
                    $GLOBALS[$global]['USA_TIENDA_NUBE'] = $this->flag_previo[$clave];
                }
            }

            putenv($this->flag_previo['putenv'] === false ? 'USA_TIENDA_NUBE' : 'USA_TIENDA_NUBE=' . $this->flag_previo['putenv']);
            $this->flag_previo = null;
            ProductService::olvidar_cache_del_modulo();
        }

        parent::tearDown();
    }
}
