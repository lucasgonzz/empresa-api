<?php

namespace App\Console\Commands;

use App\Models\ProviderOrder;
use Illuminate\Console\Command;
use Carbon\Carbon;

class unidades_a_notas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'unidades_a_notas';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        $orders = ProviderOrder::where('user_id', 121)
                                ->orderBy('created_at', 'ASC')
                                ->get();

        foreach ($orders as $provider_order) {
            
            foreach ($provider_order->articles as $article) {
                $pivot = $article->pivot;

                $pivot->notes = $pivot->amount;
                $pivot->amount = $pivot->received;

                $pivot->save();

            }
            $this->info('Se actualizo pedido N° '.$provider_order->num);
        }
        $this->info('Listo');
        return 0;
    }
}
