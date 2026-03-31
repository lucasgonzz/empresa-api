<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddListasDePrecioToUsersTable extends Migration
{
    /**
     * Agrega flag de empresa para listas de precio (reemplaza lógica solo por extensión).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('listas_de_precio')->default(0)->after('cotizar_precios_en_dolares');
        });

        $slug = 'articulo_margen_de_ganancia_segun_lista_de_precios';

        // Usuarios que ya tenían la extensión quedan con el flag activo.
        DB::statement(
            'UPDATE users u
            INNER JOIN extencion_empresa_user eu ON eu.user_id = u.id
            INNER JOIN extencion_empresas e ON e.id = eu.extencion_empresa_id AND e.slug = ?
            SET u.listas_de_precio = 1',
            [$slug]
        );
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('listas_de_precio');
        });
    }
}
