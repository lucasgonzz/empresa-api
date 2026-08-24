<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Límite de crédito por cuenta corriente (misión 160).
 *
 * 🔴 Por qué va en `credit_accounts` y no en `clients`.
 *
 * `credit_accounts` ya está partida por `model_name` + `model_id` + `moneda_id`: cada cliente
 * tiene una fila por moneda (pesos y dólares). El límite de crédito es naturalmente un tope por
 * moneda (deberle $100.000 en pesos no tiene nada que ver con deberle USD 100.000), así que
 * agregarlo acá deja el límite partido por moneda gratis, sin inventar una tabla nueva ni un
 * campo tipo array en `clients`.
 *
 * Nullable, default null: todos los clientes existentes quedan sin límite (comportamiento
 * idéntico al de hoy). Un límite se fija a propósito desde la pantalla de cliente; nadie queda
 * bloqueado por una migración que corrió en silencio.
 *
 * Misma precisión que `saldo` de esta misma tabla (decimal 22,2): el límite se compara contra el
 * saldo, y dos precisiones distintas producirían diferencias de centavos justo en el borde de la
 * comparación.
 */
class AddLimiteCreditoToCreditAccountsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('credit_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_accounts', 'limite_credito')) {
                $table->decimal('limite_credito', 22, 2)->nullable()->default(null);
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('credit_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('credit_accounts', 'limite_credito')) {
                $table->dropColumn('limite_credito');
            }
        });
    }
}
