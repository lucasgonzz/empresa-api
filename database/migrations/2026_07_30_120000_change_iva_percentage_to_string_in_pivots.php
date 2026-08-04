<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige el tipo de `iva_percentage` en los pivots `article_sale` y
 * `article_current_acount` (grupo 275, prompt 01): las migraciones
 * `2026_06_11_000001` y `2026_06_11_000002` las crearon `decimal(8,2)`, pero su fuente
 * (`ivas.percentage`) es `string` y guarda a propósito tanto alícuotas numéricas
 * ('21', '10.5') como etiquetas fiscales ('Exento', 'No Gravado'). Cualquier venta que
 * incluyera un artículo Exento/No Gravado hacía que MySQL rechazara el INSERT del pivot
 * (o, en bases sin sql_mode estricto, coercionara el valor a 0.00 en silencio).
 *
 * La columna del pivot pasa a espejar el tipo de su fuente: varchar. No se normaliza a
 * número ni se guarda NULL para los no numéricos -- Exento, No Gravado y 0% son tres
 * alícuotas distintas ante ARCA (colapsarlas pierde información irrecuperable), y esta
 * columna existe justamente para que una nota de crédito use la alícuota de la factura
 * original aunque el artículo haya cambiado de IVA después (NULL anularía ese propósito).
 * Los consumidores (AfipItemCalculator, SaleHelper::get_price_sin_iva) ya comparan contra
 * los strings 'Exento'/'No Gravado'; el único eslabón que no toleraba texto era la columna.
 */
class ChangeIvaPercentageToStringInPivots extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // ALTER crudo, no $table->string(...)->change(): el ->change() de Laravel pasa por
        // Doctrine DBAL, que reconstruye la definición completa de la columna a partir de lo
        // que se le declare y se come lo que no se repita. En una migración que corre sin
        // supervisión contra las bases de los 40 y pico de clientes, un ALTER de una línea
        // que dice exactamente lo que hace es preferible a delegar la generación del DDL.
        // VARCHAR(20) alcanza y sobra: el valor más largo del seeder es 'No Gravado' (10).
        DB::statement("ALTER TABLE `article_sale` MODIFY `iva_percentage` VARCHAR(20) NULL");
        DB::statement("ALTER TABLE `article_current_acount` MODIFY `iva_percentage` VARCHAR(20) NULL");

        /**
         * Los IVA no numéricos ('Exento', 'No Gravado', y cualquier etiqueta que haya cargado
         * el cliente) pudieron haberse guardado como 0.00 en las bases sin sql_mode estricto.
         * Se recuperan a partir del IVA actual del artículo, que es la única fuente que queda:
         * el valor original se perdió en la coerción. Solo se tocan filas en exactamente 0.00
         * de artículos cuyo IVA hoy es no numérico.
         */
        $ivas = DB::table('ivas')->select('id', 'percentage')->get();

        foreach ($ivas as $iva) {
            if (is_numeric($iva->percentage)) {
                continue;
            }

            DB::statement(
                "UPDATE `article_sale` AS `pivote`
                 INNER JOIN `articles` ON `articles`.`id` = `pivote`.`article_id`
                 SET `pivote`.`iva_percentage` = ?
                 WHERE `articles`.`iva_id` = ?
                   AND `pivote`.`iva_percentage` = '0.00'",
                [$iva->percentage, $iva->id]
            );

            DB::statement(
                "UPDATE `article_current_acount` AS `pivote`
                 INNER JOIN `articles` ON `articles`.`id` = `pivote`.`article_id`
                 SET `pivote`.`iva_percentage` = ?
                 WHERE `articles`.`iva_id` = ?
                   AND `pivote`.`iva_percentage` = '0.00'",
                [$iva->percentage, $iva->id]
            );
        }
    }

    /**
     * Reverse the migrations.
     *
     * ⚠️ Salida de emergencia, no operación rutinaria: la vuelta atrás PIERDE información. Los
     * valores no numéricos (Exento, No Gravado, etc.) no tienen representación en decimal(8,2),
     * así que se pasan a NULL antes de volver al tipo anterior.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("UPDATE `article_sale` SET `iva_percentage` = NULL WHERE `iva_percentage` IS NOT NULL AND `iva_percentage` NOT REGEXP '^[0-9]+(\\.[0-9]+)?$'");
        DB::statement("UPDATE `article_current_acount` SET `iva_percentage` = NULL WHERE `iva_percentage` IS NOT NULL AND `iva_percentage` NOT REGEXP '^[0-9]+(\\.[0-9]+)?$'");

        DB::statement("ALTER TABLE `article_sale` MODIFY `iva_percentage` DECIMAL(8,2) NULL");
        DB::statement("ALTER TABLE `article_current_acount` MODIFY `iva_percentage` DECIMAL(8,2) NULL");
    }
}
