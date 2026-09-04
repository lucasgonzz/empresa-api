<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la preferencia `aplicar_descuentos_proveedor_al_asignar` a users.
 *
 * Es la preferencia del COMERCIO (vive en el usuario dueño) sobre que pasa con los descuentos del
 * proveedor cuando se le asigna un proveedor a un articulo:
 *
 *   apagada (default) -> no pasa nada. Los descuentos del proveedor entran recien al armar la
 *                        COMPRA (provider_order_discounts, precargados desde provider_discounts).
 *                        Es el comportamiento de develop desde el prompt 261.
 *   prendida          -> al crear un articulo con proveedor, o al asignarle uno a un articulo que
 *                        no tenia, se le materializan los `provider_discounts` de ese proveedor
 *                        como `article_discounts` tagueados. Es la dinamica anterior al merge de
 *                        refractor, que varios usuarios siguen esperando: poner el proveedor y que
 *                        el articulo quede con los descuentos de ese proveedor.
 *
 * Default 0 y NOT NULL a proposito: los ~40 comercios que no pidieron nada tienen que seguir
 * costeando exactamente igual que ayer, sin depender de que nadie toque nada. Prender esta
 * preferencia mueve el `costo_real` y el `final_price` de los articulos que se creen de ahi en
 * adelante, y eso solo puede pasar en el comercio que lo pidio.
 *
 * NO es retroactiva: prenderla no toca ningun articulo ya existente.
 *
 * La base es compartida con `tienda`. Esta migracion es aditiva y compatible hacia atras en las dos
 * direcciones: `tienda-api` no lee esta columna y sigue andando sin ella, y `empresa-api` con la
 * columna puesta no cambia nada de lo que `tienda` ve mientras la preferencia este apagada. Nada se
 * renombra ni se saca.
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos y una que ya tenga
 * la columna (parche a mano) no puede tumbar la migracion.
 */
class AddAplicarDescuentosProveedorAlAsignarToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('users', 'aplicar_descuentos_proveedor_al_asignar')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('aplicar_descuentos_proveedor_al_asignar')->default(0)->nullable(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('users', 'aplicar_descuentos_proveedor_al_asignar')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('aplicar_descuentos_proveedor_al_asignar');
        });
    }
}
