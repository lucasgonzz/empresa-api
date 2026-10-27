<?php

namespace App\Console\Commands;

use App\Models\PlatformConnector;
use App\Models\SyncToMeliArticle;
use App\Services\MercadoLibre\ProductService;
use Illuminate\Console\Command;

/**
 * Procesa la cola de artículos pendientes de sincronizar hacia Mercado Libre (una corrida por
 * minuto desde el scheduler, solo con la extensión `usa_mercado_libre`).
 *
 * Misión mercado-libre-integracion (27/10/2026):
 * - Un solo `ProductService` por corrida (antes uno por artículo: un refresh potencial por
 *   artículo).
 * - Toma como mucho `services.mercadolibre.max_por_corrida` pendientes, por id, con
 *   `attempted_at` vacío o de hace más de 2 minutos (los que ML mandó a esperar).
 * - Antes, las filas que quedaron `en_progreso` hace más de 30 minutos (un proceso que murió a
 *   la mitad) vuelven a `pendiente`.
 * - Sin cuenta conectada no hace nada ni notifica (antes notificaba "Mercado Libre no conectado"
 *   en cada corrida).
 */
class sync_mercado_libre extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync_to_meli_articles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza hacia Mercado Libre los artículos pendientes del dueño de la instancia';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $user_id = (int) config('app.USER_ID');

        $liberadas = SyncToMeliArticle::where('user_id', $user_id)
            ->where('status', 'en_progreso')
            ->where('attempted_at', '<', now()->subMinutes(30))
            ->update(['status' => 'pendiente']);

        if ($liberadas > 0) {
            $this->info($liberadas.' sincronizaciones colgadas en en_progreso volvieron a pendiente');
        }

        $max = max(1, (int) config('services.mercadolibre.max_por_corrida', 30));

        $sync_articles = SyncToMeliArticle::where('user_id', $user_id)
            ->where('status', 'pendiente')
            ->where(function ($query) {
                $query->whereNull('attempted_at')
                    ->orWhere('attempted_at', '<=', now()->subMinutes(2));
            })
            ->orderBy('id')
            ->limit($max)
            ->get();

        $this->info(count($sync_articles).' sincronizaciones');

        if (count($sync_articles) === 0) {
            return 0;
        }

        if (!PlatformConnector::find_connected_mercado_libre_for_user($user_id)) {
            $this->warn('Mercado Libre no está conectado: las sincronizaciones quedan pendientes.');

            return 0;
        }

        try {
            $service = new ProductService($user_id);
        } catch (\Throwable $e) {
            // Típicamente el refresh del token falló (la conexión quedó en error y ya se avisó).
            $this->error('No se pudo iniciar la sincronización con Mercado Libre: '.$e->getMessage());

            return 0;
        }

        foreach ($sync_articles as $sync) {
            try {
                $service->sync_article($sync);
            } catch (\Throwable $e) {
                $this->error('Sync #'.$sync->id.': '.$e->getMessage());
            }
        }

        return 0;
    }
}
