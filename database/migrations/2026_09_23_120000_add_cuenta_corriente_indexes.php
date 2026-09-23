<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de la cuenta corriente (misión cuenta-corriente-carrera-y-velocidad, 23/9/2026).
 *
 * `current_acounts` nació en 2021 sin un solo índice secundario, y `pagado_por` en 2023 igual. Los
 * que hay en algunas bases viejas (client_id, seller_id, sale_id, commissioner_id) los dejaron
 * foreign keys de un esquema anterior; una base creada con las migraciones de hoy no tiene ninguno.
 * Medido en Fenix el 23/9/2026: `getSaldo()` hacía un full scan de 165.494 filas por llamada (187 ms),
 * `checkSaldos()` la llamaba una vez por movimiento, y una edición de venta tardaba ~45 s. Esa
 * ventana es la que dejó entrar la carrera de la venta 54160.
 *
 * 🔴 NO ES EL ÍNDICE DE UNA CONSULTA, ES EL DE LA FAMILIA (APRENDER_NO_PARCHEAR: "arreglar las
 * instancias que el stack trace nombró, y no la familia"). Se listaron las consultas de todos los
 * caminos de cuenta corriente —saldos, imputación, alta y baja de ventas y pagos, notas de crédito,
 * compras, el listado, comisiones y puntos— y se corrió `EXPLAIN` de cada una sobre una base con
 * 150.000 movimientos. Todo lo que daba `type=ALL` está acá:
 *
 *   current_acounts
 *   - (credit_account_id, is_provisorio, created_at, id)  cc_cuenta_orden_idx
 *       getSaldo(), checkSaldos(), checkPagos(), CurrentAcountPagoHelper, el listado, la baja de
 *       comisiones y la reconciliación de puntos. Las cuatro columnas son el filtro y el orden
 *       exactos de getSaldo()/checkSaldos(): la cadena de saldos se lee en el orden del índice, sin
 *       filesort.
 *   - sale_id            cc_sale_id_idx            el movimiento de una venta (alta, edición, baja,
 *                                                  nota de crédito, puntos).
 *   - to_pay_id          cc_to_pay_id_idx          los pagos dirigidos a un débito que se borra.
 *   - provider_order_id  cc_provider_order_id_idx  el movimiento de una compra.
 *   - (user_id, status, created_at)  cc_user_status_idx  el número de recibo de cada pago y nota de
 *                                                  crédito (`getNumReceipt()`).
 *   pagado_por
 *   - debe_id            pagado_por_debe_id_idx    las imputaciones de un débito (checkPagos las
 *                                                  borra por acá, el listado las carga por acá).
 *   - haber_id           pagado_por_haber_id_idx   las imputaciones de un pago.
 *   credit_accounts
 *   - (model_name, model_id, moneda_id)  credit_accounts_duenio_idx  la cuenta de un cliente o
 *                                                  proveedor en una moneda: la busca cada alta,
 *                                                  edición y baja de venta, cada devolución y cada
 *                                                  compra. Tabla chica (dos filas por cliente), pero
 *                                                  con miles de clientes también era un full scan.
 *
 * IDEMPOTENTE frente a bases viejas: por cada índice se mira que las columnas existan y que no haya
 * ya un índice que EMPIECE por las mismas columnas (con cualquier nombre: las bases con esquema 1.x o
 * 3.x tienen `current_acounts_sale_id_foreign` y similares). Un `ADD INDEX` repetido tira "Duplicate
 * key name" y dejaría el upgrade a medias.
 *
 * Nota de operación (igual que 2026_09_16_120000): en MySQL 8 y MariaDB crear un índice secundario
 * es INPLACE y no bloquea escrituras, pero en una tabla grande tarda y consume I/O.
 */
class AddCuentaCorrienteIndexes extends Migration
{
    /**
     * Tabla => [nombre del índice => columnas, en orden].
     *
     * @return array<string,array<string,array<int,string>>>
     */
    private function indices()
    {
        return [
            'current_acounts' => [
                'cc_cuenta_orden_idx'       => ['credit_account_id', 'is_provisorio', 'created_at', 'id'],
                'cc_sale_id_idx'            => ['sale_id'],
                'cc_to_pay_id_idx'          => ['to_pay_id'],
                'cc_provider_order_id_idx'  => ['provider_order_id'],
                'cc_user_status_idx'        => ['user_id', 'status', 'created_at'],
            ],
            'pagado_por' => [
                'pagado_por_debe_id_idx'    => ['debe_id'],
                'pagado_por_haber_id_idx'   => ['haber_id'],
            ],
            'credit_accounts' => [
                'credit_accounts_duenio_idx' => ['model_name', 'model_id', 'moneda_id'],
            ],
        ];
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->indices() as $tabla => $indices) {

            if (!Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($indices as $nombre => $columnas) {
                $this->crear_indice($tabla, $nombre, $columnas);
            }
        }
    }

    /**
     * Reverse the migrations. Solo borra los índices que creó ESTA migración (por nombre): un índice
     * equivalente que ya estaba con otro nombre no es suyo.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->indices() as $tabla => $indices) {

            if (!Schema::hasTable($tabla)) {
                continue;
            }

            foreach (array_keys($indices) as $nombre) {

                if (!$this->existe_el_indice($tabla, $nombre)) {
                    continue;
                }

                Schema::table($tabla, function (Blueprint $table) use ($nombre) {
                    $table->dropIndex($nombre);
                });
            }
        }
    }

    /**
     * Crea el índice si las columnas existen y si no hay ya uno que empiece por las mismas columnas.
     *
     * @param  string             $tabla
     * @param  string             $nombre
     * @param  array<int,string>  $columnas
     * @return void
     */
    private function crear_indice($tabla, $nombre, $columnas)
    {
        foreach ($columnas as $columna) {

            if (!Schema::hasColumn($tabla, $columna)) {
                return;
            }
        }

        if ($this->existe_el_indice($tabla, $nombre) || $this->hay_un_indice_equivalente($tabla, $columnas)) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) use ($nombre, $columnas) {
            $table->index($columnas, $nombre);
        });
    }

    /**
     * ¿Existe un índice con ese nombre?
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @return bool
     */
    private function existe_el_indice($tabla, $nombre)
    {
        return count(DB::select('SHOW INDEX FROM `'.$tabla.'` WHERE Key_name = ?', [$nombre])) > 0;
    }

    /**
     * ¿Hay algún índice (con cualquier nombre) cuyas primeras columnas sean exactamente estas, en
     * este orden? Uno así ya sirve para las mismas consultas.
     *
     * @param  string             $tabla
     * @param  array<int,string>  $columnas
     * @return bool
     */
    private function hay_un_indice_equivalente($tabla, $columnas)
    {
        $por_indice = [];

        foreach (DB::select('SHOW INDEX FROM `'.$tabla.'`') as $fila) {
            $por_indice[$fila->Key_name][(int) $fila->Seq_in_index] = $fila->Column_name;
        }

        foreach ($por_indice as $columnas_del_indice) {

            ksort($columnas_del_indice);

            $prefijo = array_slice(array_values($columnas_del_indice), 0, count($columnas));

            if ($prefijo === array_values($columnas)) {
                return true;
            }
        }

        return false;
    }
}
