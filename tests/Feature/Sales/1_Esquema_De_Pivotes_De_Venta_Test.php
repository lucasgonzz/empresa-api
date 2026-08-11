<?php

namespace Tests\Feature\Sales;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deteccion de la clase de bug "pivote de venta sin indice en sale_id".
 *
 * El grupo 050 (junio 2026) indexo las cuatro tablas de detachItems() y dio el lock timeout por
 * cerrado. Las otras tres (discount_sale, sale_surchage, current_acount_payment_method_sale)
 * siguieron sin indice y volvieron a tumbar dos actualizaciones de venta de Fenix el 7/8/2026.
 * Lo que faltaba era la deteccion, y por eso este test NO chequea una lista fija de tablas:
 * recorre los belongsToMany del modelo Sale en tiempo de ejecucion y le exige indice a cada
 * pivote que descubre. El dia que alguien le agregue una relacion nueva a Sale sin indexar su
 * pivote, esto se pone rojo solo, sin que nadie se tenga que acordar de actualizarlo.
 */
class Esquema_De_Pivotes_De_Venta_Test extends TestCase
{

    /**
     * Minimo de pivotes que el barrido tiene que descubrir.
     *
     * Al 8/8/2026 el modelo Sale declara 7 belongsToMany. Si manana agrega uno, este numero se
     * puede subir; lo que no se puede es bajarlo para "arreglar" un barrido que dejo de encontrar
     * cosas, porque ese es justamente el modo de falla que esta guardia detecta.
     */
    const MINIMO_PIVOTES = 7;

    /**
     * Las tres tablas que motivaron el grupo 375. No pueden desaparecer del barrido sin que
     * alguien se entere.
     */
    public $tablas_del_grupo_375 = [
        'discount_sale',
        'sale_surchage',
        'current_acount_payment_method_sale',
    ];

    /**
     * Todo pivote de venta tiene indice en su columna hacia la venta.
     *
     * @group esquema-ventas
     * @test
     */
    public function todo_pivote_de_venta_tiene_indice_en_sale_id()
    {
        $this->saltar_si_no_es_mysql();

        $pivotes = $this->descubrir_pivotes_de_venta();

        foreach ($pivotes as $pivote) {

            $tabla   = $pivote['tabla'];
            $columna = $pivote['columna'];

            $indices = DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Column_name = ? AND Seq_in_index = 1', [$columna]);

            $this->assertGreaterThanOrEqual(1, count($indices),
                'La tabla pivote ' . $tabla . ' no tiene indice en ' . $columna . '. '
                . 'Sin indice, el DELETE ... WHERE ' . $columna . ' = X de SaleController::update() hace table scan '
                . 'y bloquea filas de OTRAS ventas bajo REPEATABLE READ: dos ventas distintas se traban entre si '
                . 'y una muere con 1205 Lock wait timeout. Agregar el indice en una migracion nueva.');
        }
    }

    /**
     * Guardia del barrido: si la reflexion se rompe, el test de arriba pasa recorriendo cero
     * tablas y queda verde para siempre sin probar nada. Ese es el modo de falla mas peligroso
     * de un test de este tipo, asi que se chequea aparte.
     *
     * @group esquema-ventas
     * @test
     */
    public function el_barrido_descubre_todos_los_pivotes_de_venta()
    {
        $this->saltar_si_no_es_mysql();

        $pivotes = $this->descubrir_pivotes_de_venta();

        $tablas = [];

        foreach ($pivotes as $pivote) {
            $tablas[] = $pivote['tabla'];
        }

        $this->assertGreaterThanOrEqual(self::MINIMO_PIVOTES, count($tablas),
            'El barrido por reflexion descubrio solo ' . count($tablas) . ' pivote(s) de venta '
            . '(' . implode(', ', $tablas) . ') y se esperaban al menos ' . self::MINIMO_PIVOTES . '. '
            . 'Con el barrido roto, el test de indices queda verde sin recorrer nada. '
            . 'Revisar descubrir_pivotes_de_venta(): un rename, un cambio de version de Laravel o un '
            . 'catch que se traga todo lo dejan devolviendo la lista vacia.');

        foreach ($this->tablas_del_grupo_375 as $tabla) {

            $this->assertContains($tabla, $tablas,
                'La tabla pivote ' . $tabla . ' desaparecio del barrido. Es una de las tres que '
                . 'motivaron el grupo 375 (lock timeout de Fenix del 7/8/2026), asi que si ya no se '
                . 'descubre, el test de indices dejo de cubrir justo lo que vino a cubrir.');
        }
    }

    /**
     * Recorre los metodos publicos del modelo Sale y se queda con los que devuelven un
     * BelongsToMany, sacando de la propia relacion el nombre del pivote y la columna que apunta
     * a la venta. No se arma ningun nombre a mano ni se hardcodea la lista: el valor del test es
     * que descubra relaciones nuevas solo.
     *
     * @return array  lista de ['tabla' => string, 'columna' => string]
     */
    private function descubrir_pivotes_de_venta()
    {
        $sale       = new \App\Models\Sale();
        $reflection = new \ReflectionClass($sale);

        $pivotes = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {

            // Solo metodos propios del modelo y sin parametros: un metodo de relacion no recibe argumentos.
            if ($method->class !== \App\Models\Sale::class || $method->getNumberOfParameters() > 0) {
                continue;
            }

            // No todo metodo publico sin parametros es una relacion: hay accessors, scopes y
            // helpers que pueden explotar al invocarse. Que uno cualquiera reviente no puede
            // tumbar el barrido.
            try {
                $relacion = $sale->{$method->getName()}();
            } catch (\Throwable $e) {
                continue;
            }

            if (!($relacion instanceof BelongsToMany)) {
                continue;
            }

            $pivotes[] = [
                'tabla'   => $relacion->getTable(),
                'columna' => $relacion->getForeignPivotKeyName(),
            ];
        }

        return $pivotes;
    }

    /**
     * SHOW INDEX es de MySQL. Contra cualquier otro motor el test no aplica.
     *
     * @return void
     */
    private function saltar_si_no_es_mysql()
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->markTestSkipped('Estos tests leen el esquema con SHOW INDEX, que es de MySQL. Driver actual: ' . $driver . '.');
        }
    }
}
