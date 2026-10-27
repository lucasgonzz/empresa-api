<?php

namespace App\Console\Commands;

use App\Models\PlatformConnector;
use App\Services\MercadoLibre\OrderDownloaderService;
use Illuminate\Console\Command;

/**
 * Consulta periódica de pedidos de Mercado Libre del dueño de la instancia (misión
 * mercado-libre-integracion, 27/10/2026). La programa el Kernel cada 5 minutos, solo con la
 * extensión `usa_mercado_libre` y la cuenta conectada.
 *
 * Por qué consulta y no notificaciones: la app de ML tiene UNA sola URL de notificaciones para
 * todos los clientes, y rutearlas exige un relay con el mapa vendedor → cliente. La consulta es
 * autosuficiente y cuesta una llamada cada 5 minutos por cliente.
 */
class meli_sync_orders extends Command
{
    /**
     * @var string
     */
    protected $signature = 'meli_sync_orders';

    /**
     * @var string
     */
    protected $description = 'Trae los pedidos de Mercado Libre actualizados desde la última consulta y genera las ventas de los pagos';

    /**
     * @return int
     */
    public function handle()
    {
        $user_id = (int) config('app.USER_ID');

        if (!PlatformConnector::find_connected_mercado_libre_for_user($user_id)) {
            $this->info('Mercado Libre no está conectado: no hay pedidos que consultar.');

            return 0;
        }

        try {
            $service = new OrderDownloaderService($user_id);
            $resultado = $service->sincronizar_pedidos_recientes();
        } catch (\Throwable $e) {
            $this->error('No se pudieron consultar los pedidos de Mercado Libre: '.$e->getMessage());

            return 0;
        }

        $this->info($resultado['procesados'].' pedidos procesados.');

        return 0;
    }
}
