<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Blindaje de integridad para table_column_preferences.
 *
 * El indice unico original (user_id, model_name) se perdio al agregar preference_type:
 * las migraciones posteriores lo recrearon como indice comun, no unico. Esto permite que
 * existan (y que updateOrCreate() en TableColumnPreferenceController::update(), que no es
 * atomico, llegue a crear) filas duplicadas para la misma combinacion user_id + model_name
 * + preference_type.
 *
 * Esta migracion:
 * 1. Deduplica los grupos que ya tengan mas de una fila, conservando la mas reciente
 *    (por updated_at, desempatando por id) y borrando el resto.
 * 2. Crea el indice unico table_col_pref_user_model_type_unq sobre
 *    (user_id, model_name(60), preference_type) para que la base rechace duplicados futuros.
 *
 * El orden importa: si el indice se crea antes de deduplicar, la migracion falla en
 * cualquier base que ya tenga duplicados.
 */
class DedupeAndUniqueIndexTableColumnPreferences extends Migration
{
    public function up()
    {
        // Buscar las combinaciones user_id + model_name + preference_type que tienen mas de una fila.
        $duplicated = DB::table('table_column_preferences')
            ->select('user_id', 'model_name', 'preference_type', DB::raw('COUNT(*) as total'))
            ->groupBy('user_id', 'model_name', 'preference_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicated as $group) {
            // Filas del grupo duplicado, ordenadas de mas reciente a mas vieja (updated_at desc,
            // desempatando por id desc porque PHP 7.4 no garantiza sort estable en Collection::sortBy).
            $rows = DB::table('table_column_preferences')
                ->where('user_id', $group->user_id)
                ->where('model_name', $group->model_name)
                ->where('preference_type', $group->preference_type)
                ->orderBy('updated_at', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            // Ids a borrar: todas menos la primera (la mas reciente), que es la que se conserva.
            $ids_to_delete = [];

            foreach ($rows->slice(1) as $row) {
                $ids_to_delete[] = $row->id;
            }

            if (count($ids_to_delete)) {
                DB::table('table_column_preferences')->whereIn('id', $ids_to_delete)->delete();
                Log::info('Dedupe table_column_preferences: se borraron ' . count($ids_to_delete) . ' filas duplicadas de ' . $group->model_name . ' / ' . $group->preference_type . ' del usuario ' . $group->user_id);
            }
        }

        // Indice unico con prefijo en model_name (60) para respetar el limite de bytes de MySQL,
        // igual que el indice comun ya existente table_col_pref_user_model_type_idx.
        DB::statement(
            'CREATE UNIQUE INDEX table_col_pref_user_model_type_unq ON table_column_preferences (user_id, model_name(60), preference_type)'
        );
    }

    public function down()
    {
        // Las filas borradas en el dedupe no se restauran (eran duplicados, no hay forma de
        // recuperarlas); solo se revierte el indice unico agregado por esta migracion.
        DB::statement('DROP INDEX table_col_pref_user_model_type_unq ON table_column_preferences');
    }
}
