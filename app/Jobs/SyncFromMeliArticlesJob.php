<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Models\SyncFromMeliArticle;
use App\Services\MercadoLibre\ProductoDownloaderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job en cola: importa publicaciones ML hacia artículos locales para un registro de sync.
 */
class SyncFromMeliArticlesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Tiempo máximo de ejecución (catálogos grandes). */
    public $timeout = 5600;

    /** @var int Id de sync_from_meli_articles */
    public $sync_from_meli_article_id;

    /**
     * @param int $sync_from_meli_article_id Registro de sincronización a procesar.
     */
    public function __construct($sync_from_meli_article_id)
    {
        $this->sync_from_meli_article_id = $sync_from_meli_article_id;
    }

    /**
     * Ejecuta importación en modo create_only (no modifica artículos ya vinculados).
     *
     * @return void
     */
    public function handle()
    {
        $sync_record = SyncFromMeliArticle::find($this->sync_from_meli_article_id);
        if (!$sync_record) {
            return;
        }

        /*
         * Registro visible (misión procesos-en-segundo-plano, 18/9/2026). Sin total: el
         * servicio pagina contra la API de Mercado Libre y no sabe cuántas publicaciones hay
         * hasta la primera respuesta, así que la barra es indeterminada y lo que se muestra es
         * el cierre con los contadores del registro de sync, que es la referencia.
         */
        BackgroundProcessHelper::iniciar($sync_record->user_id, 'importacion_meli', 'Importación desde Mercado Libre', [
            'referencia' => $sync_record,
            'etapa'      => 'Descargando las publicaciones',
        ]);

        try {
            $service = new ProductoDownloaderService($sync_record->user_id);
            $service->importar_productos('create_only', $this->sync_from_meli_article_id);
        } catch (\Throwable $e) {
            /*
             * El servicio ya dejó el registro de sync en error y avisó; acá sólo cae el registro
             * visible, y se relanza para que el job quede en failed_jobs como antes. Va DESPUÉS
             * del rollBack del servicio: si se escribiera adentro de esa transacción, se iría
             * con ella.
             */
            BackgroundProcessHelper::fallar(BackgroundProcessHelper::por_referencia($sync_record), $e->getMessage());

            throw $e;
        }

        // Los contadores finales los escribió persist_sync_success(): se leen frescos. Si el
        // registro desapareció en el medio (no debería), se cierra igual, sin números: la
        // importación ya terminó y esto no puede tirar después del trabajo hecho.
        $cerrado = $sync_record->fresh();

        BackgroundProcessHelper::completar(BackgroundProcessHelper::por_referencia($sync_record), is_null($cerrado) ? [] : [
            'creados'   => (int) $cerrado->articles_created_count,
            'salteados' => (int) $cerrado->articles_skipped_count,
            'con_error' => (int) $cerrado->articles_error_count,
        ]);
    }

    /**
     * Cubre la muerte sin catch (OOM, timeout, worker reiniciado), donde failed() corre en un
     * proceso fresco. Idempotente: si el catch de handle() ya lo cerró, no hace nada.
     *
     * @param  \Throwable $e
     * @return void
     */
    public function failed($e)
    {
        $motivo = !is_null($e) && $e->getMessage() !== ''
            ? $e->getMessage()
            : 'La importación se interrumpió sin dejar traza (probable timeout o worker reiniciado).';

        BackgroundProcessHelper::fallar(
            BackgroundProcessHelper::por_referencia(SyncFromMeliArticle::find($this->sync_from_meli_article_id)),
            $motivo
        );
    }
}
