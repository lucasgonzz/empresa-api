<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\Order\ReservaStockWebHelper;
use App\Models\OnlineConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerificarReservaStockWeb extends Command
{
    protected $signature = 'tienda:verificar-reservas {user_id} {url : URL base de la API de Tienda instalada}';
    protected $description = 'Acredita compatibilidad de reservas con la tienda instalada, sin activar la opción';

    public function handle()
    {
        if (!ReservaStockWebHelper::hay_esquema()) {
            $this->error('Primero aplicá las migraciones de reservas del sistema.');
            return 1;
        }
        $user_id = (int) $this->argument('user_id');
        $url = rtrim($this->argument('url'), '/');
        ReservaStockWebHelper::comprobar_tienda($url, $user_id);
        DB::transaction(function () use ($user_id, $url) {
            $config = ReservaStockWebHelper::bloquear_configuracion($user_id);
            if (!$config) {
                throw new \RuntimeException('El comercio no tiene configuración de tienda.');
            }
            $config->reserva_stock_tienda_url = $url;
            $config->reserva_stock_tienda_protocolo = 1;
            $config->save();
        });
        $this->info('Compatibilidad verificada. La reserva sigue con su configuración actual; solo el dueño puede activarla.');
        return 0;
    }
}
