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
    protected $signature = 'set_sub_total_sales {user_id?} {sale_id?}';

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

        $user_id = config('app.USER_ID');
        
        $param_user_id = $this->argument('user_id');
        
        if ($param_user_id) {
            $user_id = $param_user_id;
        }
        
        $this->info('USER_ID: '.$user_id);

        $sales = Sale::where('user_id', $user_id)
                        ->orderBy('id', 'ASC');
        
        $sale_id = $this->argument('sale_id');
        if ($sale_id) {
            $sales->where('id', '>', $sale_id);
            $this->comment('desde el id > '.$sale_id);
        }
        $sales = $sales->get();

        $this->info(count($sales). ' ventas');

        $processed_count = 0;

        foreach ($sales as $sale) {

            $sub_total = SaleHelper::get_sub_total($sale);

            if ($sub_total <= 0) {
                $this->comment($sale->num.' sub total mal, reportar a Lucas');
            }



            $sale->sub_total = $sub_total;
            $sale->timestamps = false;
            $sale->save();


            $processed_count++;

            // cada 10 ventas procesadas
            if (($processed_count % 30) === 0) {
                $this->comment('Listo venta id '.$sale->id);
            }

        }

        $this->info('Listo :)');
        
        return 0;
    }
}
