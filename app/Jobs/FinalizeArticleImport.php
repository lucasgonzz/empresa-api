<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Models\ArticleImportResult;
use App\Models\ImportHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeArticleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $import_uuid, $model_name, $columns, $user, $auth_user_id, $provider_id, $archivo_excel_path;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($import_uuid, $model_name, $columns, $user, $auth_user_id, $provider_id, $archivo_excel_path)
    {
        $this->import_uuid = $import_uuid;
        $this->model_name = $model_name;
        $this->columns = $columns;
        $this->user = $user;
        $this->auth_user_id = $auth_user_id;
        $this->provider_id = $provider_id;
        $this->archivo_excel_path = $archivo_excel_path;
    }


    public function handle()
    {
        DB::beginTransaction();
        try {
            
            // 1) Traer todos los resultados por UUID con relaciones ya cargadas
            $results = ArticleImportResult::with([
                'articulos_creados:id', // sólo id para no cargar de más
                'articulos_actualizados' => function ($q) {
                    $q->select('articles.id'); // pivot vendrá con updated_props
                },
            ])->where('import_uuid', $this->import_uuid)->get();

            if ($results->isEmpty()) {
                Log::warning("FinalizeArticleImport: No hay ArticleImportResults para UUID {$this->import_uuid}");
                DB::rollBack();
                return;
            }

            Log::info('FinalizeArticleImport, results:');
            Log::info($results);

            // 2) Consolidar
            $created_ids = [];
            $updated_props_by_article = []; // [article_id => array props merged]

            foreach ($results as $result) {

                // Log::info('result articulos creados:');
                // Log::info($result->articulos_creados);

                // Log::info('result articulos actualizados:');
                // Log::info($result->articulos_actualizados);


                // 2.a) CREADOS
                foreach ($result->articulos_creados as $art) {
                    $created_ids[] = (int)$art->id;
                }

                // 2.b) ACTUALIZADOS (merge si un article_id apareció en más de un chunk)
                foreach ($result->articulos_actualizados as $art) {
                    $pivot_json = $art->pivot->updated_props ?? '{}';
                    $props = json_decode($pivot_json, true);
                    if (!is_array($props)) {
                        $props = [];
                    }

                    $aid = (int)$art->id;

                    if (!isset($updated_props_by_article[$aid])) {
                        $updated_props_by_article[$aid] = $props;
                    } else {
                        // merge por clave (la última ocurrencia pisa)
                        $updated_props_by_article[$aid] = self::mergeUpdatedProps(
                            $updated_props_by_article[$aid],
                            $props
                        );
                    }
                }
            }

            // Unificar IDs creados
            $created_ids = array_values(array_unique($created_ids));

            Log::info('created_ids:');
            Log::info($created_ids);

            // 3) Crear ImportHistory definitivo (ajustá campos a tu migración real)
            $import_history = ImportHistory::create([
                'created_models'  => count($created_ids),
                // 'created_models'  => count($created_ids),
                'updated_models'  => count($updated_props_by_article),
                'user_id'         => $this->user ? $this->user->id : null,
                'employee_id'     => $this->auth_user_id,
                'model_name'      => 'article',
                'provider_id'     => $this->provider_id,
                'observations'    => ArticleImportHelper::get_observations($this->columns ?? []),
                'excel_url'       => $this->archivo_excel_path,
            ]);

            // 4) Adjuntar relaciones al ImportHistory definitivo
            if (!empty($created_ids)) {
                $import_history->articulos_creados()->syncWithoutDetaching($created_ids);
            }

            if (!empty($updated_props_by_article)) {
                $pivot_data = [];
                foreach ($updated_props_by_article as $article_id => $props_array) {
                    $pivot_data[$article_id] = [
                        'updated_props' => json_encode($props_array, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    ];
                }
                $import_history->articulos_actualizados()->syncWithoutDetaching($pivot_data);
            }

            // 5) Eliminar resultados temporales de este UUID
            ArticleImportResult::where('import_uuid', $this->import_uuid)->delete();

            DB::commit();

            Log::info("FinalizeArticleImport: ImportHistory creado para {$this->import_uuid}");

            ArticleImportHelper::enviar_notificacion($this->user, count($created_ids), count($updated_props_by_article));

            Log::info('Se envio notificacion');

            Artisan::call('set_article_address_stock_from_variants', [
                'user_id' => $this->user->id
            ]);

            
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error("FinalizeArticleImport ERROR: {$th->getMessage()}");
            throw $th;
        }
    }


    /**
     * Merge de updated_props de un mismo artículo cuando aparece en múltiples chunks.
     * - Estrategia: la última ocurrencia pisa campos anteriores.
     * - Mantiene subestructuras (price_types_data, stock_addresses, stock_global, __diff__...).
     */
    public static function mergeUpdatedProps(array $base, array $incoming): array
    {
        // merge superficial clave por clave, incoming prevalece
        // si necesitás un merge profundo para arrays anidados, podés ajustar acá
        foreach ($incoming as $k => $v) {
            $base[$k] = $v;
        }
        return $base;
    }
}
