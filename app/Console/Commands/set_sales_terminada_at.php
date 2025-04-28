<?php

namespace App\Console\Commands;

use App\Models\Sale;
use Illuminate\Console\Command;

class set_sales_terminada_at extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_sales_terminada_at {user_id}';

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
        
        $sales = Sale::where('user_id', $this->argument('user_id'))
                        ->whereNull('terminada_at')
                        ->orderBy('created_at', 'ASC')
                        ->get();

        $this->info(count($sales).' ventas para actualizar');
        $count = 1;
        foreach ($sales as $sale) {
            
            $sale->terminada = 1;
            $sale->terminada_at = $sale->created_at;
            $sale->timestamps = false;
            $sale->save();
            $count++;
        }
        $this->info('termino. '.$count.' ventas actualizadas');
        return 0;
    }
}
