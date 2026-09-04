<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Indices por `sale_id` que le faltan al guardado de venta (medicion en produccion, lamartina2, 4/9/2026).
 *
 * Lucas reporto que guardar una venta tardaba ~5 segundos. El desglose del laravel.log sobre 2.246
 * ventas dio dos tramos dominantes dentro del guardado: `inicio -> attachProperies` (0,87s promedio)
 * y `-> set_costo_y_price` (1,08s promedio). La causa es la misma en los dos: TRES tablas que el
 * guardado consulta por `sale_id` no tienen indice por esa columna, asi que cada consulta es un
 * full scan.
 *
 * Medido a mano contra la base de lamartina2, antes de crear los indices:
 *
 *   afip_tickets       65.350 filas / 290 MB  -> 3,78 s por consulta
 *   article_purchases 883.534 filas /  80 MB  -> 1,35 s por consulta
 *   movimiento_cajas  237.944 filas /  20 MB  -> 0,20 s por consulta
 *
 * `EXPLAIN SELECT * FROM article_purchases WHERE sale_id = ?` daba `type: ALL`, `key: NULL`,
 * `rows: 881944`. Con los tres indices puestos, las tres consultas bajaron a ~0,01 s y el promedio
 * de guardado de venta paso de 2,17 s a 0,56 s (medido con trafico real, 183 ventas contra 9).
 *
 * `afip_tickets` es la peor de las tres y no por cantidad de filas sino por PESO: tiene 65 mil filas
 * pero pesa 290 MB (4,5 KB por fila), asi que escanearla entera cuesta casi 4 segundos. Y es de las
 * consultas mas frecuentes del sistema: en un muestreo de 5 minutos del processlist,
 * `select * from afip_tickets where sale_id in (N)` aparecio 67 veces.
 *
 * ---
 *
 * POR QUE FALTAN JUSTO ESTAS TRES. El repo viene indexando `sale_id` tabla por tabla, y estas se
 * fueron quedando afuera de cada tanda:
 *
 *   2026_06_19_120000 -> article_sale, combo_sale, promocion_vinoteca_sale, sale_service
 *   2026_08_08_120000 -> discount_sale, sale_surchage, current_acount_payment_method_sale
 *   2026_08_14_120400 -> article_purchases, pero por (address_id, article_id, created_at) y (created_at)
 *   2026_09_01_180000 -> article_purchases, pero por article_id
 *
 * Ninguna toca `sale_id` en article_purchases, afip_tickets ni movimiento_cajas. Las dos migraciones
 * que SI tocaron article_purchases lo hicieron por otras columnas, que es lo que vuelve facil pasarlo
 * por alto: la tabla "ya tiene indices", solo que ninguno sirve para el filtro por venta.
 *
 * ---
 *
 * IDEMPOTENCIA: guarda doble, igual que 2026_09_01_180000, y cada mitad hace un trabajo distinto.
 *
 *   (a) por NOMBRE EXACTO -> evita "1061 Duplicate key name" en lamartina2, donde los tres ya existen
 *                            creados a mano el 4/9/2026 con estos mismos nombres.
 *   (b) por PREFIJO DE COLUMNAS EN ORDEN -> evita crear un indice REDUNDANTE en un cliente que ya
 *                            tenga `sale_id` indexado bajo el nombre default de Laravel
 *                            (`<tabla>_sale_id_index`), como podria pasar si alguna migracion futura
 *                            o un DBA lo agrega por su cuenta.
 *
 * La guarda (b) compara la secuencia 1..n columna por columna y EN ORDEN, no solo la primera, por el
 * mismo motivo que documenta 2026_09_01_180000: preguntar por Seq_in_index = 1 a secas es el modo de
 * falla que 2026_08_19_120000 dejo escrito. Aca los tres indices son de UNA sola columna, asi que las
 * dos formas darian igual, pero se mantiene la estricta para que el patron no se degrade cuando
 * alguien copie este archivo como molde para un indice compuesto.
 *
 * SUB_PART IS NULL en la guarda (b): un indice de prefijo arranca por la columna pero no cubre lo
 * mismo, y no puede contar como cubierto.
 *
 * down() es un NO-OP a proposito (ver su propio docblock).
 *
 * ---
 *
 * ALGORITHM=INPLACE, LOCK=NONE EXPLICITO, con caida a Schema::table()->index() si el motor devuelve
 * 1846. Mismo criterio y mismo motivo que 2026_09_01_180000: el default no es un contrato, y en estas
 * tablas de un cliente con volumen la diferencia entre LOCK=NONE y un lock de escritura es que se
 * pueda o no vender mientras corre la migracion.
 *
 * NOTA DE OPERACION: en lamartina2 los tres indices se crearon en 1,0s / 2,8s / 1,4s con el comercio
 * vendiendo, sin cortar el servicio. Son tablas mucho mas chicas que `sales`, asi que el costo es
 * bajo; aun asi, para un comercio muy grande vale la recomendacion de siempre de correr fuera del
 * horario de atencion.
 */
class AddSaleIdIndexesToVentaTables extends Migration
{
    /**
     * tabla => [ nombre del indice => columnas en orden ].
     *
     * Los nombres son explicitos y no derivados de Laravel: son los que ya existen a mano en
     * lamartina2 desde el 4/9/2026, y por eso la guarda (a) los reconoce ahi en vez de duplicarlos.
     */
    const INDICES = [
        'article_purchases' => [
            'article_purchases_sale_id_idx' => ['sale_id'],
        ],
        'afip_tickets' => [
            'afip_tickets_sale_id_idx' => ['sale_id'],
        ],
        'movimiento_cajas' => [
            'movimiento_cajas_sale_id_idx' => ['sale_id'],
        ],
    ];

    /**
     * Crea los indices que falten. Cada uno pasa por cuatro guardas antes de crearse: la tabla
     * existe, las columnas existen, no hay un indice con ese nombre, y no hay ya un indice que cubra
     * ese prefijo de columnas. Si alguna no se cumple, ese indice puntual se saltea (con log si es un
     * caso raro de esquema) y la migracion sigue con el resto.
     *
     * @return void
     */
    public function up()
    {
        foreach (self::INDICES as $tabla => $indices) {

            if (!Schema::hasTable($tabla)) {
                Log::warning('AddSaleIdIndexesToVentaTables: la tabla no existe, se saltea', ['tabla' => $tabla]);
                continue;
            }

            foreach ($indices as $nombre => $columnas) {

                if (!$this->columnas_existen($tabla, $columnas)) {
                    Log::warning('AddSaleIdIndexesToVentaTables: falta alguna columna, se saltea el indice', [
                        'tabla'    => $tabla,
                        'indice'   => $nombre,
                        'columnas' => $columnas,
                    ]);
                    continue;
                }

                // Guarda (a): ya existe con ESE nombre (lamartina2, creado a mano el 4/9/2026).
                if ($this->ya_existe_el_indice($tabla, $nombre)) {
                    continue;
                }

                // Guarda (b): ya existe OTRO indice que cubre ese prefijo de columnas en orden.
                if ($this->ya_hay_indice_que_cubre($tabla, $columnas)) {
                    continue;
                }

                $this->crear_indice($tabla, $nombre, $columnas);
            }
        }
    }

    /**
     * NO DROPEA NADA. Es un no-op documentado, a proposito.
     *
     * up() no puede distinguir "este indice lo cree yo" de "este indice ya estaba, con este mismo
     * nombre, antes de correr la migracion" -- que es exactamente el caso de lamartina2, donde los
     * tres se crearon a mano el 4/9/2026 para destrabar el guardado de venta ese mismo dia. Un down()
     * que dropea por nombre exacto borraria ahi el arreglo puesto a mano y devolveria el guardado a
     * los ~5 segundos, que es justo lo que esta migracion existe para sacar del sistema.
     *
     * Es el mismo criterio que 2026_09_01_180000::down(), y por el mismo motivo. down() no es un
     * camino que este release use (no hay rollback de esta migracion en produccion), asi que el costo
     * de dejarlo quieto es bajo. Si alguna vez hace falta revertir esto en un cliente puntual, se hace
     * a mano, mirando primero si ese indice ya estaba ANTES de esta migracion o lo creo ella.
     *
     * @return void
     */
    public function down()
    {
        Log::warning('AddSaleIdIndexesToVentaTables::down() es un no-op a proposito: no se puede '
            . 'distinguir un indice creado por up() de uno que ya existia con ese nombre (caso '
            . 'lamartina2). Revertir esta migracion, si hace falta, se hace a mano por cliente.');
    }

    /**
     * Emite el ALTER TABLE con ALGORITHM=INPLACE, LOCK=NONE explicito. Si el motor de ese cliente no
     * lo soporta (error 1846), el ALTER falla entero sin crear nada -- es atomico -- y se cae a
     * Schema::table()->index(), que deja que MySQL elija el algoritmo como hace el resto del repo.
     *
     * @param  string $tabla
     * @param  string $nombre
     * @param  array  $columnas
     * @return void
     */
    private function crear_indice($tabla, $nombre, $columnas)
    {
        $sql = 'ALTER TABLE `' . $tabla . '` '
             . 'ADD INDEX `' . $nombre . '` (`' . implode('`, `', $columnas) . '`), '
             . 'ALGORITHM=INPLACE, LOCK=NONE';

        try {
            DB::statement($sql);
            return;
        } catch (\Throwable $e) {
            Log::warning('AddSaleIdIndexesToVentaTables: ALGORITHM=INPLACE, LOCK=NONE no se pudo usar, se cae a Schema::table()', [
                'tabla'  => $tabla,
                'indice' => $nombre,
                'error'  => $e->getMessage(),
            ]);
        }

        Schema::table($tabla, function (Blueprint $table) use ($nombre, $columnas) {
            $table->index($columnas, $nombre);
        });
    }

    /**
     * Devuelve true si TODAS las columnas pedidas existen en la tabla. Un cliente con el esquema
     * atrasado puede no tener alguna, y crear el indice ahi cortaria la migracion entera.
     *
     * @param  string $tabla
     * @param  array  $columnas
     * @return bool
     */
    private function columnas_existen($tabla, $columnas)
    {
        foreach ($columnas as $columna) {
            if (!Schema::hasColumn($tabla, $columna)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guarda (a). Devuelve true si la tabla tiene un indice con ESE NOMBRE.
     *
     * Se consulta information_schema y no `SHOW INDEX FROM` porque acepta bind parametrizado limpio
     * para el nombre de tabla; y no Schema::hasIndex() porque ese metodo no existe en la version de
     * Laravel del proyecto, ni doctrine/dbal porque no queremos depender de que este instalado en
     * cada cliente. Mismo criterio que 2026_09_01_180000 y 2026_08_19_120000.
     *
     * @param  string $tabla
     * @param  string $nombre
     * @return bool
     */
    private function ya_existe_el_indice($tabla, $nombre)
    {
        $filas = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$tabla, $nombre]
        );

        return count($filas) >= 1;
    }

    /**
     * Guarda (b). Devuelve true si YA existe algun indice de la tabla cuyas primeras columnas son,
     * en orden y una por una, las que se quieren indexar.
     *
     * Los tres indices de esta migracion son de una sola columna, asi que alcanzaria con mirar la
     * primera; se mantiene la comparacion estricta en orden para que el patron siga siendo correcto
     * si alguien copia este archivo como molde para un indice compuesto.
     *
     * SUB_PART IS NULL: un indice de prefijo sobre varchar arranca por la columna pero no cubre lo
     * mismo, asi que no puede contar como cubierto.
     *
     * @param  string $tabla
     * @param  array  $columnas
     * @return bool
     */
    private function ya_hay_indice_que_cubre($tabla, $columnas)
    {
        $filas = DB::select(
            'SELECT INDEX_NAME as indice, SEQ_IN_INDEX as seq, COLUMN_NAME as columna
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND SUB_PART IS NULL
              ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$tabla]
        );

        $por_indice = [];

        foreach ($filas as $fila) {
            $por_indice[$fila->indice][(int) $fila->seq] = $fila->columna;
        }

        foreach ($por_indice as $columnas_del_indice) {

            $cubre = true;

            foreach ($columnas as $posicion => $columna) {

                $seq = $posicion + 1;

                if (!isset($columnas_del_indice[$seq]) || $columnas_del_indice[$seq] !== $columna) {
                    $cubre = false;
                    break;
                }
            }

            if ($cubre) {
                return true;
            }
        }

        return false;
    }
}
