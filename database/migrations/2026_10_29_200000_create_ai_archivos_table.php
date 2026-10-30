<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los archivos que el agente personal le arma al dueño para bajar (misión agente-mejoras-uso-real,
 * 29/10/2026, §4 E3.1 del plan): hoy, el Excel de exportar_excel. Ver App\Models\AiArchivo y
 * ArchivosDelAgenteIaHelper.
 *
 * El caso real (CONV 9 de Ferre Total, 28/10/2026): «¿me lo podés volcar en un archivo Excel?» y el
 * agente contestó que no podía generar archivos. Ahora arma el .xlsx en el disco PRIVADO y devuelve un
 * link con token que vence a los 7 días.
 *
 * 🔴 EL TOKEN NO SE GUARDA: se guarda su sha256 (`token_hash`). Con la base en la mano no se puede
 * armar un link que funcione; la ruta pública compara con hash_equals el sha256 del `?t=` que llega.
 * Es el mismo criterio que el informe compartido del mostrador.
 *
 * Sin foreign keys (regla de la casa) y con índices de nombre corto.
 */
class CreateAiArchivosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_archivos')) {
            return;
        }

        Schema::create('ai_archivos', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* La identidad pública del archivo: va en la URL del link. */
            $table->string('uuid', 36)->unique();

            /* El dueño de la cuenta (la tenencia es por dueño, como todo el asistente). */
            $table->unsignedBigInteger('user_id');

            /* La persona que lo pidió (ai_conversations.auth_user_id); null si no se sabe. */
            $table->unsignedBigInteger('auth_user_id')->nullable();

            /* El mensaje del asistente que lo generó; null si vino del MCP (sin mensaje). */
            $table->unsignedBigInteger('ai_message_id')->nullable();

            /* Qué es: hoy solo 'excel'. */
            $table->string('tipo', 20)->default('excel');

            /* asistente_archivos/{user_id}/{uuid}.xlsx en el disco `local` (privado). */
            $table->string('path', 191);

            /* El nombre con el que se baja (ranking-de-ventas.xlsx). */
            $table->string('nombre', 120);

            /* sha256 del token del link (64 caracteres hex). El token en claro no se guarda. */
            $table->string('token_hash', 64);

            /* Cuántas filas de datos tiene (para el registro y el análisis diario). */
            $table->unsignedInteger('filas')->default(0);

            /* Después de esta fecha el link da 404 y la limpieza diaria lo borra. */
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'aiarch_user_created_idx');
            $table->index(['expires_at'], 'aiarch_expires_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_archivos');
    }
}
