<?php

namespace Tests\Feature\Sales;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mision "indices por sale_id que faltan en el guardado de venta" (4/9/2026).
 *
 * Cubre la migracion 2026_09_04_120000_add_sale_id_indexes_to_venta_tables, que agrega el indice por
 * `sale_id` a las tres tablas que el guardado de venta consulta por esa columna y que no lo tenian:
 * article_purchases, afip_tickets y movimiento_cajas.
 *
 * Medido en la produccion de lamartina2 antes del arreglo: 1,35s / 3,78s / 0,20s por consulta, con
 * `EXPLAIN` dando `type: ALL` y `rows: 881944` sobre article_purchases. El guardado de venta promedio
 * paso de 2,17s a 0,56s despues de crear los tres.
 *
 * Se prueba contra MySQL real (empresa_testing_s<N>): la migracion y sus guardas leen
 * information_schema.STATISTICS, que no existe en sqlite. Con otro driver se saltea con
 * markTestSkipped(), mismo patron que 15_Indices_De_Venta_Y_Vender_Test.php.
 */
class Indices_Sale_Id_Venta_Test extends TestCase
{
    use DatabaseTransactions;

    /** Las 3 tablas que toca la migracion, con el nombre de indice que les corresponde. */
    const INDICES_ESPERADOS = [
        'article_purchases' => 'article_purchases_sale_id_idx',
        'afip_tickets'      => 'afip_tickets_sale_id_idx',
        'movimiento_cajas'  => 'movimiento_cajas_sale_id_idx',
    ];

    /**
     * La migracion vive en el namespace global (como el resto de las migraciones del repo): alcanza
     * con requerirla una vez por proceso de phpunit.
     *
     * @return void
     */
    private function requerir_migracion()
    {
        $archivo_migracion = base_path('database/migrations/2026_09_04_120000_add_sale_id_indexes_to_venta_tables.php');

        $this->assertFileExists($archivo_migracion);

        if (!class_exists('AddSaleIdIndexesToVentaTables', false)) {
            require_once $archivo_migracion;
        }
    }

    /**
     * SHOW INDEX / information_schema son de MySQL. Contra cualquier otro motor el test no aplica.
     *
     * @return void
     */
    private function saltar_si_no_es_mysql()
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->markTestSkipped('Estos tests leen el esquema con information_schema, que es de MySQL. Driver actual: ' . $driver . '.');
        }
    }

    /**
     * Foto del esquema de indices de las 3 tablas: nombre + posicion + columna, en un solo array
     * comparable con assertEquals. Sirve para probar que una segunda corrida de up() no cambia nada.
     *
     * @return array
     */
    private function foto_de_los_indices()
    {
        $foto = [];

        foreach (array_keys(self::INDICES_ESPERADOS) as $tabla) {

            $filas = DB::select(
                'SELECT INDEX_NAME as indice, SEQ_IN_INDEX as seq, COLUMN_NAME as columna
                   FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                  ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [$tabla]
            );

            $foto[$tabla] = array_map(function ($fila) {
                return $fila->indice . ':' . $fila->seq . ':' . $fila->columna;
            }, $filas);
        }

        return $foto;
    }

    /**
     * Columnas del indice, en orden (SEQ_IN_INDEX). Vacio si el indice no existe.
     *
     * @param  string $tabla
     * @param  string $nombre
     * @return array
     */
    private function columnas_del_indice($tabla, $nombre)
    {
        $filas = DB::select(
            'SELECT COLUMN_NAME as columna
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            [$tabla, $nombre]
        );

        return array_map(function ($fila) {
            return $fila->columna;
        }, $filas);
    }

    /**
     * Correr up() dos veces seguidas no puede tirar excepcion, y la segunda corrida no puede crear ni
     * modificar ningun indice: reproduce el escenario de lamartina2, donde los tres ya existen
     * creados a mano el 4/9/2026 y la migracion tiene que pasar en verde sin hacer nada.
     *
     * @group sales
     * @group esquema-ventas
     * @test
     */
    public function la_migracion_es_idempotente_corriendola_dos_veces()
    {
        $this->saltar_si_no_es_mysql();
        $this->requerir_migracion();

        $migracion = new \AddSaleIdIndexesToVentaTables();

        // Primera corrida: en el slot la migracion real ya corrio (el runner migra antes de la
        // suite), asi que esto en si mismo prueba que up() tolera encontrar todo puesto.
        $migracion->up();

        $antes = $this->foto_de_los_indices();

        // Segunda corrida: no puede cambiar nada.
        $migracion->up();

        $despues = $this->foto_de_los_indices();

        $this->assertEquals(
            $antes,
            $despues,
            'Correr up() dos veces cambio el esquema de indices; la guarda de idempotencia no esta funcionando.'
        );
    }

    /**
     * Los tres indices existen y son sobre la columna `sale_id`, exactamente esa y una sola.
     *
     * Que exista un indice CON EL NOMBRE correcto pero sobre otra columna seria peor que no tenerlo:
     * la guarda por nombre de up() lo daria por bueno para siempre y el full scan seguiria.
     *
     * @group sales
     * @group esquema-ventas
     * @test
     */
    public function los_tres_indices_estan_sobre_la_columna_sale_id()
    {
        $this->saltar_si_no_es_mysql();
        $this->requerir_migracion();

        (new \AddSaleIdIndexesToVentaTables())->up();

        foreach (self::INDICES_ESPERADOS as $tabla => $nombre) {

            $columnas = $this->columnas_del_indice($tabla, $nombre);

            $this->assertEquals(
                ['sale_id'],
                $columnas,
                'El indice ' . $nombre . ' de ' . $tabla . ' no esta sobre (sale_id). Encontrado: '
                    . '[' . implode(', ', $columnas) . '].'
            );
        }
    }

    /**
     * La guarda (b) tiene que reconocer un indice que YA cubre `sale_id` bajo otro nombre y no crear
     * uno redundante al lado.
     *
     * Se prueba de verdad: se crea a mano un indice con el nombre default de Laravel sobre una tabla
     * de las tres, se borra el que puso la migracion, se corre up() y se verifica que NO lo recree.
     * Al final se deja el esquema como estaba. Se usa movimiento_cajas porque es la mas chica de las
     * tres y no tiene FK sobre sale_id que complique el drop.
     *
     * @group sales
     * @group esquema-ventas
     * @test
     */
    public function no_crea_un_indice_redundante_si_sale_id_ya_esta_cubierto_con_otro_nombre()
    {
        $this->saltar_si_no_es_mysql();
        $this->requerir_migracion();

        $tabla    = 'movimiento_cajas';
        $nuestro  = self::INDICES_ESPERADOS[$tabla];
        $ajeno    = 'movimiento_cajas_sale_id_index'; // el nombre que derivaria Laravel

        (new \AddSaleIdIndexesToVentaTables())->up();

        // Dejamos el escenario del cliente que ya tiene sale_id indexado, pero con el otro nombre.
        DB::statement('ALTER TABLE `' . $tabla . '` DROP INDEX `' . $nuestro . '`');
        DB::statement('ALTER TABLE `' . $tabla . '` ADD INDEX `' . $ajeno . '` (`sale_id`)');

        try {
            (new \AddSaleIdIndexesToVentaTables())->up();

            $this->assertEmpty(
                $this->columnas_del_indice($tabla, $nuestro),
                'La migracion creo ' . $nuestro . ' aunque ' . $ajeno . ' ya cubria (sale_id): '
                    . 'la guarda por prefijo de columnas no esta funcionando y el cliente termina con dos '
                    . 'indices identicos.'
            );

        } finally {
            // Volvemos el esquema a como estaba, pase lo que pase con la asercion: DatabaseTransactions
            // no revierte DDL en MySQL (el ALTER hace commit implicito).
            DB::statement('ALTER TABLE `' . $tabla . '` DROP INDEX `' . $ajeno . '`');

            if (empty($this->columnas_del_indice($tabla, $nuestro))) {
                DB::statement('ALTER TABLE `' . $tabla . '` ADD INDEX `' . $nuestro . '` (`sale_id`)');
            }
        }
    }
}
