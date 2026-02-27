<?php

namespace App\Console\Commands;

use App\Models\ProviderOrder;
use Illuminate\Console\Command;

class set_provider_order_afip_tickets_user_id extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_provider_order_afip_tickets_user_id {user_id? : User ID a usar. Si no se pasa, toma USER_ID del .env}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setea el user_id para provider_order_afip_tickets (toma parametro o USER_ID del .env)';

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
        // 1) Prioridad al parámetro
        $user_id = $this->argument('user_id');

        // 2) Si no vino parámetro, tomo del .env
        if (empty($user_id)) {
            $user_id = config('app.USER_ID');
        }

        // 3) Si no hay ninguno, error y salgo con código != 0
        if (empty($user_id)) {
            $this->error('Falta el user_id. Pasalo como parámetro: php artisan set_provider_order_afip_tickets_user_id {user_id} o definí USER_ID en el .env');
            return 1;
        }

        $this->info("Usando user_id: {$user_id}");

        // Acá seguís con tu lógica real usando $user_id...



        $provider_orders = ProviderOrder::where('user_id', $user_id)
                                        ->get();

        foreach ($provider_orders as $provider_order) {

            // $this->total_iva_comprado += (float)$provider_order->total_iva;

            foreach ($provider_order->provider_order_afip_tickets as $afip_ticket) {

                $afip_ticket->user_id = $user_id;
                $afip_ticket->timestamps = false;
                $afip_ticket->save();

                $this->comment('afip_ticket '.$afip_ticket->id.' ok');
            }
        }
        $this->info('Terminado exitosamente');

        return 0;
    }
}