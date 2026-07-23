<?php

namespace App\Console\Commands;

use App\Models\OnlineConfiguration;
use App\Services\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refresca los access_token de Mercado Pago próximos a vencer (grupo 170, prompt 598).
 *
 * El access_token OAuth de Mercado Pago vence a los 180 días: sin este refresh periódico el
 * comercio se quedaría sin poder cobrar hasta reconectar manualmente. Corre diario (ver
 * app/Console/Kernel.php) y recorre TODOS los comercios con mp_enabled=true, no solo el de la
 * instancia (config('app.USER_ID')): puede haber más de un online_configuration por instancia.
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
    protected $description = 'Renueva los access_token de Mercado Pago próximos a vencer (comercios con mp_enabled=true).';

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
     * Ejecuta el comando: busca configuraciones con Mercado Pago habilitado cuyo token vence
     * dentro de la ventana definida (15 días por defecto) y las renueva una por una. Si el
     * refresh de una falla (por ejemplo el comercio revocó el acceso desde su cuenta de MP), esa
     * configuración queda marcada como desconectada pero el loop sigue con las demás.
     *
     * @return int
     */
    public function handle()
    {
        // Ventana de anticipación: se renuevan los tokens que vencen dentro de estos días, para
        // no esperar al último momento (evita que un cron caído justo ese día deje al comercio
        // sin cobrar).
        $window_days = 15;

        $configurations = OnlineConfiguration::where('mp_enabled', true)
            ->whereNotNull('mp_refresh_token')
            ->whereNotNull('mp_token_expires_at')
            ->where('mp_token_expires_at', '<=', now()->addDays($window_days))
            ->get();

        $this->info("mercadopago:refresh-tokens: {$configurations->count()} configuración(es) con token próximo a vencer.");

        $service = new MercadoPagoOAuthService();
        $renewed = 0;
        $disconnected = 0;

        foreach ($configurations as $configuration) {
            $ok = $service->refresh_configuration($configuration);

            if ($ok) {
                $renewed++;
            } else {
                $disconnected++;
                Log::warning("mercadopago:refresh-tokens: online_configuration {$configuration->id} (user_id {$configuration->user_id}) quedó desconectada.");
            }
        }

        $this->info("mercadopago:refresh-tokens: {$renewed} renovado(s), {$disconnected} desconectado(s).");

        return 0;
    }
}
