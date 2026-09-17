<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `proveedor` a `ai_token_usages` (misión tokens-por-cliente).
 *
 * Por qué hace falta una columna y no alcanza con el nombre del modelo: el costo se
 * calcula en el admin con una tabla de precios POR PROVEEDOR, y el nombre del modelo no
 * dice de quién es. `text-embedding-3-small` es de OpenAI y no tiene la palabra "openai"
 * en ningún lado; el día que dos proveedores tengan un modelo con nombre parecido, adivinar
 * por el string sería un costo mal imputado y mudo.
 *
 * Aditiva y con default `'anthropic'`: las filas viejas —todas de Anthropic, que hasta esta
 * misión era el único proveedor instrumentado— quedan bien sin backfill, y los 9 llamadores
 * de `AiTokenUsageHelper::registrar()` que ya existen siguen andando sin tocarles una línea.
 *
 * Guard `hasColumn` para que sea segura de re-ejecutar (mismo criterio que la migración que
 * creó la tabla).
 */
class AddProveedorToAiTokenUsagesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('ai_token_usages')) {
            return;
        }

        if (Schema::hasColumn('ai_token_usages', 'proveedor')) {
            return;
        }

        Schema::table('ai_token_usages', function (Blueprint $table) {

            /* 'anthropic' | 'openai'. Con default para no romper lo ya escrito ni lo ya construido. */
            $table->string('proveedor', 20)->default('anthropic')->after('proceso');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('ai_token_usages')) {
            return;
        }

        if (! Schema::hasColumn('ai_token_usages', 'proveedor')) {
            return;
        }

        Schema::table('ai_token_usages', function (Blueprint $table) {
            $table->dropColumn('proveedor');
        });
    }
}
