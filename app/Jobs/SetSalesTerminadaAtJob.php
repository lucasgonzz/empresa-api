<?php

namespace App\Jobs;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SetSalesTerminadaAtJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
   
    public $timeout = 99999999;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        Log::info('entro en el __construct de SetSalesTerminadaAtJob');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        Log::info('entro en el handle');
        
        $sales = Sale::where('user_id', 121)
                        ->whereDate('created_at', '<', Carbon::parse('2024/09/01'))
                        ->where('terminada', 1)
                        ->whereNull('terminada_at')
                        ->orderBy('created_at', 'ASC')
                        ->get();

        $count = 1;
        foreach ($sales as $sale) {
            
            $sale->terminada_at = $sale->created_at;
            $sale->timestamps = false;
            $sale->save();
            $count++;
        }
        Log::info('termino SetSalesTerminadaAtJob. '.$count.' ventas actualizadas');
    }
}
