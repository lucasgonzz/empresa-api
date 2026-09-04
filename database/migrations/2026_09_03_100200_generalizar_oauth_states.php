<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generaliza `mercado_pago_oauth_states` a `oauth_states` y le agrega `platform_connector_id`.
 *
 * Por que: hay dos mecanismos anti-CSRF conviviendo en el repo y son de calidad muy distinta.
 *
 * - Mercado Pago (prompt 598) genera un `state` ALEATORIO (`Str::random(64)`, generador
 *   criptografico), lo persiste con vencimiento corto y lo consume una sola vez.
 * - El OAuth generico de `PlatformConnectorOAuthService` manda como `state` el ID del conector
 *   (`PlatformConnector::find((int) $state)`): predecible, reusable y sin vencimiento.
 *
 * Al mudar Mercado Pago a `platform_connectors` habia que elegir cual de los dos quedaba. Esta
 * migracion abre el camino para que quede el bueno: la tabla del state aleatorio deja de ser de
 * Mercado Pago y pasa a poder atarse a cualquier conector.
 *
 * ALCANCE: esta mision solo mueve Mercado Pago. Mercado Libre y Tienda Nube SIGUEN mandando el
 * id del conector como `state` y su callback lo sigue aceptando (ver
 * `PlatformConnectorOAuthService::resolver_conector_del_state()`, que prueba primero el state
 * aleatorio y despues el formato viejo). Cambiarles la URL de autorizacion a ellos es la mision
 * que sigue: hacerlo aca romperia en produccion cualquier ventana de autorizacion ya abierta.
 *
 * `Schema::rename()` conserva los datos, los indices y el AUTO_INCREMENT de la tabla: los states
 * de Mercado Pago que esten en vuelo en el momento del deploy siguen siendo validos.
 */
class GeneralizarOauthStates extends Migration
{
    /**
     * Renombra la tabla y agrega la columna del conector.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('oauth_states')) {
            if (Schema::hasTable('mercado_pago_oauth_states')) {
                Schema::rename('mercado_pago_oauth_states', 'oauth_states');
            } else {
                // Instalacion donde por lo que sea no quedo la tabla de Mercado Pago: se crea la
                // generica de cero, con la misma forma que dejaba la migracion del prompt 598.
                Schema::create('oauth_states', function (Blueprint $table) {
                    $table->id();
                    $table->string('state', 64);
                    $table->unsignedBigInteger('user_id');
                    $table->timestamp('expires_at');
                    $table->timestamp('used_at')->nullable();
                    $table->timestamps();

                    $table->index('state', 'mpo_state_idx');
                    $table->index('user_id', 'mpo_user_idx');
                });
            }
        }

        if (!Schema::hasColumn('oauth_states', 'platform_connector_id')) {
            Schema::table('oauth_states', function (Blueprint $table) {
                // Nullable a proposito: los states del flujo viejo (`create_for_user`) solo
                // saben a que comercio pertenecen, no a que conector. Sin FK, mismo criterio
                // que el resto de la tabla (no la tenia para `user_id` tampoco): si el conector
                // se borra, el state queda huerfano y el `consume` no lo resuelve — que es
                // exactamente lo que corresponde.
                $table->unsignedBigInteger('platform_connector_id')->nullable()->after('user_id');
                $table->index('platform_connector_id', 'oauth_states_connector_idx');
            });
        }
    }

    /**
     * Saca la columna y devuelve la tabla a su nombre de Mercado Pago.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('oauth_states')) {
            return;
        }

        if (Schema::hasColumn('oauth_states', 'platform_connector_id')) {
            Schema::table('oauth_states', function (Blueprint $table) {
                $table->dropIndex('oauth_states_connector_idx');
                $table->dropColumn('platform_connector_id');
            });
        }

        if (!Schema::hasTable('mercado_pago_oauth_states')) {
            Schema::rename('oauth_states', 'mercado_pago_oauth_states');
        }
    }
}
