<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use Illuminate\Console\Command;

class check_reset_stock_command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_reset_stock_command {user_id}';

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


        $stock_movements = StockMovement::where('user_id', $this->argument('user_id'))
                                            ->where('concepto_stock_movement_id', 2)
                                            ->orderBy('created_at', 'ASC')
                                            ->get();

        foreach ($stock_movements as $stock_movement) {
            $anterior = StockMovement::where('article_id', $stock_movement->article_id)
                                    ->where('id', '<', $stock_movement->id)
                                    ->orderBy('id', 'DESC')
                                    ->first();

            if ($anterior) {
                $amount = 0 - $anterior->stock_resultante;
                // $this->info('Article num: '.$stock_movement->article->num);
                // $this->info('Se va a poner la cantidad de: '.$amount);
                $stock_movement->amount = $amount;
                $stock_movement->timestamps = false;
                $stock_movement->save();
            }
        }
    }
}
