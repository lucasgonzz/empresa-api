<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asegura que exista la plantilla "ComercioCity" en `online_templates` (pedido de Lucas,
 * 21/9/2026: agregarla a Unicas y a todos los clientes que no la tengan).
 *
 * 🔴 Por que una migracion y no alcanza con el seeder. `OnlineTemplateComercioCitySeeder` ya
 * existia para esto, pero es manual: hay que acordarse de correrlo cliente por cliente en cada
 * upgrade. `DemoSetupHelper`/`UserSetupHelper` ya la siembran sola en toda instalacion NUEVA
 * (via `OnlineTemplateSeeder` en `base_seeders()`, ver mision `plantilla-comerciocity-y-condicion-fiscal-en-setups`
 * del 27/8/2026) — lo que faltaba era la base YA instalada, que el upgrade corre por migraciones,
 * no por seeders. Mismo criterio que `asegurar_payment_method_type_mercado_pago`.
 *
 * El 21/9/2026 se corrio un barrido manual (SSH, `_cruzado/misiones/20260921-plantilla-comerciocity-todos-los-clientes/`)
 * que ya la agrego a la flota activa de ese momento, incluida Unicas. Esta migracion es la
 * cobertura permanente: la deja resuelta sola para cualquier cliente que en ese momento no tenia
 * `active_client_api_id` resuelto (Distribuidora Pets, Rosmar, Feitoamao — corrian un esquema
 * anterior a que existiera `online_templates` y el barrido no pudo tocarlos), para reinstalaciones
 * desde un backup viejo, y para cualquier cliente nuevo que en el futuro entre por un camino
 * distinto a los dos setups.
 */
class AsegurarOnlineTemplateComercioCity extends Migration
{
    /** Nombre que muestra el selector "Configuracion online -> Diseño -> Plantilla". */
    const NOMBRE = 'ComercioCity';

    /** Slug que arma la clase CSS de `tienda-spa` (`'plantilla-' + slug`). No se hardcodea el id. */
    const SLUG = 'comerciocity';

    /**
     * Inserta la fila si no esta. Es idempotente: correrla dos veces no duplica nada.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('online_templates')) {
            return;
        }

        $existe = DB::table('online_templates')
            ->where('slug', self::SLUG)
            ->exists();

        if ($existe) {
            return;
        }

        $ahora = now();

        DB::table('online_templates')->insert([
            'name'       => self::NOMBRE,
            'slug'       => self::SLUG,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    /**
     * No revierte nada, a proposito.
     *
     * Borrar la fila dejaria huerfanas las `online_configurations` que ya eligieron esta
     * plantilla por `online_template_id` (y a toda cuenta nueva, que la elige por defecto desde
     * el 27/8/2026). Un `down()` no puede distinguir la fila que creo esta migracion de la que ya
     * estaba en una base sembrada, asi que la unica reversion segura es ninguna — mismo criterio
     * que `asegurar_payment_method_type_mercado_pago`.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
