<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\Provider;
use App\Http\Controllers\Helpers\ArticleHelper;

/**
 * Siembra el escenario de artículos y proveedores que usan todos los tests de
 * importación de Excel.
 *
 * Se ejecuta en el setUp() de ImportTestCase, DENTRO de la transacción de
 * DatabaseTransactions, así que cada test arranca con exactamente este estado
 * y no deja nada al terminar.
 *
 * IMPORTANTE (PHP 7.4): este repo corre PHP 7.4 en producción. No usar match,
 * str_contains, nullsafe (?->), argumentos nombrados, union types, promoción de
 * constructor, readonly, enum ni atributos #[...].
 */
class ImportTestSeeder
{
    /**
     * Escenario completo. Cada entrada documenta qué rama de
     * ArticleIndexCache::find_with_index() habilita.
     *
     * Claves: A1..A15. Los tests referencian por clave, nunca por id.
     *
     * @var array
     */
    protected static $definiciones = [

        /* Match limpio por cualquier escalón, dentro del proveedor A. */
        'A1'  => ['name' => 'Art unico prov A',        'bar_code' => '7790001', 'sku' => 'SKU-001', 'provider_code' => 'PC-100',  'prov' => 'A',    'cost' => 100,  'stock' => 10],

        /* Match limpio pero en OTRO proveedor: sirve para actualizar_articulos_de_otro_proveedor. */
        'A2'  => ['name' => 'Art unico prov B',        'bar_code' => '7790002', 'sku' => 'SKU-002', 'provider_code' => 'PC-200',  'prov' => 'B',    'cost' => 200,  'stock' => 20],

        /* provider_code REPETIDO dentro del MISMO proveedor: ambiguo si no se permiten repetidos,
           colección de 2 artículos si se permiten. */
        'A3'  => ['name' => 'Art PC repetido uno',     'bar_code' => '7790003', 'sku' => 'SKU-003', 'provider_code' => 'PC-DUP',  'prov' => 'A',    'cost' => 300,  'stock' => 30],
        'A4'  => ['name' => 'Art PC repetido dos',     'bar_code' => '7790004', 'sku' => 'SKU-004', 'provider_code' => 'PC-DUP',  'prov' => 'A',    'cost' => 400,  'stock' => 40],

        /* Mismo provider_code en DOS proveedores distintos (ninguno es el de la importación):
           habilita el bloqueo por otro proveedor y la ambigüedad cruzada. */
        'A5'  => ['name' => 'Art PC cruzado en B',     'bar_code' => '7790005', 'sku' => 'SKU-005', 'provider_code' => 'PC-CROSS','prov' => 'B',    'cost' => 500,  'stock' => 50],
        'A6'  => ['name' => 'Art PC cruzado en C',     'bar_code' => '7790006', 'sku' => 'SKU-006', 'provider_code' => 'PC-CROSS','prov' => 'C',    'cost' => 600,  'stock' => 60],

        /* bar_code DUPLICADO en base: el escalón bar_code no tiene bandera de configuración,
           siempre tiene que dar ambiguo y no tocar ninguno de los dos. */
        'A7'  => ['name' => 'Art bar code repetido 1', 'bar_code' => '7790007', 'sku' => 'SKU-007', 'provider_code' => 'PC-700',  'prov' => 'A',    'cost' => 700,  'stock' => 70],
        'A8'  => ['name' => 'Art bar code repetido 2', 'bar_code' => '7790007', 'sku' => 'SKU-008', 'provider_code' => 'PC-800',  'prov' => 'A',    'cost' => 800,  'stock' => 80],

        /* Sin ningún identificador: solo se puede alcanzar por el escalón name. */
        'A9'  => ['name' => 'Art solo por nombre',     'bar_code' => null,      'sku' => null,      'provider_code' => null,      'prov' => 'A',    'cost' => 900,  'stock' => 90],

        /* Nombre repetido: el escalón name también tiene que dar ambiguo. */
        'A10' => ['name' => 'Art nombre repetido',     'bar_code' => '7790010', 'sku' => 'SKU-010', 'provider_code' => 'PC-1010', 'prov' => 'A',    'cost' => 1000, 'stock' => 100],
        'A11' => ['name' => 'Art nombre repetido',     'bar_code' => '7790011', 'sku' => 'SKU-011', 'provider_code' => 'PC-1111', 'prov' => 'A',    'cost' => 1100, 'stock' => 110],

        /* provider_code CON valor pero provider_id NULL: ArticleIndexCache::build() exige que los
           dos estén presentes para indexar, así que este artículo es INVISIBLE al escalón
           provider_code. Está a propósito para fijar ese comportamiento (ver DuplicadosProviderCodeTest). */
        'A12' => ['name' => 'Art sin proveedor',       'bar_code' => '7790012', 'sku' => 'SKU-012', 'provider_code' => 'PC-1200', 'prov' => null,   'cost' => 1200, 'stock' => 120],

        /* sku DUPLICADO en base: el escalón sku tampoco tiene bandera, siempre ambiguo. */
        'A13' => ['name' => 'Art sku repetido 1',      'bar_code' => '7790013', 'sku' => 'SKU-DUP', 'provider_code' => 'PC-1300', 'prov' => 'A',    'cost' => 1300, 'stock' => 130],
        'A14' => ['name' => 'Art sku repetido 2',      'bar_code' => '7790014', 'sku' => 'SKU-DUP', 'provider_code' => 'PC-1400', 'prov' => 'A',    'cost' => 1400, 'stock' => 140],

        /* provider_code que existe en UN SOLO proveedor distinto al de la importación:
           es el caso limpio de bloqueo / de actualización cruzada. */
        'A15' => ['name' => 'Art solo prov C',         'bar_code' => '7790015', 'sku' => 'SKU-015', 'provider_code' => 'PC-1500', 'prov' => 'C',    'cost' => 1500, 'stock' => 150],
    ];

    /**
     * Crea proveedores y artículos para el tenant de prueba.
     *
     * @param  int $user_id
     * @return array ['providers' => ['A' => Provider, ...], 'articulos' => ['A1' => Article, ...]]
     */
    public static function sembrar($user_id)
    {
        $providers = [
            'A' => self::crear_provider($user_id, 'Proveedor A Test'),
            'B' => self::crear_provider($user_id, 'Proveedor B Test'),
            'C' => self::crear_provider($user_id, 'Proveedor C Test'),
        ];

        $articulos = [];

        foreach (self::$definiciones as $clave => $definicion) {

            $provider_id = is_null($definicion['prov'])
                            ? null
                            : $providers[$definicion['prov']]->id;

            $articulos[$clave] = self::crear_article([
                'user_id'       => $user_id,
                'name'          => $definicion['name'],
                'bar_code'      => $definicion['bar_code'],
                'sku'           => $definicion['sku'],
                'provider_code' => $definicion['provider_code'],
                'provider_id'   => $provider_id,
                'cost'          => $definicion['cost'],
                'stock'         => $definicion['stock'],
                'iva_id'        => 2,
                'status'        => 'active',
                'online'        => 1,
            ]);
        }

        return [
            'providers' => $providers,
            'articulos' => $articulos,
        ];
    }

    /**
     * @param  int    $user_id
     * @param  string $name
     * @return \App\Models\Provider
     */
    protected static function crear_provider($user_id, $name)
    {
        $provider = new Provider();

        $provider->name    = $name;
        $provider->user_id = $user_id;
        $provider->status  = 'active';

        $provider->save();

        return $provider;
    }

    /**
     * Se asigna propiedad por propiedad en vez de Article::create() para no depender
     * de $fillable: si un campo no estuviera en fillable, create() lo descartaria en
     * silencio y el escenario quedaria mal sembrado sin que ningun test lo note.
     *
     * @param  array $props
     * @return \App\Models\Article
     */
    protected static function crear_article(array $props)
    {
        $article = new Article();

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        self::derivar_precio($article, $props['user_id']);

        return $article;
    }

    /**
     * Deriva final_price, costo_real y price de un artículo ya guardado por la
     * misma vía que usa la importación real (ActualizarBBDD::set_precios_finales()
     * llama a este mismo helper). El baseline de los fixtures tiene que ser un
     * estado que el sistema pueda haber producido -- no un valor arbitrario -- para
     * que el rollback (que restaura la propiedad tocada y deja que las derivadas se
     * recalculen) tenga algo consistente contra qué compararse.
     *
     * @param  \App\Models\Article $article
     * @param  int                 $user_id
     * @return \App\Models\Article
     */
    public static function derivar_precio($article, $user_id)
    {
        return ArticleHelper::setFinalPrice($article, $user_id);
    }

    /**
     * Definiciones del escenario, para que los tests puedan asertar contra los
     * valores originales sin repetirlos a mano.
     *
     * @return array
     */
    public static function definiciones()
    {
        return self::$definiciones;
    }
}
