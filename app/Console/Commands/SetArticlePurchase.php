<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Models\Sale;
use Illuminate\Console\Command;

class SetArticlePurchase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_article_purchase {user_id=500}';

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
                        ->get();

        foreach ($sales as $sale) {

            ArticlePurchaseHelper::set_article_purcase($sale);
            $this->info('Venta '.$sale->num.' del '.$sale->created_at->format('d/m/Y'));
        }
        $this->info('Termino');
        return 0;
    }
}
