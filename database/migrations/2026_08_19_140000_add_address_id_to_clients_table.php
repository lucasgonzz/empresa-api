<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asegura que `clients.address_id` exista antes de que el hotfix la use (19/8/2026).
 *
 * 🔴 ESTA MIGRACION NO EXISTE EN develop Y NO ES UN OLVIDO: es la reparacion de un agujero que
 * develop tambien tiene, hecha aca porque aca se necesita ya.
 *
 * La columna `clients.address_id` se agrego el 12/11/2024 (commit f485a3a2) EDITANDO A MANO la
 * migracion `2019_10_24_003025_create_clients_table.php`, en vez de con un ALTER nuevo. Editar una
 * migracion ya corrida no le agrega la columna a ninguna base existente: solo se la da a las
 * instalaciones que se crean de cero desde ese dia. Verificado el 19/8/2026: en todo el repo NO hay
 * ninguna otra migracion que agregue esta columna.
 *
 * O sea que una base instalada antes de 11/2024 --que es el caso de cualquier cliente con varios
 * anios de antiguedad-- no tiene la columna, y todo lo que el hotfix trae para "cliente por
 * sucursal" revienta con un SQL error:
 *
 *   - el campo "Sucursal" del ABM de clientes (guarda address_id),
 *   - el filtro de clientes por sucursal en VENDER (SearchController::searchFromModal),
 *   - la importacion de clientes con columna sucursal (ClientImport),
 *   - y el logo del RECIBO DE PAGO, que resuelve la sucursal via Client::address().
 *
 * La guarda `hasColumn` la vuelve idempotente: en una base que ya la tiene --por haberse instalado
 * despues de 11/2024-- no hace nada y no falla.
 *
 * Se declara `nullable` y sin foreign key a proposito, exactamente como quedo en la migracion
 * CREATE editada: agregar una FK aca fallaria en cualquier base que tenga filas con un address_id
 * viejo apuntando a una sucursal borrada.
 *
 * CLASE DE ERROR: "editar una migracion ya corrida en vez de escribir un ALTER". Deteccion en el
 * resto del repo -- columnas que estan en una migracion CREATE vieja pero no en la base de un
 * cliente antiguo:
 *
 *   git log --all --format='%h %ad %s' --date=short -S"<columna>" -- database/migrations/<create_...>.php
 *
 * Si devuelve un commit MUY POSTERIOR a la fecha del nombre del archivo, esa columna tiene el mismo
 * problema. Queda anotado en el informe.
 */
class AddAddressIdToClientsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('clients', 'address_id')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->integer('address_id')->nullable();
        });
    }

    /**
     * No se dropea la columna.
     *
     * Un down() que la borre destruiria la asignacion de sucursal de todos los clientes, y ademas no
     * podria distinguir el caso en que la columna ya existia antes de esta migracion (que es el
     * caso mas comun: cualquier base instalada despues de 11/2024). Revertir el hotfix no tiene por
     * que costar datos del cliente.
     *
     * @return void
     */
    public function down()
    {
        // Intencionalmente vacio. Ver el docblock.
    }
}
