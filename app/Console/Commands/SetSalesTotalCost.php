<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\sale\SaleTotalesHelper;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SetSalesTotalCost extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_sales_total_cost {user_id=500}';

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
        $user_id = $this->argument('user_id');

        $sales = Sale::where('user_id', $user_id)
                        ->orderBy('created_at', 'ASC')
                        ->where('created_at', '>=', Carbon::today()->subMonths(2))
                        ->get();

        foreach ($sales as $sale) {

            $sale = SaleTotalesHelper::set_total_cost($sale);
            $this->info('Se seteo total de venta N° '.$sale->num);
            $this->info('Fecha '.$sale->created_at->format('d/m/Y'));
            $this->comment('Total cost '.$sale->total_cost);
            $this->info('');
        }

        $this->info('Termino');
        return 0;
    }
}
