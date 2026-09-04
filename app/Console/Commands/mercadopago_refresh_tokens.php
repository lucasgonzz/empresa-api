<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Services\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refresca los access_token de Mercado Pago próximos a vencer (grupo 170, prompt 598).
 *
 * El access_token OAuth de Mercado Pago vence a los 180 días: sin este refresh periódico el
 * comercio se quedaría sin poder cobrar hasta reconectar manualmente. Corre diario (ver
 * app/Console/Kernel.php).
 *
 * Recorre `platform_connectors` (no `online_configurations`, que es de donde leía antes de esta
 * misión): los tokens de cobro se mudaron ahí para sacarlos de la fila que `tienda-api` publica
 * sin autenticación. Recorre TODOS los comercios con conector de Mercado Pago, no solo el de la
 * instancia (config('app.USER_ID')): puede haber más de un comercio por instancia.
 *
 * Un conector sin `expires_at` (credencial cargada a mano que la migración copió desde
 * `payment_methods`) NO entra en la ventana: no tiene refresh_token con qué renovarse y no
 * vence. Intentarlo solo lograría marcarlo desconectado y dejar al comercio sin cobrar.
 */
class mercadopago_refresh_tokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mercadopago:refresh-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Renueva los access_token de Mercado Pago próximos a vencer (conectores de platform_connectors).';

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
     * Ejecuta el comando: busca conectores de Mercado Pago cuyo token vence dentro de la ventana
     * definida (15 días por defecto) y los renueva uno por uno. Si el refresh de uno falla (por
     * ejemplo el comercio revocó el acceso desde su cuenta de MP), ese conector queda marcado
     * como desconectado pero el loop sigue con los demás.
     *
     * @return int
     */
    public function handle()
    {
        // Ventana de anticipación: se renuevan los tokens que vencen dentro de estos días, para
        // no esperar al último momento (evita que un cron caído justo ese día deje al comercio
        // sin cobrar).
        $window_days = 15;

        $connectors = PlatformConnector::with('platform')
            ->whereHas('platform', function ($platform_query) {
                $platform_query->where('slug', Platform::SLUG_MERCADO_PAGO);
            })
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->whereNotNull('refresh_token')
            ->where('refresh_token', '!=', '')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($window_days))
            ->get();

        $this->info("mercadopago:refresh-tokens: {$connectors->count()} conector(es) con token próximo a vencer.");

        $service = new MercadoPagoOAuthService();
        $renewed = 0;
        $disconnected = 0;

        foreach ($connectors as $connector) {
            $ok = $service->refresh_connector($connector);

            if ($ok) {
                $renewed++;
            } else {
                $disconnected++;
                Log::warning("mercadopago:refresh-tokens: platform_connector {$connector->id} (user_id {$connector->user_id}) quedó desconectado.");
            }
        }

        $this->info("mercadopago:refresh-tokens: {$renewed} renovado(s), {$disconnected} desconectado(s).");

        return 0;
    }
}
