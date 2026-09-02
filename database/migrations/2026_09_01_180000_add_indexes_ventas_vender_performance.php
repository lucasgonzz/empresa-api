<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Indices del esquema de ventas y de escaneo en Vender (medicion en produccion, lamartina2, 1/9/2026).
 *
 * En una instancia migrada a VPS con buffer pool chico, guardar una venta tardaba 20-36 segundos y
 * escanear un articulo por codigo de barras 12-17 segundos. Con estos indices puestos a mano bajo a
 * 1-2 segundos y 0,05 segundos respectivamente. El esquema le falta desde siempre a TODOS los
 * clientes; en el hosting compartido viejo quedaba tapado por el cache. Esta migracion lo lleva a
 * todos.
 *
 * Las tres consultas que motivaron esto:
 *
 *   1. Controller::num('sales') -> WHERE user_id = ? ORDER BY num DESC LIMIT 1 FOR UPDATE.
 *      Sin (user_id, num) hay filesort sobre todas las ventas del comercio, y encima con el lock
 *      pesimista tomado mientras dura. Lo cubre `sales_user_id_num_idx`.
 *
 *   2. SaleController::venta_ya_cread() -> WHERE user_id = ? AND client_id = ? AND employee_id = ?
 *      AND total = ? AND created_at >= NOW() - INTERVAL 5 SECOND ORDER BY created_at DESC.
 *      Lo cubre `sales_user_id_created_at_idx`.
 *
 *      ESTE INDICE NO ES SOLO PERFORMANCE: venta_ya_cread() es la guarda anti-duplicados de ventas.
 *      Medida el 1/9/2026 tarda 13 segundos con una ventana de 5 segundos, o sea que termina de
 *      mirar cuando la venta anterior YA quedo fuera de su propia ventana, y no la ve. Ese es el
 *      motivo de las ventas duplicadas del cliente. Con el indice la consulta vuelve a milisegundos
 *      y la ventana de 5s vuelve a significar 5s. SI ALGUIEN SACA ESTE INDICE, VUELVEN LOS
 *      DUPLICADOS.
 *
 *   3. VenderController::search_bar_code() -> el peso estaba en el eager loading de
 *      `sales_with_deliveries_in_acopio` (join article_sale/sales filtrando en_acopio), que se
 *      resuelve aparte con el scope `withAllSinAcopio` de Article. Lo que queda del lado del
 *      esquema es `article_sale (article_id, delivered_amount)`, que es por donde entra esa relacion
 *      en el resto del sistema (Listado, que la sigue usando).
 *
 * ---
 *
 * QUE INDICES CREA DE VERDAD. De los 8 nombres declarados abajo, en una base al dia con develop
 * (medido contra empresa_testing_s10 el 1/9/2026) solo 5 se crean; los otros 3 ya existen bajo el
 * nombre default de Laravel y la guarda (b) los saltea:
 *
 *   se crean : sales_user_id_num_idx, sales_user_id_created_at_idx,
 *              article_sale_article_delivered_idx, article_purchases_article_id_idx,
 *              articles_bar_code_idx
 *   ya estan : article_sale_sale_id_idx     (= article_sale_sale_id_index,    migracion 050)
 *              article_sale_article_id_idx  (= article_sale_article_id_index, migracion 050)
 *              caps_sale_id_idx             (= current_acount_payment_method_sale_sale_id_index, 375)
 *
 * Los nombres son los que Lucas creo A MANO en lamartina2, tal cual, a proposito: en esa base los 8
 * ya existen con estos nombres exactos y la guarda (a) los reconoce y los saltea. Si esta migracion
 * inventara nombres propios, lamartina2 terminaria con 16 indices en vez de 8.
 *
 * `articles_bar_code_idx` merece una aclaracion, porque parece redundante y no lo es:
 * VenderController::search_bar_code() filtra user_id Y bar_code (las dos por igualdad) y ya lo
 * resuelve `articles_user_bar_code_index (user_id, bar_code)` de la migracion 2026_07_30_150000.
 * El consumidor real de este indice es OTRO: ConsultoraDePrecioController::buscador()
 * (routes/api.php:166) hace Article::where('bar_code', $codigo) SIN user_id, y hoy eso es un full
 * scan de `articles` (inventarios de hasta 500.000 filas por comercio). NO SACAR ESTE INDICE
 * PENSANDO QUE LO CUBRE EL COMPUESTO: no lo cubre, bar_code no es columna lider de ninguno.
 *
 * ---
 *
 * IDEMPOTENCIA: guarda doble, y cada mitad hace un trabajo distinto.
 *
 *   (a) por NOMBRE EXACTO -> evita "1061 Duplicate key name" en lamartina2, donde los 8 ya existen
 *                            a mano con estos mismos nombres.
 *   (b) por PREFIJO DE COLUMNAS EN ORDEN -> evita crear un indice REDUNDANTE en los ~40 clientes que
 *                            ya vinieron con las migraciones 050 / 375 / 07-30 y tienen la misma
 *                            columna bajo el nombre default de Laravel.
 *
 * La guarda (b) compara la secuencia 1..n columna por columna y EN ORDEN, no solo la primera. Es la
 * diferencia con 2026_08_08_120000_add_indexes_to_remaining_sale_pivot_tables (grupo 375), que
 * pregunta por Seq_in_index = 1, y NO es un capricho: `sales_user_id_foreign` ya arranca por
 * `user_id`, asi que con aquella guarda `sales_user_id_num_idx` y `sales_user_id_created_at_idx` NO
 * SE CREARIAN NUNCA y la migracion pasaria en verde sin hacer nada. Es exactamente el modo de falla
 * (a) que documenta 2026_08_19_120000_add_indexes_to_address_article_tables. Lo mismo con
 * `article_sale_article_id_index`, que arranca por article_id pero no cubre el compuesto
 * (article_id, delivered_amount).
 *
 * SUB_PART IS NULL en la guarda (b): un indice de prefijo sobre varchar (INDEX bar_code(20))
 * arranca por la columna pero no cubre lo mismo, y no puede contar como cubierto.
 *
 * down() usa SOLO la guarda (a): dropea unicamente lo que up() pudo haber creado, por nombre exacto.
 * Nunca toca `article_sale_sale_id_index` ni ningun indice ajeno; ese es el modo de falla (c) de
 * 2026_08_19_120000 ("un down() que destruye en vez de revertir").
 *
 * ---
 *
 * ALGORITHM=INPLACE, LOCK=NONE EXPLICITO. Ninguna migracion del repo lo emite hoy: las cinco que lo
 * mencionan (2026_07_23_120100, 2026_07_30_150000, 2026_08_14_120400, 2026_08_15_140000,
 * 2026_08_15_140200) lo hacen EN COMENTARIOS, confiando en el default de MySQL 5.7/8. Aca va
 * explicito porque el default no es un contrato, y en la tabla `sales` de un cliente con volumen la
 * diferencia entre LOCK=NONE y un lock de escritura de varios minutos es que se pueda o no vender
 * mientras corre la migracion.
 *
 * No va a pelo: si el motor de algun cliente no soporta el inplace sin lock, MySQL falla duro con
 * "1846 ALGORITHM=INPLACE is not supported" y esa migracion quedaria a medias y sin fila en
 * `migrations`, justo lo que las guardas vienen a evitar. Como el ALTER es atomico (si falla no creo
 * nada), se atrapa, se loguea el motivo y se cae a Schema::table()->index(), que es lo que hace hoy
 * el resto del repo. Asi el 99% de los clientes se lleva la garantia explicita y el 1% raro se lleva
 * el indice igual, con un warning, en vez de una migracion rota.
 *
 * NOTA DE OPERACION (igual que 2026_07_30_150000 y 2026_08_14_120400): aunque con LOCK=NONE no
 * bloquea escrituras, sobre sales / article_sale / articles de un comercio grande la creacion puede
 * tardar varios minutos y consumir I/O. Correr fuera del horario de atencion del comercio.
 */
class AddIndexesVentasVenderPerformance extends Migration
{
    /**
     * tabla => [ nombre del indice => columnas en orden ].
     *
     * Los nombres son explicitos y no derivados de Laravel, por dos motivos: son los que ya existen a
     * mano en lamartina2 (y por eso la guarda por nombre los reconoce), y un down() que confia en el
     * nombre derivado deja de encontrar lo que el up() creo si el derivador cambia.
     */
    const INDICES = [
        'sales' => [
            'sales_user_id_num_idx'        => ['user_id', 'num'],
            'sales_user_id_created_at_idx' => ['user_id', 'created_at'],
        ],
        'article_sale' => [
            'article_sale_sale_id_idx'           => ['sale_id'],
            'article_sale_article_id_idx'        => ['article_id'],
            'article_sale_article_delivered_idx' => ['article_id', 'delivered_amount'],
        ],
        'article_purchases' => [
            'article_purchases_article_id_idx' => ['article_id'],
        ],
        'articles' => [
            'articles_bar_code_idx' => ['bar_code'],
        ],
        'current_acount_payment_method_sale' => [
            'caps_sale_id_idx' => ['sale_id'],
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
                Log::warning('AddIndexesVentasVenderPerformance: la tabla no existe, se saltea', ['tabla' => $tabla]);
                continue;
            }

            foreach ($indices as $nombre => $columnas) {

                if (!$this->columnas_existen($tabla, $columnas)) {
                    Log::warning('AddIndexesVentasVenderPerformance: falta alguna columna, se saltea el indice', [
                        'tabla'    => $tabla,
                        'indice'   => $nombre,
                        'columnas' => $columnas,
                    ]);
                    continue;
                }

                // Guarda (a): ya existe con ESE nombre (lamartina2, creado a mano).
                if ($this->ya_existe_el_indice($tabla, $nombre)) {
                    continue;
                }

                // Guarda (b): ya existe OTRO indice que cubre ese prefijo de columnas en orden
                // (clientes al dia con las migraciones 050 / 375 / 07-30). Crearlo seria redundante.
                if ($this->ya_hay_indice_que_cubre($tabla, $columnas)) {
                    continue;
                }

                $this->crear_indice($tabla, $nombre, $columnas);
            }
        }
    }

    /**
     * Dropea UNICAMENTE los indices que up() puede haber creado, y solo si existen con ese nombre
     * exacto. Nunca toca los indices de nombre default de las migraciones 050 / 375 / 07-30.
     *
     * Cada drop va en su propio try/catch: en `sales`, `user_id` tiene FK hacia `users`
     * (`sales_user_id_foreign`). Medido el 1/9/2026 contra empresa_testing_s10: al crear
     * `sales_user_id_num_idx` (arranca igual por `user_id`), InnoDB deja de necesitar el indice de
     * soporte auto-generado de la FK y lo remueve -- confirmado con SHOW INDEX (desaparece) e
     * information_schema.KEY_COLUMN_USAGE (la constraint sigue viva). Con las dos columnas nuevas
     * puestas, cualquiera de las dos sostiene la FK sola, pero NINGUNA de las dos se puede dropear si
     * es la ULTIMA que le queda a la columna: MySQL corta con "1553 Cannot drop index ... needed in a
     * foreign key constraint". Sin este catch, el down() de sales aborta ahi mismo y nunca llega a
     * article_sale / article_purchases / articles / current_acount_payment_method_sale -- que no
     * tienen este problema porque ninguna de sus columnas indexadas por esta migracion sostiene una FK
     * en soledad. Se loguea y se sigue: down() no es un camino que este release use (no hay rollback en
     * produccion), y dejar un indice de mas puesto es un resultado aceptable frente a abortar a mitad.
     *
     * @return void
     */
    public function down()
    {
        foreach (self::INDICES as $tabla => $indices) {

            if (!Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($indices as $nombre => $columnas) {

                if (!$this->ya_existe_el_indice($tabla, $nombre)) {
                    continue;
                }

                try {
                    $this->dropear_indice($tabla, $nombre);
                } catch (\Throwable $e) {
                    Log::warning('AddIndexesVentasVenderPerformance: no se pudo dropear el indice (probablemente sostiene una FK en soledad), se lo deja puesto', [
                        'tabla'  => $tabla,
                        'indice' => $nombre,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }
        }
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
            Log::warning('AddIndexesVentasVenderPerformance: ALGORITHM=INPLACE, LOCK=NONE no se pudo usar, se cae a Schema::table()', [
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
     * Contraparte de crear_indice(): DROP con el mismo ALGORITHM/LOCK explicito y la misma caida.
     *
     * @param  string $tabla
     * @param  string $nombre
     * @return void
     */
    private function dropear_indice($tabla, $nombre)
    {
        $sql = 'ALTER TABLE `' . $tabla . '` DROP INDEX `' . $nombre . '`, ALGORITHM=INPLACE, LOCK=NONE';

        try {
            DB::statement($sql);
            return;
        } catch (\Throwable $e) {
            Log::warning('AddIndexesVentasVenderPerformance: el DROP con ALGORITHM=INPLACE, LOCK=NONE fallo, se cae a Schema::table()', [
                'tabla'  => $tabla,
                'indice' => $nombre,
                'error'  => $e->getMessage(),
            ]);
        }

        Schema::table($tabla, function (Blueprint $table) use ($nombre) {
            $table->dropIndex($nombre);
        });
    }

    /**
     * Guarda (a). Devuelve true si la tabla tiene un indice con ESE NOMBRE.
     *
     * Se consulta information_schema y no `SHOW INDEX FROM` porque acepta bind parametrizado limpio
     * para el nombre de tabla; y no Schema::hasIndex() porque ese metodo no existe en la version de
     * Laravel del proyecto, ni doctrine/dbal porque no queremos depender de que este instalado en
     * cada cliente. Mismo criterio que 2026_08_19_120000.
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
     * No alcanza con mirar la primera columna: `sales_user_id_foreign` arranca por user_id pero es de
     * una sola columna y no sirve para el ORDER BY num; y `article_sale_article_id_index` arranca por
     * article_id pero no cubre el compuesto (article_id, delivered_amount).
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

    /**
     * Verifica que todas las columnas del indice existan en la tabla. Hay ~40 bases de clientes en
     * estados de esquema distintos; que a una le falte una columna no puede tumbar la migracion para
     * las otras 39. Mismo criterio que 2026_07_30_150000::columna_es_la_esperada().
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
}
