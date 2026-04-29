<?php

namespace App\Jobs;

use App\Exports\ArticleExport;
use App\Models\Article;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ProcessArticleExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Define timeout amplio para exportaciones grandes.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Evita reprocesos duplicados sobre una misma exportación solicitada.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Guarda el usuario owner que recibirá la notificación final.
     *
     * @var int
     */
    protected $owner_user_id;

    /**
     * Guarda el id del usuario autenticado al solicitar la exportación.
     *
     * @var int
     */
    protected $auth_user_id;

    /**
     * Guarda los ids de artículos a exportar.
     *
     * @var array
     */
    protected $article_ids;

    /**
     * Crea el job de exportación en segundo plano.
     *
     * @param int $owner_user_id
     * @param int $auth_user_id
     * @param array $article_ids
     */
    public function __construct($owner_user_id, $auth_user_id, $article_ids)
    {
        // Usuario owner que define el canal de notificación.
        $this->owner_user_id = (int) $owner_user_id;

        // Usuario autenticado para restringir la visualización si aplica.
        $this->auth_user_id = (int) $auth_user_id;

        // IDs finales del lote a exportar.
        $this->article_ids = is_array($article_ids) ? $article_ids : [];
    }

    /**
     * Ejecuta la generación de excel, guarda el archivo y notifica resultado.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // Se valida owner para evitar procesar jobs sin destino de notificación.
            $owner_user = User::find($this->owner_user_id);
            if (is_null($owner_user)) {
                Log::warning('ProcessArticleExportJob: owner no encontrado', [
                    'owner_user_id' => $this->owner_user_id,
                ]);
                return;
            }

            /**
             * Si vienen IDs explícitos, exporta ese subconjunto.
             * Si no vienen (flujo dropdown), se exporta todo vía ArticleExport con models null.
             */
            $models = null;
            if (count($this->article_ids)) {
                $models = Article::where('user_id', $this->owner_user_id)
                                ->whereIn('id', $this->article_ids)
                                ->get();
            }

            // Nombre único para evitar colisiones entre exportaciones concurrentes.
            $file_name = 'comerciocity-articulos_' . date_format(Carbon::now(), 'd-m-y_H-i-s') . '_' . uniqid() . '.xlsx';
            $relative_path = 'exported-files/' . $file_name;

            // Se persiste el archivo en storage/app/exported-files.
            Excel::store(new ArticleExport($models, $this->owner_user_id), $relative_path);

            // Link público para descarga directa desde la notificación global.
            $api_url = config('app.API_URL');
            if (
                config('app.APP_ENV') == 'production' 
                && !config('app.VPS')
            ) {
                $api_url .= '/public';
            }
            $download_link = $api_url. '/exported-files/' . rawurlencode($file_name);

            // Botón que abre una nueva ventana con el archivo listo.
            $functions_to_execute = [
                [
                    'btn_text' => 'Descargar excel',
                    'btn_variant' => 'primary',
                    'link' => $download_link,
                ],
            ];

            // Información resumida para que el usuario sepa cuántos registros se exportaron.
            $info_to_show = [
                [
                    'title' => 'Resultado de la exportacion',
                    'parrafos' => [
                        !is_null($models) ? count($models) . ' articulos exportados' : 'Exportacion solicitada para todos los articulos',
                    ],
                ],
            ];

            $owner_user->notify(new GlobalNotification([
                'message_text' => 'El excel de articulos ya esta listo para descargar',
                'color_variant' => 'success',
                'functions_to_execute' => $functions_to_execute,
                'info_to_show' => $info_to_show,
                'owner_id' => $owner_user->id,
                'is_only_for_auth_user' => $this->auth_user_id,
            ]));
        } catch (Exception $e) {
            Log::error('ProcessArticleExportJob: error al generar exportacion', [
                'owner_user_id' => $this->owner_user_id,
                'auth_user_id' => $this->auth_user_id,
                'article_ids_count' => count($this->article_ids),
                'message' => $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine(),
            ]);

            $owner_user = User::find($this->owner_user_id);
            if (!is_null($owner_user)) {
                // Botón de cierre para mantener UX consistente en fallas.
                $functions_to_execute = [
                    [
                        'btn_text' => 'Entendido',
                        'btn_variant' => 'primary',
                    ],
                ];

                // Se informa de forma explícita que el archivo no pudo generarse.
                $owner_user->notify(new GlobalNotification([
                    'message_text' => 'No se pudo generar el excel de articulos',
                    'color_variant' => 'danger',
                    'functions_to_execute' => $functions_to_execute,
                    'info_to_show' => [],
                    'owner_id' => $owner_user->id,
                    'is_only_for_auth_user' => $this->auth_user_id,
                ]));
            }
        }
    }
}
