<?php

namespace Tests\Feature\SaneoStockSucursales;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Base de los tests de `stock:sanear-sucursales-borradas` (misión sanear-stock-de-sucursales-borradas,
 * 6/10/2026).
 *
 * ─── Las reglas de estos tests ────────────────────────────────────────────────────────────────
 *
 * 1. TODO se afirma leyendo las tablas con `DB::table()`: nunca el modelo en memoria ni lo que
 *    imprime el comando (salvo los tests que prueban justamente el reporte).
 * 2. Los fixtures tienen la FORMA REAL de producción, no una cómoda:
 *      - la fila fantasma se arma con `$article->addresses()->attach($id_muerto, ['amount' => n])`,
 *        igual que `CheckFromAddress` (`created_at` / `updated_at` quedan NULL, sin `stock_min`);
 *      - el id muerto sale de CREAR una sucursal y BORRARLA (un id que existió);
 *      - varias filas del mismo par (artículo, sucursal) son lo normal: cada venta contra la
 *        sucursal muerta abre una fila nueva, porque `attach` no ve la anterior;
 *      - `articles.stock` queda como lo deja el motor: la SUMA CRUDA de todas las filas de
 *        `address_article`, fantasmas incluidos.
 * 3. La base del slot NO está limpia (tiene 12 filas fantasma ajenas del artículo 1, de corridas
 *    viejas): cada test usa un DUEÑO PROPIO recién creado, con sus propias sucursales y artículos
 *    "zz", y acota el comando con `--user_id`. Cuando un test corre SIN acotar (a propósito), sus
 *    afirmaciones miran solo sus artículos o miden por diferencia.
 * 4. Un test que nace verde sin el arreglo no prueba nada: cada uno tiene que poder ponerse rojo si
 *    se rompe el comportamiento que cubre, y su mensaje de aserción dice qué se rompió.
 * 5. `--salida` va siempre a una carpeta temporal única, que `tearDown` borra.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class SaneoStockSucursalesTestCase extends EmpresaTestCase
{
    /** Firma del comando que se prueba. */
    const COMANDO = 'stock:sanear-sucursales-borradas';

    /** Concepto con el que el comando etiqueta sus movimientos. */
    const CONCEPTO = 'Actualizacion de deposito';

    /** Observación de esos movimientos (el motor le agrega " - <stock>" al final). */
    const OBSERVACION = 'Baja de sucursal eliminada';

    /**
     * Lo que imprimió la última corrida del comando.
     *
     * @var string
     */
    protected $salida = '';

    /**
     * Carpeta de salida de este test (la ruta; el comando la crea recién al escribir).
     *
     * @var string
     */
    protected $carpeta_de_salida;

    /**
     * Todas las carpetas de salida que pidió el test, para borrarlas en tearDown.
     *
     * @var string[]
     */
    protected $carpetas_a_borrar = [];

    /**
     * Lo que había en `USA_TIENDA_NUBE` antes de que un test la tocara con `prender_tienda_nube()`
     * (null = no se tocó). `tearDown` lo restaura: el entorno es del proceso, no del test.
     *
     * @var array|null
     */
    protected $tienda_nube_anterior = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->carpeta_de_salida = $this->carpeta_nueva();
    }

    protected function tearDown(): void
    {
        $this->restaurar_tienda_nube();

        foreach ($this->carpetas_a_borrar as $carpeta) {
            $this->borrar_carpeta($carpeta);
        }

        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  TIENDA NUBE (--sincronizar)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Pone `USA_TIENDA_NUBE` en el entorno del proceso: `add_article_to_sync()` lee `env()` directo, y
     * `env()` mira `$_SERVER`, `$_ENV` y `getenv()` (la misma técnica de los tests de categorización
     * con IA). `tearDown` la deja como estaba. Cada test que mira la cola de Tienda Nube la fija
     * explícitamente, prendida o apagada, para no depender de lo que traiga el `.env.testing`.
     *
     * @param  string  $valor  'true' (prendida) por defecto; 'false' la apaga.
     * @return void
     */
    protected function prender_tienda_nube($valor = 'true')
    {
        if (is_null($this->tienda_nube_anterior)) {
            $this->tienda_nube_anterior = [
                'server' => array_key_exists('USA_TIENDA_NUBE', $_SERVER) ? $_SERVER['USA_TIENDA_NUBE'] : null,
                'env' => array_key_exists('USA_TIENDA_NUBE', $_ENV) ? $_ENV['USA_TIENDA_NUBE'] : null,
                'putenv' => getenv('USA_TIENDA_NUBE'),
            ];
        }

        $_SERVER['USA_TIENDA_NUBE'] = $valor;
        $_ENV['USA_TIENDA_NUBE'] = $valor;
        putenv('USA_TIENDA_NUBE=' . $valor);
    }

    /**
     * Deja `USA_TIENDA_NUBE` como estaba antes del primer `prender_tienda_nube()` del test.
     *
     * @return void
     */
    protected function restaurar_tienda_nube()
    {
        if (is_null($this->tienda_nube_anterior)) {
            return;
        }

        $anterior = $this->tienda_nube_anterior;

        if (is_null($anterior['server'])) {
            unset($_SERVER['USA_TIENDA_NUBE']);
        } else {
            $_SERVER['USA_TIENDA_NUBE'] = $anterior['server'];
        }

        if (is_null($anterior['env'])) {
            unset($_ENV['USA_TIENDA_NUBE']);
        } else {
            $_ENV['USA_TIENDA_NUBE'] = $anterior['env'];
        }

        if ($anterior['putenv'] === false) {
            putenv('USA_TIENDA_NUBE');
        } else {
            putenv('USA_TIENDA_NUBE=' . $anterior['putenv']);
        }

        $this->tienda_nube_anterior = null;
    }

    /**
     * Deja el artículo "en Tienda Nube" (con su `tiendanube_product_id`), que es lo que mira
     * `add_article_to_sync()` para encolarlo. Se escribe con el query builder, como el resto de los fixtures.
     *
     * @param  \App\Models\Article|int  $articulo
     * @param  int                      $product_id
     * @return void
     */
    protected function articulo_en_tienda_nube($articulo, $product_id = 4242)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        DB::table('articles')->where('id', $id)->update(['tiendanube_product_id' => $product_id]);
    }

    /**
     * Las filas de la cola de Tienda Nube (`sync_to_t_n_articles`) de un artículo, del más viejo al
     * más nuevo, como arreglos.
     *
     * @param  \App\Models\Article|int  $articulo
     * @return array
     */
    protected function cola_tienda_nube($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        return $this->filas(DB::table('sync_to_t_n_articles')->where('article_id', $id)->orderBy('id')->get());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  DUEÑOS Y SUCURSALES
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Un dueño (comercio) propio del test, con una clave aleatoria descartable.
     *
     * @param  string  $etiqueta
     * @return \App\Models\User
     */
    protected function dueno($etiqueta = 'dueno')
    {
        return User::create([
            'name' => 'zz Dueño saneo ' . $etiqueta,
            'email' => 'zz-saneo-' . $etiqueta . '-' . uniqid() . '@test.local',
            'password' => Hash::make(uniqid('', true)),
        ]);
    }

    /**
     * Una sucursal viva del dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  string            $nombre
     * @return \App\Models\Address
     */
    protected function sucursal($dueno, $nombre = 'zz Sucursal saneo')
    {
        return Address::create([
            'street' => $nombre,
            'user_id' => $dueno->id,
            'default_address' => 0,
        ]);
    }

    /**
     * El id de una sucursal que EXISTIÓ y ya no existe: se crea y se borra con el query builder
     * (sin pasar por `AddressController::destroy`, que a propósito deja el stock en regla).
     *
     * @param  \App\Models\User  $dueno
     * @return int
     */
    protected function sucursal_muerta($dueno)
    {
        $sucursal = $this->sucursal($dueno, 'zz Sucursal que se va a borrar');

        $id = (int) $sucursal->id;

        DB::table('addresses')->where('id', $id)->delete();

        $this->assertNull(DB::table('addresses')->where('id', $id)->first(), 'El fixture no pudo borrar la sucursal que iba a quedar muerta.');

        return $id;
    }

    /**
     * Un domicilio de comprador (`buyer_id` no nulo, sin dueño): lo escribe tienda-api en la misma
     * tabla y EXISTE, así que una fila suya no es un fantasma.
     *
     * @return \App\Models\Address
     */
    protected function domicilio_de_comprador()
    {
        return Address::create([
            'street' => 'zz Domicilio de comprador',
            'user_id' => null,
            'buyer_id' => 987654,
            'default_address' => 0,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  ARTÍCULOS, VARIANTES Y FILAS DE PIVOT
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Un artículo propio del test.
     *
     * @param  \App\Models\User  $dueno
     * @param  string            $nombre
     * @param  array             $atributos  Columnas a pisar (por ejemplo ['stock' => 5]).
     * @return \App\Models\Article
     */
    protected function crear_articulo($dueno, $nombre, array $atributos = [])
    {
        return Article::create(array_merge([
            'name' => 'zz ' . $nombre . ' ' . uniqid(),
            'user_id' => $dueno->id,
        ], $atributos));
    }

    /**
     * Una variante del artículo.
     *
     * @param  \App\Models\Article  $articulo
     * @param  int|null             $stock
     * @return \App\Models\ArticleVariant
     */
    protected function variante($articulo, $stock = null)
    {
        return ArticleVariant::create([
            'article_id' => $articulo->id,
            'variant_description' => 'zz Variante ' . uniqid(),
            'stock' => $stock,
        ]);
    }

    /**
     * Una fila de `address_article`, armada como la arma el motor (`attach`): sin timestamps.
     * Sirve igual para una sucursal viva que para una muerta (esa es la fila fantasma).
     *
     * @param  \App\Models\Article  $articulo
     * @param  int                  $address_id
     * @param  mixed                $amount
     * @return int  Id de la fila.
     */
    protected function fila($articulo, $address_id, $amount)
    {
        $articulo->addresses()->attach($address_id, ['amount' => $amount]);

        return (int) DB::table('address_article')
            ->where('article_id', $articulo->id)
            ->where('address_id', $address_id)
            ->max('id');
    }

    /**
     * Una fila de `address_article_variant`, armada como la arma el motor (`attach`).
     *
     * @param  \App\Models\ArticleVariant  $variante
     * @param  int                         $address_id
     * @param  mixed                       $amount
     * @return int  Id de la fila.
     */
    protected function fila_de_variante($variante, $address_id, $amount)
    {
        $variante->addresses()->attach($address_id, ['amount' => $amount]);

        return (int) DB::table('address_article_variant')
            ->where('article_variant_id', $variante->id)
            ->where('address_id', $address_id)
            ->max('id');
    }

    /**
     * Deja `articles.stock` como lo deja el motor en un artículo sin variantes: la SUMA CRUDA de
     * `address_article.amount`, sin mirar si la sucursal existe (la misma sentencia que
     * `ArticleHelper::setArticleStockFromAddresses`).
     *
     * @param  \App\Models\Article  $articulo
     * @return void
     */
    protected function stock_como_el_motor($articulo)
    {
        DB::table('articles')->where('id', $articulo->id)->update([
            'stock' => DB::raw('(SELECT COALESCE(SUM(aa.amount), 0) FROM address_article aa WHERE aa.article_id = ' . (int) $articulo->id . ')'),
        ]);
    }

    /**
     * Un artículo SIN variantes con filas vivas y fantasma, y el stock como lo deja el motor.
     *
     * @param  \App\Models\User  $dueno
     * @param  string            $nombre
     * @param  array             $vivas      address_id => amount (sucursales que existen).
     * @param  array             $fantasmas  Lista de [address_id_muerto, amount]; se repiten pares a gusto.
     * @param  mixed             $stock      'motor' = la suma cruda; cualquier otro valor se escribe tal cual
     *                                       (null incluido): es el "stock desviado de antes".
     * @return array  ['articulo' => Article, 'vivas' => address_id => id de fila, 'fantasmas' => ids de fila]
     */
    protected function articulo_con_fantasmas($dueno, $nombre, array $vivas, array $fantasmas, $stock = 'motor')
    {
        $articulo = $this->crear_articulo($dueno, $nombre);

        $ids_vivas = [];
        $ids_fantasmas = [];

        foreach ($vivas as $address_id => $amount) {
            $ids_vivas[$address_id] = $this->fila($articulo, $address_id, $amount);
        }

        foreach ($fantasmas as $fantasma) {
            $ids_fantasmas[] = $this->fila($articulo, $fantasma[0], $fantasma[1]);
        }

        if ($stock === 'motor') {
            $this->stock_como_el_motor($articulo);
        } else {
            DB::table('articles')->where('id', $articulo->id)->update(['stock' => $stock]);
        }

        return ['articulo' => $articulo, 'vivas' => $ids_vivas, 'fantasmas' => $ids_fantasmas];
    }

    /**
     * Un artículo CON variantes en la forma que deja el motor, y recién después los fantasmas.
     *
     * Primero se cargan las filas vivas de cada variante y se llama a la función del sistema
     * (`setArticleStockFromAddresses`) para que reconstruya el pivot del artículo y los stocks
     * exactamente como lo hace el motor; después se agregan las filas fantasma, que es el orden en
     * que nacen en producción (un movimiento a una sucursal muerta DESPUÉS de la última
     * reconstrucción).
     *
     * @param  \App\Models\User  $dueno
     * @param  string            $nombre
     * @param  array             $variantes  Lista de ['vivas' => address_id => amount, 'fantasmas' => [[muerta, amount]], 'stock' => int|null]
     *                                       ('stock' solo cuenta si la variante no tiene filas vivas).
     * @param  array             $fantasmas_del_articulo  Lista de [address_id_muerto, amount] en `address_article`.
     * @return array  ['articulo', 'variantes' => [Variante...], 'filas_variante' => [[ids de las vivas], [ids de las fantasma]...], 'fantasmas_articulo' => ids]
     */
    protected function articulo_con_variantes($dueno, $nombre, array $variantes, array $fantasmas_del_articulo = [])
    {
        $articulo = $this->crear_articulo($dueno, $nombre);

        $modelos = [];
        $filas_variante = [];

        foreach ($variantes as $definicion) {
            $variante = $this->variante($articulo, isset($definicion['stock']) ? $definicion['stock'] : null);

            $vivas = [];

            if (isset($definicion['vivas'])) {
                foreach ($definicion['vivas'] as $address_id => $amount) {
                    $vivas[] = $this->fila_de_variante($variante, $address_id, $amount);
                }
            }

            $modelos[] = $variante;
            $filas_variante[] = ['vivas' => $vivas, 'fantasmas' => []];
        }

        // El pivot del artículo y los stocks, por el camino del sistema.
        ArticleHelper::setArticleStockFromAddresses(Article::find($articulo->id), false, $dueno->id);

        // Los fantasmas nacen después de esa última reconstrucción.
        foreach ($variantes as $indice => $definicion) {
            if (!isset($definicion['fantasmas'])) {
                continue;
            }

            foreach ($definicion['fantasmas'] as $fantasma) {
                $filas_variante[$indice]['fantasmas'][] = $this->fila_de_variante($modelos[$indice], $fantasma[0], $fantasma[1]);
            }
        }

        $fantasmas_articulo = [];

        foreach ($fantasmas_del_articulo as $fantasma) {
            $fantasmas_articulo[] = $this->fila($articulo, $fantasma[0], $fantasma[1]);
        }

        return [
            'articulo' => $articulo,
            'variantes' => $modelos,
            'filas_variante' => $filas_variante,
            'fantasmas_articulo' => $fantasmas_articulo,
        ];
    }

    /**
     * Un artículo con una variante que tiene una fila VIVA en una sucursal que NO es del dueño, y
     * además una fila fantasma: el motor revienta con `Undefined index` al recalcularlo, así que el
     * saneo lo clasifica `no_recalculable` y no lo toca.
     *
     * No se arma con `articulo_con_variantes()` porque esa función llama a la del sistema para dejar
     * el pivot y los stocks como el motor, y justamente esta combinación la hace reventar: los
     * stocks se escriben a mano.
     *
     * @param  \App\Models\User     $dueno
     * @param  \App\Models\Address  $sucursal_ajena  Sucursal viva de OTRO dueño (o un domicilio de comprador).
     * @param  int                  $muerta          Id de una sucursal borrada.
     * @param  string               $nombre
     * @return array  ['articulo', 'variante', 'fila_viva', 'fila_fantasma']
     */
    protected function articulo_no_recalculable($dueno, $sucursal_ajena, $muerta, $nombre = 'No recalculable')
    {
        $articulo = $this->crear_articulo($dueno, $nombre);

        $variante = $this->variante($articulo, 3);

        $fila_viva = $this->fila_de_variante($variante, $sucursal_ajena->id, 3);
        $fila_fantasma = $this->fila_de_variante($variante, $muerta, -1);

        DB::table('articles')->where('id', $articulo->id)->update(['stock' => 3]);

        return [
            'articulo' => $articulo,
            'variante' => $variante,
            'fila_viva' => $fila_viva,
            'fila_fantasma' => $fila_fantasma,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  LECTURAS (siempre de la base)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * `articles.stock` leído de la base.
     *
     * @param  \App\Models\Article|int  $articulo
     * @return float|null
     */
    protected function stock($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $stock = DB::table('articles')->where('id', $id)->value('stock');

        return is_null($stock) ? null : (float) $stock;
    }

    /**
     * Las filas de `address_article` del artículo, ordenadas por id, como arreglos.
     *
     * @param  \App\Models\Article|int  $articulo
     * @return array
     */
    protected function pivot($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        return $this->filas(DB::table('address_article')->where('article_id', $id)->orderBy('id')->get());
    }

    /**
     * Las filas de `address_article_variant` de la variante, ordenadas por id, como arreglos.
     *
     * @param  \App\Models\ArticleVariant|int  $variante
     * @return array
     */
    protected function pivot_de_variante($variante)
    {
        $id = is_object($variante) ? $variante->id : $variante;

        return $this->filas(DB::table('address_article_variant')->where('article_variant_id', $id)->orderBy('id')->get());
    }

    /**
     * Los movimientos del artículo, del más viejo al más nuevo.
     *
     * @param  \App\Models\Article|int  $articulo
     * @return \Illuminate\Support\Collection  Filas crudas (stdClass).
     */
    protected function movimientos($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        return DB::table('stock_movements')->where('article_id', $id)->orderBy('id')->get();
    }

    /**
     * Los movimientos que dejó el saneo (concepto "Actualizacion de deposito") de un artículo.
     *
     * @param  \App\Models\Article|int  $articulo
     * @return \Illuminate\Support\Collection
     */
    protected function movimientos_del_saneo($articulo)
    {
        $concepto_id = DB::table('concepto_stock_movements')->where('name', self::CONCEPTO)->value('id');

        return $this->movimientos($articulo)->filter(function ($movimiento) use ($concepto_id) {
            return (int) $movimiento->concepto_stock_movement_id === (int) $concepto_id;
        })->values();
    }

    /**
     * Cuántas filas de `address_article` tiene el artículo en una sucursal.
     *
     * @param  \App\Models\Article|int  $articulo
     * @param  int                      $address_id
     * @return int
     */
    protected function filas_en($articulo, $address_id)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        return (int) DB::table('address_article')->where('article_id', $id)->where('address_id', $address_id)->count();
    }

    /**
     * Filas de la base como arreglos asociativos.
     *
     * @param  \Illuminate\Support\Collection  $filas
     * @return array
     */
    protected function filas($filas)
    {
        $lista = [];

        foreach ($filas as $fila) {
            $lista[] = (array) $fila;
        }

        return $lista;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  EL COMANDO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Corre el comando y deja lo que imprimió en `$this->salida`.
     *
     * @param  array  $opciones  Las del comando, con el guion doble (['--aplicar' => true, '--user_id' => 5]).
     * @return int  Código de salida.
     */
    protected function sanear(array $opciones = [])
    {
        $codigo = Artisan::call(self::COMANDO, $opciones);

        $this->salida = Artisan::output();

        return $codigo;
    }

    /**
     * `--aplicar` sobre un dueño, con la carpeta de salida de este test.
     *
     * @param  \App\Models\User  $dueno
     * @param  array             $extra  Opciones que se suman o pisan.
     * @return int
     */
    protected function aplicar($dueno, array $extra = [])
    {
        return $this->sanear(array_merge([
            '--aplicar' => true,
            '--user_id' => $dueno->id,
            '--salida' => $this->carpeta_de_salida,
        ], $extra));
    }

    /**
     * `--ver` sobre un dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  array             $extra
     * @return int
     */
    protected function ver($dueno, array $extra = [])
    {
        return $this->sanear(array_merge([
            '--ver' => true,
            '--user_id' => $dueno->id,
        ], $extra));
    }

    /**
     * Los totales que imprimió el reporte para un dueño (o para "TOTAL"), leídos de la tabla.
     *
     * @param  int|string  $etiqueta  Id del dueño o 'TOTAL'.
     * @return array  filas_articulo, filas_variante, articulos, papelera, unidades_articulo, unidades_variante, desfase.
     */
    protected function fila_del_reporte($etiqueta)
    {
        $patron = '/^\|\s*' . preg_quote((string) $etiqueta, '/') . '\s*\|(.*)\|\s*$/m';

        $this->assertSame(1, preg_match($patron, $this->salida, $coincidencia), 'El reporte no trae la fila "' . $etiqueta . '". Salida:' . "\n" . $this->salida);

        $celdas = array_map('trim', explode('|', $coincidencia[1]));

        return [
            'filas_articulo' => (int) $celdas[0],
            'filas_variante' => (int) $celdas[1],
            'articulos' => (int) $celdas[2],
            'papelera' => (int) $celdas[3],
            'unidades_articulo' => (float) $celdas[4],
            'unidades_variante' => (float) $celdas[5],
            'desfase' => (float) $celdas[6],
        ];
    }

    /**
     * Una carpeta de salida nueva (solo la ruta; no se crea) y anotada para borrarla al terminar.
     *
     * @return string
     */
    protected function carpeta_nueva()
    {
        $carpeta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saneo-stock-sucursales-test-' . uniqid('', true);

        $this->carpetas_a_borrar[] = $carpeta;

        return $carpeta;
    }

    /**
     * Los archivos de una carpeta de salida que terminan en un sufijo.
     *
     * @param  string  $carpeta
     * @param  string  $sufijo  '-respaldo.jsonl' o '-reversion.sql'.
     * @return string[]
     */
    protected function archivos_de($carpeta, $sufijo)
    {
        $archivos = (array) glob($carpeta . DIRECTORY_SEPARATOR . '*' . $sufijo);

        sort($archivos);

        return $archivos;
    }

    /**
     * Las líneas del respaldo JSONL, decodificadas.
     *
     * @param  string  $carpeta
     * @return array
     */
    protected function lineas_del_respaldo($carpeta)
    {
        $archivos = $this->archivos_de($carpeta, '-respaldo.jsonl');

        $this->assertCount(1, $archivos, 'La corrida tenía que dejar UN respaldo JSONL en ' . $carpeta . '.');

        $lineas = [];

        foreach (file($archivos[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $decodificada = json_decode($linea, true);

            $this->assertIsArray($decodificada, 'Una línea del respaldo no es JSON válido: ' . $linea);

            $lineas[] = $decodificada;
        }

        return $lineas;
    }

    /**
     * El SQL de reversión de una carpeta de salida, entero.
     *
     * @param  string  $carpeta
     * @return string
     */
    protected function sql_de_reversion($carpeta)
    {
        $archivos = $this->archivos_de($carpeta, '-reversion.sql');

        $this->assertCount(1, $archivos, 'La corrida tenía que dejar UN SQL de reversión en ' . $carpeta . '.');

        return file_get_contents($archivos[0]);
    }

    /**
     * Ejecuta un SQL de reversión sentencia por sentencia, SIN las líneas de transacción.
     *
     * 🔴 `START TRANSACTION` dentro de la transacción del test hace COMMIT implícito de todo lo
     * sembrado (MySQL no anida transacciones): el fixture quedaría grabado en la base del slot. El
     * archivo real sí lleva START/COMMIT (un test lo verifica aparte); acá se corren solo las
     * sentencias, que están una por línea.
     *
     * @param  string  $sql
     * @return int  Sentencias ejecutadas.
     */
    protected function correr_reversion($sql)
    {
        $ejecutadas = 0;

        foreach (explode("\n", $sql) as $linea) {
            $linea = trim($linea);

            if ($linea === '' || strpos($linea, '--') === 0 || $linea === 'START TRANSACTION;' || $linea === 'COMMIT;') {
                continue;
            }

            DB::unprepared($linea);

            $ejecutadas++;
        }

        return $ejecutadas;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  FOTOS DE LA BASE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Cuenta y huella del contenido COMPLETO de las tablas que el comando podría tocar. Dos fotos
     * iguales significan que no se escribió ni una fila (ni se pisó un valor).
     *
     * Se serializa (no `json_encode`): un valor binario hace fallar al JSON y la huella de un
     * `false` sería siempre la misma — un verde falso.
     *
     * @param  string[]|null  $tablas
     * @return array  tabla => ['filas' => int, 'huella' => string]
     */
    protected function foto_de_tablas(array $tablas = null)
    {
        if (is_null($tablas)) {
            $tablas = ['address_article', 'address_article_variant', 'articles', 'article_variants', 'stock_movements', 'users', 'addresses'];
        }

        $foto = [];

        foreach ($tablas as $tabla) {
            $filas = DB::table($tabla)->orderBy('id')->get()->all();

            $foto[$tabla] = ['filas' => count($filas), 'huella' => md5(serialize($filas))];
        }

        return $foto;
    }

    /**
     * Afirma que dos fotos de `foto_de_tablas()` son idénticas, diciendo CUÁL tabla cambió.
     *
     * @param  array   $antes
     * @param  array   $despues
     * @param  string  $mensaje  Qué se esperaba.
     * @return void
     */
    protected function assertFotosIguales(array $antes, array $despues, $mensaje)
    {
        foreach ($antes as $tabla => $datos) {
            $this->assertSame($datos['filas'], $despues[$tabla]['filas'], $mensaje . ' — cambió la CANTIDAD de filas de ' . $tabla . '.');
            $this->assertSame($datos['huella'], $despues[$tabla]['huella'], $mensaje . ' — cambió el CONTENIDO de ' . $tabla . '.');
        }
    }

    /**
     * Borra las filas fantasma de los artículos que NO son de la lista: los 12 fantasmas del
     * artículo centinela que la base del slot ya trae, y lo que haya dejado cualquier corrida vieja.
     * Sirve para afirmar sobre "hay UN solo dueño con trabajo" (sin esto el dueño 500 siempre tiene
     * trabajo ajeno a lo que el test sembró). Todo ocurre dentro de la transacción del test.
     *
     * @param  int[]  $article_ids_propios  Artículos del test, que NO se tocan.
     * @return void
     */
    protected function limpiar_fantasmas_ajenos(array $article_ids_propios)
    {
        $variantes_propias = DB::table('article_variants')->whereIn('article_id', $article_ids_propios)->pluck('id')->all();

        DB::table('address_article')
            ->whereNotExists(function ($consulta) {
                $consulta->select(DB::raw(1))->from('addresses')->whereColumn('addresses.id', 'address_article.address_id');
            })
            ->whereNotIn('article_id', $article_ids_propios)
            ->delete();

        DB::table('address_article_variant')
            ->whereNotExists(function ($consulta) {
                $consulta->select(DB::raw(1))->from('addresses')->whereColumn('addresses.id', 'address_article_variant.address_id');
            })
            ->whereNotIn('article_variant_id', $variantes_propias)
            ->delete();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  UTILIDADES
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Borra una carpeta y todo lo que tiene (si existe).
     *
     * @param  string  $carpeta
     * @return void
     */
    protected function borrar_carpeta($carpeta)
    {
        if (!is_dir($carpeta)) {
            return;
        }

        foreach ((array) glob($carpeta . DIRECTORY_SEPARATOR . '*') as $archivo) {
            if (is_dir($archivo)) {
                $this->borrar_carpeta($archivo);
            } else {
                @unlink($archivo);
            }
        }

        @rmdir($carpeta);
    }
}
