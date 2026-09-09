<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice sobre messages.buyer_id (misión actualizar-sin-el-vps, 9/9/2026).
 *
 * `messages` nació en 2021 con `buyer_id` sin índice, y todo lo que lee la conversación de un
 * comprador (`MessageController::fromBuyer`, los agregados de `GET api/buyer`) filtra por esa
 * columna. En Fenix son 87.928 mensajes recorridos enteros en cada chat abierto. Sin foreign
 * key, como el resto del esquema.
 *
 * La guarda por `SHOW INDEX` es para las instancias donde alguien lo haya creado a mano por
 * SSH antes de este upgrade: un `ADD INDEX` repetido tira y dejaría el upgrade a medias.
 */
class AddBuyerIdIndexToMessagesTable extends Migration
{
    /** Nombre corto y fijo del índice, para que la guarda y el down() miren exactamente el mismo. */
    const INDICE = 'messages_buyer_id_idx';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if ($this->existe_el_indice()) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->index('buyer_id', self::INDICE);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! $this->existe_el_indice()) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(self::INDICE);
        });
    }

    /**
     * ¿Ya existe el índice en la base conectada?
     *
     * @return bool
     */
    private function existe_el_indice()
    {
        return count(DB::select("SHOW INDEX FROM messages WHERE Key_name = 'messages_buyer_id_idx'")) > 0;
    }
}
