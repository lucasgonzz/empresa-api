<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La clave de Serper del comercio, en la fila del dueño (misión serper-en-user-setup, 28/9/2026).
 *
 * Serper es el buscador de imágenes principal de las asignaciones inteligentes (misión
 * imagenes-catalogo-completo, 27/9/2026). Hasta hoy su clave salía SOLO de
 * `config('services.serper.api_key')` (SERPER_API_KEY en el .env del servidor), así que ponérsela a
 * un cliente era entrar a su servidor, tocar el .env y reiniciar las colas.
 *
 * Desde esta misión la manda el admin en el payload de user-setup y de demo-setup (campo opcional
 * `serper_api_key`, igual que ya manda `google_custom_search_api_key`), `UserSetupHelper` y
 * `DemoSetupHelper` la guardan acá, e `ImageSearchProviderFactory::clave_serper_para()` la usa ANTES
 * que la del .env. El .env queda de respaldo: un admin viejo no la manda, la columna queda en null y
 * todo sigue como hoy.
 *
 * 🔴 ES UN SECRETO: `User::$hidden` la oculta. AuthController@get_user y UserController@update
 * devuelven el modelo User entero, y sin eso la clave viajaría al navegador.
 *
 * - `string(100)`: las claves de Serper son alfanuméricas de 32 a 64 caracteres (el admin las valida
 *   con esa forma); 100 deja margen sin abrir la puerta a cualquier cosa.
 * - Nullable y sin default: null = "no la mandó el admin" y se usa la del .env.
 * - Sin índice: nunca se busca por esta columna, solo se lee la fila del dueño.
 *
 * Sin foreign keys, con guard por columna para que sea segura de re-ejecutar.
 */
class AddSerperApiKeyToUsersTable extends Migration
{
    /**
     * Agrega la columna, con su guard hasColumn.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'serper_api_key')) {
                /* Clave de Serper del comercio. Null = se usa SERPER_API_KEY del .env. */
                $table->string('serper_api_key', 100)->nullable();
            }
        });
    }

    /**
     * Elimina la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'serper_api_key')) {
                $table->dropColumn('serper_api_key');
            }
        });
    }
}
