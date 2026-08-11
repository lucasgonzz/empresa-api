<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indices en los tres pivotes de venta que el grupo 050 (junio 2026) NO cubrio.
 *
 * ANTES DE AGREGAR UN belongsToMany NUEVO AL MODELO Sale: la tabla pivote necesita indice en
 * sale_id, siempre. SaleController::update() hace detach() sobre TODOS los pivotes de la venta,
 * y un DELETE ... WHERE sale_id = X sin indice hace table scan y, bajo REPEATABLE READ, bloquea
 * las filas escaneadas -- no solo las que matchean. Resultado: dos ventas distintas se bloquean
 * entre si y una muere con "1205 Lock wait timeout exceeded" a los 50 segundos.
 *
 * El grupo 050 indexo las cuatro tablas de detachItems() y dio el bug por cerrado. Las otras tres
 * (discount_sale, sale_surchage, current_acount_payment_method_sale) siguieron sin indice y
 * volvieron a tumbar dos actualizaciones de venta en Fenix el 7/8/2026 -- la misma familia de
 * error, otra tabla, dos meses despues.
 *
 * La deteccion de la familia esta automatizada: el test de la suite esquema-ventas recorre los
 * belongsToMany del modelo Sale y falla si alguno no tiene indice en sale_id.
 */
class AddIndexesToRemainingSalePivotTables extends Migration
{
    /**
     * Nombre del indice de current_acount_payment_method_sale.current_acount_payment_method_id.
     *
     * Los otros cinco indices de esta migracion usan el default de Laravel (<tabla>_<columna>_index),
     * igual que la migracion del grupo 050. Este no puede: el default daria 72 caracteres y MySQL
     * corta los identificadores en 64 ("1059 Identifier name ... is too long").
     */
    const INDICE_CAPM_ID = 'capm_sale_capm_id_index';

    /**
     * Agrega indice en sale_id y en la FK opuesta de cada una de las tres tablas pivote.
     *
     * Cada index() va guardado: hay ~40 bases de clientes en estados distintos y en alguna el
     * indice pudo haberse agregado a mano. Sin la guarda, el primer "Duplicate key name" deja la
     * migracion a medias y sin fila en `migrations`.
     *
     * @return void
     */
    public function up()
    {
        // Pivot descuentos ↔ ventas: el DELETE de attachDiscounts() es el que tumbo a Fenix.
        Schema::table('discount_sale', function (Blueprint $table) {
            if (!$this->ya_tiene_indice('discount_sale', 'sale_id')) {
                $table->index('sale_id');
            }
            if (!$this->ya_tiene_indice('discount_sale', 'discount_id')) {
                $table->index('discount_id');
            }
        });

        // Pivot recargos ↔ ventas.
        Schema::table('sale_surchage', function (Blueprint $table) {
            if (!$this->ya_tiene_indice('sale_surchage', 'sale_id')) {
                $table->index('sale_id');
            }
            if (!$this->ya_tiene_indice('sale_surchage', 'surchage_id')) {
                $table->index('surchage_id');
            }
        });

        // Pivot metodos de pago de cuenta corriente ↔ ventas.
        Schema::table('current_acount_payment_method_sale', function (Blueprint $table) {
            if (!$this->ya_tiene_indice('current_acount_payment_method_sale', 'sale_id')) {
                $table->index('sale_id');
            }
            if (!$this->ya_tiene_indice('current_acount_payment_method_sale', 'current_acount_payment_method_id')) {
                // Nombre explicito, unica excepcion al default de Laravel en esta migracion: el
                // default seria 'current_acount_payment_method_sale_current_acount_payment_method_id_index',
                // 72 caracteres, y MySQL corta los identificadores en 64 (error 1059).
                $table->index('current_acount_payment_method_id', self::INDICE_CAPM_ID);
            }
        });
    }

    /**
     * Elimina los indices agregados en up(), con la misma guarda para no explotar si no estaban.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('discount_sale', function (Blueprint $table) {
            if ($this->ya_tiene_indice('discount_sale', 'sale_id')) {
                $table->dropIndex(['sale_id']);
            }
            if ($this->ya_tiene_indice('discount_sale', 'discount_id')) {
                $table->dropIndex(['discount_id']);
            }
        });

        Schema::table('sale_surchage', function (Blueprint $table) {
            if ($this->ya_tiene_indice('sale_surchage', 'sale_id')) {
                $table->dropIndex(['sale_id']);
            }
            if ($this->ya_tiene_indice('sale_surchage', 'surchage_id')) {
                $table->dropIndex(['surchage_id']);
            }
        });

        Schema::table('current_acount_payment_method_sale', function (Blueprint $table) {
            if ($this->ya_tiene_indice('current_acount_payment_method_sale', 'sale_id')) {
                $table->dropIndex(['sale_id']);
            }
            if ($this->ya_tiene_indice('current_acount_payment_method_sale', 'current_acount_payment_method_id')) {
                $table->dropIndex(self::INDICE_CAPM_ID);
            }
        });
    }

    /**
     * Devuelve true si la tabla ya tiene un indice cuya primera columna es la que se le pasa.
     * Se usa SHOW INDEX en vez de Schema::hasIndex() porque ese metodo no existe en la version de
     * Laravel del proyecto, y en vez de doctrine/dbal porque no queremos depender de que este
     * instalado en cada cliente.
     *
     * Seq_in_index = 1 es a proposito: un indice compuesto que empiece por esa columna ya sirve
     * para el WHERE sale_id = X, asi que tampoco hay que duplicarlo.
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return bool
     */
    private function ya_tiene_indice($tabla, $columna)
    {
        $indices = DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Column_name = ? AND Seq_in_index = 1', [$columna]);

        return count($indices) >= 1;
    }
}
