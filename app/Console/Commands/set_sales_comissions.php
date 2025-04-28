<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;
use App\Models\SellerCommission;
use Carbon\Carbon;
use Illuminate\Console\Command;

class set_sales_comissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_sales_comissions {user_id} {num_sale?}';

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
                        ->where('created_at', '>=', Carbon::today()->subDays(7))
                        ->whereDoesntHave('seller_commissions')
                        ->orderBy('created_at', 'ASC');
        if ($this->argument('num_sale')) {
            $sales = $sales->where('num', $this->argument('num_sale'));
        }

        $sales = $sales->get();

        $this->info(count($sales).' ventas');


        foreach ($sales as $sale) {

            if (
                $sale->client
                && $sale->client->seller_id
            ) {
                $sale->seller_id = $sale->client->seller_id;
                $sale->timestamps = false;
                $sale->save();
            }

            if ($sale->seller_id) {


                $seller_commissions = SellerCommission::where('sale_id', $sale->id)
                                            ->whereNull('haber')
                                            ->get();

                foreach ($seller_commissions as $seller_commission) {
                    $seller_commission->delete();
                }

                $this->comment('Se eliminaron '.count($seller_commissions).' comisiones');

                SaleHelper::crear_comision($sale);
                $this->comment('Comision para venta N° '.$sale->num);
            }
            
        }
        $this->info('TERMINO');

        return 0;
    }
}
