<?php

namespace App\Console\Commands;

use App\Models\OnlineConfiguration;
use App\Services\Zippin\ZippinOAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refresca los access_token de Zippin próximos a vencer (grupo 171, prompt 599).
 *
 * El access_token OAuth de Zippin dura aproximadamente 1 año pero también tiene refresh: sin
 * este renovado periódico el comercio se quedaría sin poder operar envíos hasta reconectar
 * manualmente. Corre diario (ver app/Console/Kernel.php) y recorre TODOS los comercios con
 * zippin_enabled=true, no solo el de la instancia (config('app.USER_ID')): puede haber más de un
 * online_configuration por instancia.
 */
class zippin_refresh_tokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zippin:refresh-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Renueva los access_token de Zippin próximos a vencer (comercios con zippin_enabled=true).';

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
     * Ejecuta el comando: busca configuraciones con Zippin habilitado cuyo token vence dentro de
     * la ventana definida (30 días por defecto, más ancha que Mercado Pago porque el token de
     * Zippin dura ~1 año) y las renueva una por una. Si el refresh de una falla (por ejemplo el
     * comercio revocó el acceso desde su cuenta Zipnova), esa configuración queda marcada como
     * desconectada pero el loop sigue con las demás.
     *
     * @return int
     */
    public function handle()
    {
        // Ventana de anticipación: se renuevan los tokens que vencen dentro de estos días. Más
        // ancha que en Mercado Pago (15 días) porque acá el ciclo de vida del token es ~1 año,
        // así que hay más margen y menos urgencia de estar justo al límite.
        $window_days = 30;

        $configurations = OnlineConfiguration::where('zippin_enabled', true)
            ->whereNotNull('zippin_refresh_token')
            ->whereNotNull('zippin_token_expires_at')
            ->where('zippin_token_expires_at', '<=', now()->addDays($window_days))
            ->get();

        $this->info("zippin:refresh-tokens: {$configurations->count()} configuración(es) con token próximo a vencer.");

        $service = new ZippinOAuthService();
        $renewed = 0;
        $disconnected = 0;

        foreach ($configurations as $configuration) {
            $ok = $service->refresh_configuration($configuration);

            if ($ok) {
                $renewed++;
            } else {
                $disconnected++;
                Log::warning("zippin:refresh-tokens: online_configuration {$configuration->id} (user_id {$configuration->user_id}) quedó desconectada.");
            }
        }

        $this->info("zippin:refresh-tokens: {$renewed} renovado(s), {$disconnected} desconectado(s).");

        return 0;
    }
}
