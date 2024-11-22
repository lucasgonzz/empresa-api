<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Mantenimiento;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckStockMovements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_stock_movements';

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
        $this->info("Iniciando recalculo de stock...");

        // Obtener movimientos generados en las últimas 24 horas
        $movimientosRecientes = StockMovement::whereDate('created_at', '>=', Carbon::today()->subDays(1))
                                            ->get();

        // Obtener los artículos afectados
        $articulosAfectados = $movimientosRecientes->pluck('article_id')->unique();

        foreach ($articulosAfectados as $articuloId) {
            
            $article = Article::find($articuloId);

            // if ($article->user_id == 228) {
            if ($article->user_id == 228 && $article->num == 996) {

                $this->info("Recalculando movimientos del artículo: ".$article->name);
                $this->info('');
                // if (count($article->addresses) >= 1) {

                //     $this->info("Recalculando movimientos del artículo: ".$article->name);
                //     $this->info('');
                // }

                // continue;
                // Obtener todos los movimientos del artículo, ordenados por fecha
                $movimientos = StockMovement::where('article_id', $articuloId)
                                        ->orderBy('id', 'asc')
                                        ->get();

                // Inicializar el stock para recalcular
                $stockActual = 0; 

                foreach ($movimientos as $movimiento) {

                    // Si es un movimiento entre depósitos, el stock no cambia
                    if (
                        str_contains($movimiento->concepto, 'Act de depositos')  
                        || str_contains($movimiento->concepto, 'Creacion de deposito')
                        || str_contains($movimiento->concepto, 'Mov. Deposito')  
                        || str_contains($movimiento->concepto, 'Movimiento de depositos')
                        ) {

                        $movimiento->stock_resultante = $stockActual;

                    } else {

                        // Calcular el stock resultante

                        $amount = $movimiento->amount;
                        if (str_contains($movimiento->concepto, 'Venta')
                            && $movimiento->amount > 0) {
                            $amount = -$movimiento->amount;
                        }

                        $stockActual += $amount;
                        $movimiento->stock_resultante = $stockActual;
                    }

                    // Guardar el movimiento con el stock recalculado
                    $movimiento->save();
                }

                $this->check_depositos($article);

                $this->check_stock_actual($article, $movimientos);
            }

        }

        $this->info("Recalculo de stock completado.");

        return 0;
    }

    function check_stock_actual($article, $movimientos) {

        $stock_resultante = $movimientos[count($movimientos) - 1]->stock_resultante;

        if ($article->stock != $stock_resultante) {

            Mantenimiento::create([
                'notas'     => 'Stock actual diferente a stock_resultante del ultimo movimiento de stock. Article id: '.$article->id.'. Nombre: '.$article->name.'. Stock: '.$article->stock.'. stock_resultante: '.$stock_resultante,
                'user_id'   => $article->user_id,
            ]);
        }
    }

    function check_depositos($article) {

        if (count($article->addresses) >= 1) {

            $total = 0;

            foreach ($article->addresses as $address) {
                $total += $address->pivot->amount;    
            }

            if ($total != $article->stock) {

                Mantenimiento::create([
                    'notas'     => 'Stock diferente a suma de depositos. Article id: '.$article->id.'. Nombre: '.$article->name.'. Stock: '.$article->stock.'. Suma depositos: '.$total,
                    'user_id'   => $article->user_id,
                ]);
            }
        }
    }
}
