<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\GlobalSearchDefaultsHelper;
use App\Models\TableColumnPreference;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Siembra las propiedades tildadas por defecto del buscador general (preference_type
 * `global_search`), una fila por dueño y por modelo de GlobalSearchDefaultsHelper::DEFAULTS.
 *
 * Idempotente: se puede correr todas las veces que haga falta y deja siempre una sola fila por
 * (user_id, model_name, 'global_search') con el mismo contenido. Es lo que permite cambiar la
 * tabla de defaults y volver a correrlo.
 *
 * 🔴 PISA lo que haya, por decisión de Lucas del 13/8/2026: el buscador general todavía no está
 * en producción (en `develop` no existe ni la carpeta del componente), así que no hay ninguna
 * elección real de un cliente que proteger. Importa porque la selección se persiste también al
 * buscar, no solo al tildar: cualquier usuario que haya hecho una sola búsqueda ya tiene fila.
 *
 * 🔴 Y toca SOLO `preference_type = 'global_search'` y solo los model_name de la tabla. Ni
 * `table`, ni `search`, ni `form_has_many`, ni `global_search_filters`, ni los `btm_*`: un seeder
 * que se llevara puesta la configuración de columnas de las tablas sería un desastre silencioso.
 */
class GlobalSearchDefaultsSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        $model_names = GlobalSearchDefaultsHelper::model_names();

        // chunkById en vez de get(): mismo patrón que PdfColumnProfileArticleSeeder, para no
        // cargar en memoria todos los usuarios de una base grande.
        User::query()
            ->whereNull('owner_id')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($owners) use ($model_names) {
                foreach ($owners as $owner) {
                    $this->sembrar_owner($owner->id, $model_names);
                    $this->borrar_empleados($owner->id, $model_names);
                }
            });
    }

    /**
     * Fila del dueño para cada modelo de la tabla: se reemplaza si existe, se crea si no.
     *
     * @param int   $owner_id
     * @param array $model_names
     * @return void
     */
    protected function sembrar_owner($owner_id, array $model_names)
    {
        foreach ($model_names as $model_name) {
            TableColumnPreference::updateOrCreate(
                [
                    'user_id'         => $owner_id,
                    'model_name'      => $model_name,
                    'preference_type' => 'global_search',
                ],
                [
                    'columns'  => GlobalSearchDefaultsHelper::columns_for($model_name),
                    'settings' => GlobalSearchDefaultsHelper::settings(),
                ]
            );
        }
    }

    /**
     * Borra las filas `global_search` de los empleados de este dueño para los modelos de la tabla,
     * para que caigan al fallback dueño->empleado que TableColumnPreferenceController::show() ya
     * resuelve.
     *
     * Es más limpio que duplicarles la misma configuración: el día que se cambie un default,
     * alcanza con volver a correr el seeder y los empleados lo heredan solos.
     *
     * @param int   $owner_id
     * @param array $model_names
     * @return void
     */
    protected function borrar_empleados($owner_id, array $model_names)
    {
        $employee_ids = User::where('owner_id', $owner_id)->pluck('id')->all();

        if (empty($employee_ids)) {
            return;
        }

        TableColumnPreference::whereIn('user_id', $employee_ids)
            ->whereIn('model_name', $model_names)
            ->where('preference_type', 'global_search')
            ->delete();
    }
}
