<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\combo\ComboCalculadoEsquemaHelper;
use App\Models\Article;
use App\Models\Combo;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Base común de los tests de los combos calculados (misión combos-calculados, 30/9/2026).
 *
 * Arma lo que los seis archivos de esta carpeta necesitan y no conviene repetir: artículos con
 * costo y precio CONOCIDOS (para poder decir cuánto tiene que dar el combo sin depender de la
 * fórmula de precios de la cuenta), combos con sus componentes, listas de precio con su pivote
 * escrito a mano, y lecturas de la fila tal como quedó en la base (nunca el modelo en memoria, que
 * puede estar viejo: `ComboCalculadoHelper::guardar()` NO actualiza el modelo que recibe).
 *
 * 🔴 Los precios de los artículos se ESCRIBEN (`final_price` y el pivote de cada lista), no se
 * calculan con `setFinalPrice()`. Es a propósito: estos tests miden la cuenta del combo, no la del
 * artículo, y atar la aserción a la fórmula de precios haría que cualquier cambio de esa fórmula
 * (que tiene sus propios tests) ponga rojos a estos por un motivo que no es suyo. Los tests que sí
 * necesitan pasar por `setFinalPrice()` (el gancho) comparan contra lo que el artículo QUEDÓ
 * teniendo, no contra un número calculado a mano.
 *
 * Todo lo que crea lleva el prefijo `zz` y corre adentro de la transacción de `EmpresaTestCase`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class ComboCalculadoTestCase extends EmpresaTestCase
{
    /** Dueño del fixture de testing (el que actúa en cada test). */
    const DUENO = 500;

    /** @var int Contador para los `num` de los combos y los nombres. */
    protected static $secuencia = 0;

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        /* El memo de la guarda es estático: un test que lo deje en false contamina a los demás. */
        ComboCalculadoEsquemaHelper::olvidar();

        $this->dueno = User::find(self::DUENO);

        if (is_null($this->dueno)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
    }

    protected function tearDown(): void
    {
        ComboCalculadoEsquemaHelper::olvidar();

        parent::tearDown();
    }

    /**
     * Prende o apaga las listas de precio de la cuenta y devuelve el dueño recargado (el helper
     * de listas lee el flag del modelo que le pasan).
     *
     * @param  int  $usa  1 o 0
     * @return \App\Models\User
     */
    protected function con_listas($usa)
    {
        $this->dueno->listas_de_precio = $usa;
        $this->dueno->save();

        $this->dueno = User::find(self::DUENO);

        return $this->dueno;
    }

    /**
     * Una lista de precios nueva del dueño. Las de position alta ganan como "por defecto"; el
     * fixture trae cuatro listas (position 1 a 4), así que las de estos tests arrancan en 90.
     *
     * @param  string  $nombre
     * @param  int     $position
     * @return \App\Models\PriceType
     */
    protected function lista($nombre, $position)
    {
        return PriceType::create([
            'name'     => 'zz ' . $nombre . ' ' . (++self::$secuencia),
            'user_id'  => self::DUENO,
            'position' => $position,
        ]);
    }

    /**
     * Un artículo del dueño con costo y precio conocidos.
     *
     * @param  array  $atributos  Pisan los defaults: `costo_real`, `final_price`, `stock`,
     *                            `unidades_individuales`, `cost_in_dollars`, `provider_id`...
     * @return \App\Models\Article
     */
    protected function nuevo_articulo(array $atributos = [])
    {
        return Article::create(array_merge([
            'name'        => 'zz Componente de combo ' . (++self::$secuencia) . ' ' . uniqid(),
            'user_id'     => self::DUENO,
            'status'      => 'active',
            'costo_real'  => 100,
            'final_price' => 250,
            'stock'       => 50,
        ], $atributos));
    }

    /**
     * Le escribe al artículo su precio en una lista (el pivote `article_price_type`).
     *
     * @param  \App\Models\Article    $articulo
     * @param  \App\Models\PriceType  $lista
     * @param  float                  $precio
     * @return void
     */
    protected function precio_en_lista($articulo, $lista, $precio)
    {
        $articulo->price_types()->attach($lista->id, ['final_price' => $precio]);
    }

    /**
     * Un combo del dueño con sus componentes.
     *
     * @param  array  $componentes  Lista de [articulo, cantidad].
     * @param  array  $atributos    Pisan los defaults (`calcular_desde_articulos`, `descuento_tipo`,
     *                              `descuento_valor`, `cost`, `price`, `online`...).
     * @return \App\Models\Combo
     */
    protected function combo(array $componentes, array $atributos = [])
    {
        $combo = Combo::create(array_merge([
            'num'     => 970000 + (++self::$secuencia),
            'name'    => 'zz Combo calculado ' . self::$secuencia . ' ' . uniqid(),
            'user_id' => self::DUENO,
        ], $atributos));

        foreach ($componentes as $par) {
            $combo->articles()->attach($par[0]->id, ['amount' => $par[1]]);
        }

        return $combo;
    }

    /**
     * Un combo que se calcula solo (el interruptor prendido), con sus componentes.
     *
     * @param  array  $componentes
     * @param  array  $atributos
     * @return \App\Models\Combo
     */
    protected function combo_calculado(array $componentes, array $atributos = [])
    {
        return $this->combo($componentes, array_merge(['calcular_desde_articulos' => 1], $atributos));
    }

    /**
     * La fila del combo tal como está en la base ahora (no el modelo en memoria).
     *
     * @param  \App\Models\Combo|int  $combo
     * @return object
     */
    protected function fila($combo)
    {
        $id = is_object($combo) ? $combo->id : $combo;

        return DB::table('combos')->where('id', $id)->first();
    }

    /**
     * El costo del combo en la base, como float.
     *
     * @param  \App\Models\Combo|int  $combo
     * @return float|null
     */
    protected function costo_en_base($combo)
    {
        $fila = $this->fila($combo);

        return is_null($fila->cost) ? null : (float) $fila->cost;
    }

    /**
     * El precio del combo en la base, como float.
     *
     * @param  \App\Models\Combo|int  $combo
     * @return float|null
     */
    protected function precio_en_base($combo)
    {
        $fila = $this->fila($combo);

        return is_null($fila->price) ? null : (float) $fila->price;
    }

    /**
     * Los precios por lista del combo en la base: [price_type_id => float].
     *
     * @param  \App\Models\Combo|int  $combo
     * @return array
     */
    protected function precios_por_lista_en_base($combo)
    {
        $id = is_object($combo) ? $combo->id : $combo;

        $precios = [];

        foreach (DB::table('combo_price_type')->where('combo_id', $id)->get() as $fila) {
            $precios[(int) $fila->price_type_id] = (float) $fila->price;
        }

        return $precios;
    }

    /**
     * Escribe el precio/costo de un artículo por consulta directa, SIN pasar por ningún gancho:
     * es justamente la escritura "cruda" que ningún disparador ve.
     *
     * @param  \App\Models\Article|int  $articulo
     * @param  array                    $columnas
     * @return void
     */
    protected function escribir_crudo($articulo, array $columnas)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        DB::table('articles')->where('id', $id)->update($columnas);
    }
}
