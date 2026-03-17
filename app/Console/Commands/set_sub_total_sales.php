<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;
use Illuminate\Console\Command;

class set_sub_total_sales extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_sub_total_sales {sale_id?}';

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
        $sales = Sale::where('user_id', config('app.USER_ID'))
                        ->orderBy('id', 'ASC');
        
        $sale_id = $this->argument('sale_id');
        if ($sale_id) {
            $sales->where('id', '>', $sale_id);
            $this->comment('desde el id > '.$sale_id);
        }
        $sales = $sales->get();

        $this->info(count($sales). ' ventas');



        foreach ($sales as $sale) {

            $sub_total = SaleHelper::get_sub_total($sale);

            if ($sub_total <= 0) {
                $this->comment($sale->num.' sub total mal, reportar a Lucas');
            }

            $sale->sub_total = $sub_total;
            $sale->timestamps = false;
            $sale->save();
        }

        $this->info('Listo :)');
        
        return 0;
    }
}
