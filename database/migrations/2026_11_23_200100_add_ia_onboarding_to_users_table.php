<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El ciclo de vida del onboarding conversacional en el dueño (misión onboarding-conversacional-whatsapp,
 * 23-24/11/2026). Todo nullable: null = «el onboarding no empezó» y es como están hoy todos los clientes.
 *
 *   - `ia_onboarding_estado`: en_curso | pausado_sin_respuesta | pausado_por_el_dueno | completo.
 *   - `ia_onboarding_origen`: whatsapp (el primer mensaje del dueño) | pedido (lo pidió él).
 *   - `ia_onboarding_iniciado_at` / `_esenciales_at` / `_completado_at`: cuándo arrancó, cuándo cruzó los 13
 *     temas esenciales y cuándo cerró los 32.
 *   - `ia_onboarding_cursor` / `_cursor_desde`: la pregunta en curso (clave del catálogo) y desde cuándo se le
 *     hizo al dueño; es lo que permite retomar la misma pregunta aunque pasen días.
 *
 * Aditiva, sin claves foráneas, cada columna con su guarda hasColumn (correrla dos veces no rompe).
 */
class AddIaOnboardingToUsersTable extends Migration
{
    /** @var array<string,string> columna => tipo */
    const COLUMNAS = [
        'ia_onboarding_estado'       => 'string:30',
        'ia_onboarding_origen'       => 'string:20',
        'ia_onboarding_iniciado_at'  => 'timestamp',
        'ia_onboarding_esenciales_at' => 'timestamp',
        'ia_onboarding_completado_at' => 'timestamp',
        'ia_onboarding_cursor'       => 'string:60',
        'ia_onboarding_cursor_desde' => 'timestamp',
    ];

    public function up()
    {
        foreach (self::COLUMNAS as $columna => $tipo) {
            if (Schema::hasColumn('users', $columna)) {
                continue;
            }

            Schema::table('users', function (Blueprint $table) use ($columna, $tipo) {
                if (strpos($tipo, 'string:') === 0) {
                    $table->string($columna, (int) substr($tipo, 7))->nullable();
                } else {
                    $table->timestamp($columna)->nullable();
                }
            });
        }
    }

    public function down()
    {
        foreach (array_keys(self::COLUMNAS) as $columna) {
            if (Schema::hasColumn('users', $columna)) {
                Schema::table('users', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
