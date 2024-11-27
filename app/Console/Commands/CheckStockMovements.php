<?php

namespace App\Console\Commands;

use App\Http\Controllers\StockMovementController;
use App\Models\Article;
use App\Models\Mantenimiento;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

        Log::info('**********************************');
        Log::info('Comando check_stock_movements');
        Log::info('**********************************');

        // Obtener movimientos generados en las últimas 24 horas
        $movimientosRecientes = StockMovement::whereDate('created_at', '>=', Carbon::today()->subDays(1))
                                            ->get();

        // Obtener los artículos afectados
        $articulosAfectados = $movimientosRecientes->pluck('article_id')->unique();

        $articulos_chequeados = 0;

        Log::info(count($articulosAfectados).' articulos para chequear');


        foreach ($articulosAfectados as $articuloId) {

            $articulos_chequeados++;
            
            $article = Article::find($articuloId);

            if (is_null($article)) {
                continue;
            }

            Log::info($article->name.'. Id: '.$article->id);

            if ($article->user_id == 121) {
            // if ($article->user_id == 228 && $article->num == 996) {
            // if ($article->id == 132196) {

                $this->recalcular_movimientos($article);
            }

        }

        Mantenimiento::create([
            'notas'     => 'Se repaso el stock de '.$articulos_chequeados.' articulos',
        ]);   

        $this->info("Recalculo de stock completado.");

        return 0;
    }

    function recalcular_movimientos($article) {
        $this->info("Recalculando movimientos del artículo: ".$article->name);
        $this->info('');
        
        // if (count($article->addresses) >= 1) {

        //     $this->info("Recalculando movimientos del artículo: ".$article->name);
        //     $this->info('');
        // }

        // continue;
        // Obtener todos los movimientos del artículo, ordenados por fecha
        $movimientos = StockMovement::where('article_id', $article->id)
                                ->orderBy('id', 'asc')
                                ->get();

        // Inicializar el stock para recalcular
        $stockActual = 0; 

        if (count($movimientos) >= 1) {
            $stockActual = $movimientos[0]->stock_resultante;
        }

        $this->info('la cantidad empeiza en: '.$stockActual);

        foreach ($movimientos as $movimiento) {

            if ($movimiento->id == $movimientos[0]->id) {
                continue;
            }

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

    function check_stock_actual($article, $movimientos) {

        $stock_resultante = $movimientos[count($movimientos) - 1]->stock_resultante;
        
        $epsilon = 0.00001;

        if (abs($article->stock - $stock_resultante) > $epsilon) {
        // if ($article->stock != $stock_resultante) {

            $diferencia = (float)$article->stock - (float)$stock_resultante;

            $this->crear_movimiento_para_compensar($article, $movimientos, $diferencia);
            
            $this->recalcular_movimientos($article);

            // Mantenimiento::create([
            //     'notas'     => 'Stock actual diferente a stock_resultante del ultimo movimiento de stock. Article id: '.$article->id.'. Nombre: '.$article->name.'. Stock: '.$article->stock.'. stock_resultante: '.$stock_resultante,
            //     'user_id'   => $article->user_id,
            // ]);
        }
    }

    function crear_movimiento_para_compensar($article, $movimientos, $diferencia) {

        $this->info('Se va a compensar con la diferencia de '.$diferencia);

        $primer_movimiento = $movimientos[0];

        $stock_resultante_del_primero = (float)$primer_movimiento->stock_resultante;

        $this->info('stock_resultante_del_primero: '.$stock_resultante_del_primero);
        // $this->info('concepto del primero: '.$primer_movimiento->concepto);

        $nuevo_stock_resultante = $stock_resultante_del_primero + $diferencia;

        $this->info('resutlado: '.$nuevo_stock_resultante);

        $primer_movimiento->stock_resultante = $nuevo_stock_resultante;

        $primer_movimiento->save();

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
