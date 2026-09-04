<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `editado_a_mano` a article_discounts: marca que una persona le cambio el porcentaje a un
 * descuento que habia puesto el sistema copiandolo del proveedor.
 *
 * 🔴 Para que existe. Al propagar a los articulos un cambio en los descuentos de un proveedor hay
 * que distinguir dos situaciones que se ven identicas mirando solo los numeros:
 *
 *   - el articulo esta DESACTUALIZADO: tiene la copia vieja y corresponde actualizarlo;
 *   - alguien le puso A PROPOSITO otro porcentaje para ESE articulo puntual, porque lo negocio
 *     distinto. Pisarlo sin preguntar le borra una decision comercial.
 *
 * La primera version de esta mision intentaba deducirlo comparando el porcentaje del articulo
 * contra el valor actual y el anterior del proveedor. **No alcanza, y lo encontro un test en rojo**:
 * cuando se BORRA un descuento del proveedor, su porcentaje desaparece de las dos listas, y todos
 * los articulos que lo tenian copiado quedaban clasificados como editados a mano. Lo mismo pasaba
 * con cualquier valor viejo de mas de un cambio de antiguedad.
 *
 * La marca no deduce: la pone `ArticleDiscountController::update()` en el momento exacto en que una
 * persona edita el descuento, que es cuando el dato existe con certeza.
 *
 * Default 0 y NOT NULL: los descuentos que ya existen no estan marcados, asi que una propagacion
 * los va a tratar como actualizables. Es lo correcto para los que puso el sistema (la enorme
 * mayoria) y es el unico comportamiento posible para los viejos, porque de ellos no hay registro de
 * quien los toco.
 *
 * La base es compartida con `tienda`, que lee `article_discounts` pero no esta columna. Migracion
 * aditiva, compatible hacia atras en las dos direcciones; nada se renombra ni se saca.
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos.
 */
class AddEditadoAManoToArticleDiscountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('article_discounts', 'editado_a_mano')) {
            return;
        }

        Schema::table('article_discounts', function (Blueprint $table) {
            $table->boolean('editado_a_mano')->default(0)->nullable(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('article_discounts', 'editado_a_mano')) {
            return;
        }

        Schema::table('article_discounts', function (Blueprint $table) {
            $table->dropColumn('editado_a_mano');
        });
    }
}
