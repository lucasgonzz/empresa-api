<?php

namespace App\Console\Commands;

use App\Models\Sale;
use Illuminate\Console\Command;

class set_sales_total_factuado extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_sales_total_factuado';

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
        $sales = Sale::whereNotNull('total_a_facturar')
                        ->orderBy('id', 'ASC')
                        ->get();

        $this->info(count($sales).' ventas');
        foreach ($sales as $sale) {
            $sale->total_facturado = $sale->total_a_facturar;
            $sale->timestamps = false;
            $sale->save();
            $this->line($sale->id.' ok');
        }
        $this->info('Listo');
        return 0;
    }
}
