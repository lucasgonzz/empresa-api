<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemoveLegacyBuyersPhoneUnique extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('buyers')) {
            return;
        }

        $indice = DB::select('SHOW INDEX FROM `buyers` WHERE Key_name = ?', ['buyers_phone_unique']);

        // Fenix conservaba un índice global viejo que el esquema actual no crea. Un teléfono
        // compartido no permite reutilizar la cuenta ni debe bloquear el alta de otra persona.
        // El nombre solo no alcanza: no tocamos índices compuestos ni otras restricciones.
        if (count($indice) === 1
            && (int) $indice[0]->Non_unique === 0
            && $indice[0]->Column_name === 'phone'
            && (int) $indice[0]->Seq_in_index === 1) {
            DB::statement('ALTER TABLE `buyers` DROP INDEX `buyers_phone_unique`');
        }
    }

    public function down()
    {
        // No se reinstala una restricción ajena al esquema: después del alta válida puede haber
        // teléfonos repetidos. Reponerla fallaría o exigiría borrar cuentas; ambas cosas son inseguras.
    }
}
