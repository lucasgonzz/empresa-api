<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la preferencia `fechar_ventas_por_fecha_de_entrega` a users.
 *
 * Es la preferencia del COMERCIO (vive en el usuario dueño) sobre por que fecha se fechan las
 * ventas en el listado y en los reportes:
 *
 *   apagada (default) -> por `sales.created_at`, cuando se cargo la fila. Es lo de siempre y es lo
 *                        correcto para quien carga la venta en el momento.
 *   prendida          -> por la fecha del pedido (`sales.fecha_entrega`, con COALESCE a created_at
 *                        para las ventas que no la tienen cargada). Es lo que necesita una
 *                        distribuidora que anota los pedidos en papel y los carga en tandas: sin
 *                        esto, cargar en agosto 156 pedidos de julio le mezcla los dos meses en el
 *                        reporte, sin error y con un numero plausible.
 *
 * Default 0 y NOT NULL a proposito: los ~40 comercios que no pidieron nada tienen que seguir
 * midiendo exactamente igual que ayer, sin depender de que nadie toque nada.
 *
 * La base es compartida con `tienda`. Esta migracion es aditiva y compatible hacia atras en las dos
 * direcciones: `tienda-api` no lee esta columna y sigue andando sin ella, y `empresa-api` con la
 * columna puesta no cambia nada de lo que `tienda` ve mientras la preferencia este apagada. Nada se
 * renombra ni se saca.
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos y una que ya tenga
 * la columna (parche a mano) no puede tumbar la migracion.
 */
class AddFecharVentasPorFechaDeEntregaToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('users', 'fechar_ventas_por_fecha_de_entrega')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('fechar_ventas_por_fecha_de_entrega')->default(0)->nullable(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('users', 'fechar_ventas_por_fecha_de_entrega')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fechar_ventas_por_fecha_de_entrega');
        });
    }
}
