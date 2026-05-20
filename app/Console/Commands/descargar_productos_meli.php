<?php

namespace App\Console\Commands;

use App\Models\PlatformConnector;
use App\Services\MercadoLibre\ProductoDownloaderService;
use Illuminate\Console\Command;

/**
 * Comando legacy: importa publicaciones ML del usuario config('app.USER_ID').
 */
class descargar_productos_meli extends Command
{
    /**
     * @var string
     */
    protected $signature = 'descargar_productos_meli';

    /**
     * @var string
     */
    protected $description = 'Importa artículos desde Mercado Libre (requiere PlatformConnector conectado)';

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return int
     */
    public function handle()
    {
        $user_id = (int) config('app.USER_ID');
        $platform_connector = PlatformConnector::find_connected_mercado_libre_for_user($user_id);
        if (!$platform_connector) {
            $this->error('No hay conector de Mercado Libre conectado para USER_ID='.$user_id);

            return 1;
        }

        $service = new ProductoDownloaderService($user_id);
        $service->importar_productos('create_only');

        return 0;
    }
}
