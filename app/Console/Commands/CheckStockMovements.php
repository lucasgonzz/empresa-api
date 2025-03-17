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
    protected $signature = 'check_stock_movements_viejo {article_id?} {article_num?} {user_id?} {--todos_los_articulos} {--corregir_stock} {--check_movimientos_repetidos}';

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



        $this->corregir_stock = $this->option('corregir_stock');

        $this->notas = '';
            

        if ($this->corregir_stock) {
            $this->comment('corregir_stock SI');
        } else {
            $this->comment('corregir_stock NO');
        }

        sleep(4);

        $this->todos_los_articulos = $this->option('todos_los_articulos');
        $this->check_movimientos_repetidos = $this->option('check_movimientos_repetidos');

        $this->article_id = $this->argument('article_id') ?? null;
        $this->article_num = $this->argument('article_num') ?? null;
        $this->user_id = $this->argument('user_id') ?? null;

        $this->articulos_afectados = [];

        $this->info("Iniciando recalculo de stock...");

        $this->init_movimientos();
        
        $articulos_chequeados = 0;

        $this->comment(count($this->articulos_afectados).' articulos para chequear');

        foreach ($this->articulos_afectados as $articuloId) {

            $articulos_chequeados++;
            
            $article = Article::find($articuloId);

            if (is_null($article)) {
                continue;
            }

            if ($this->check_article($article)) {

                $this->recalcular_movimientos($article);
            }

            if ($articulos_chequeados % 50 == 0) {
                $this->comment('Se chequearon '.$articulos_chequeados);
            }

        }

        Mantenimiento::create([
            'notas'     => 'Se repaso el stock de '.$articulos_chequeados.' articulos. Del user_id: '.$this->user_id.'. Notas: '.$this->notas,
            'user_id'   => $this->user_id,
        ]);   

        $this->info("Recalculo de stock completado.");

        return 0;
    }

    function init_movimientos() {

        if ($this->todos_los_articulos) {

            $this->articulos_afectados = Article::where('user_id', $this->user_id)
                                            ->pluck('id');

        } else {

            // Obtener movimientos generados en las últimas 24 horas
            $movimientosRecientes = StockMovement::whereDate('created_at', '>=', Carbon::today()->subDays(2))
                                                ->get();

            // Obtener los artículos afectados
            $this->articulos_afectados = $movimientosRecientes->pluck('article_id')->unique();
        }


    }

    function check_article($article) {

        if ($article->default_in_vender) {
            return false;
        }

        if (!is_null($this->article_id) && $this->article_id != '') {
            return $article->id == $this->article_id;
        }

        if (!is_null($this->article_num) && $this->article_num != '') {
            return $article->num == $this->article_num;
        }

        if (!is_null($this->user_id) && $this->user_id != '') {
            return $article->user_id == $this->user_id;
        }
        return true;
    }


    /*
        * Repaso todos los moviemitos de stock del articulo
        Seteo $stockActual con el stock_resultante del primero movimiento de stock
        Despues le voy sumando el amount del proximo stock_movement 
        y le asigno ese resultado a stock_resultante de este proximo stock_movement

        * Solo actualizo el stock resutlante de los movimientos de stock
        * A lo utlimo llamo a check_stock_actual y ahi si cambio el stock actual del articulo 
    */

    function recalcular_movimientos($article) {
        // $this->info('');
        // $this->info("Recalculando movimientos del artículo: ".$article->name.'. Num: '.$article->num);
        // $this->info('');
        
        // if (count($article->addresses) >= 1) {

        //     $this->info("Recalculando movimientos del artículo: ".$article->name);
        //     $this->info('');
        // }

        // Obtener todos los movimientos del artículo, ordenados por fecha
        $movimientos = $this->get_movimientos($article);

        if (!count($movimientos) >= 1) {
            return;
        }

        // Inicializar el stock para recalcular
        $stockActual = 0; 

        if (count($movimientos) >= 1) {
            $stockActual = $movimientos[0]->stock_resultante;
        }

        if ($this->check_movimientos_repetidos) {
            $this->_check_movimientos_repetidos($movimientos);
            $movimientos = $this->get_movimientos($article);
        }
        

        foreach ($movimientos as $movimiento) {

            if ($movimiento->id == $movimientos[0]->id) {
                continue;
            }

            // if ($movimiento->id != $movimientos[count($movimientos) - 1]->id) {
            //     $movimiento->observations = null;
            //     $movimiento->save();
            // }

            // Si es un movimiento entre depósitos, el stock no cambia
            if (
                str_contains($movimiento->concepto, 'Act de depositos')  
                || str_contains($movimiento->concepto, 'Creacion de deposito')
                || str_contains($movimiento->concepto, 'Mov. Deposito')  
                || str_contains($movimiento->concepto, 'Movimiento de depositos')
                ) {

                $movimiento->stock_resultante = $stockActual;
                $movimiento->observations = $stockActual;

            } else {

                // Calcular el stock resultante

                $amount = $movimiento->amount;
                if (str_contains($movimiento->concepto, 'Venta')
                    && !str_contains($movimiento->concepto, 'Restauracion')
                    && !str_contains($movimiento->concepto, 'Nota credito')
                    && !str_contains($movimiento->concepto, 'Act. Venta')
                    && !str_contains($movimiento->concepto, 'Eliminacion de venta')
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

        $article = $this->check_variants($article);

        // Esto para corregir el bug de colman
        // Cuando se pasa venta de chequeada a confirmada,
        // y el articulo tiene 0 en unidades chequeadas,
        // se le regresa al stock y se marca como "se elimino de venta"
        $this->check_se_elimino_de_venta($article);

        $this->check_stock_actual($article, $movimientos);
    }

    function check_se_elimino_de_venta($article) {

        if (!$this->corregir_stock) {
            return;
        }

        $stock_movements = StockMovement::where('article_id', $article->id)
                                                ->orderBy('created_at', 'ASC')
                                                ->get();
            
        foreach ($stock_movements as $stock_movement) {
            
            if (str_contains($stock_movement->concepto, 'Se elimino de la venta')) {

                $num_venta = substr($stock_movement->concepto, 23);
                

                $stock_movement_de_la_venta = StockMovement::where('article_id', $article->id)
                                        ->where('concepto', 'Venta N° '.$num_venta)
                                        ->first();

                if (is_null($stock_movement_de_la_venta)) {
                    $this->comment('Article: '.$article->name. '. Num: '.$article->num);

                    $this->info('N° venta: '.$num_venta);
                    
                    $this->info('NO TIENE');
                    $article->stock -= $stock_movement->amount;
                    $article->timestamps = false;
                    $article->save();

                    $ultimo_stock_movement = $stock_movements->last();

                    if ($ultimo_stock_movement) {

                        $ultimo_stock_movement->observations = $article->stock;
                        $ultimo_stock_movement->save();
                    }
                    $stock_movement->delete();

                    $this->notas .= ' Se hizo movimiento al pedo de eliminacion de venta para '.$article->name;
                }
            }
        }
    }

    function get_movimientos($article) {

        $movimientos = StockMovement::where('article_id', $article->id)
                                ->orderBy('id', 'asc')
                                ->get();

        return $movimientos;
    }

    function _check_movimientos_repetidos($movimientos) {

        $index = 0;

        foreach ($movimientos as $movimiento) {

            $index_siguiente = $index + 1;
            
            if ($index_siguiente == count($movimientos)
                || !isset($movimientos[$index_siguiente])) {
                break;
            }

            $siguiente_movimiento = $movimientos[$index_siguiente];

            if ($movimiento->concepto == $siguiente_movimiento->concepto
                && $movimiento->concepto != 'Creacion de deposito'
                && $movimiento->concepto != 'Movimiento de depositos'
                && $movimiento->concepto != 'Act de depositos'
                && $movimiento->concepto != 'Reseteo de stock'
                && $movimiento->amount == $siguiente_movimiento->amount) {

                if (!$this->es_un_movimiento_de_depositos($movimiento, $siguiente_movimiento)) {

                    $createdAt1 = $movimiento->created_at; 
                    $createdAt2 = $siguiente_movimiento->created_at;

                    $diferencia_en_segundos = $createdAt1->diffInSeconds($createdAt2);

                    if ($diferencia_en_segundos < 10) {

                        $this->comment('Article: '.$movimiento->article->name.'. Num: '.$movimiento->article->num);
                        $this->comment('Movimiento repetido '.$movimiento->concepto);

                        if ($this->corregir_stock) {

                            $movimientos[$index_siguiente]->delete();
                            $this->info('Se elimino mov repetido');

                            // Eliminar el movimiento repetido del array
                            unset($movimientos[$index_siguiente]);

                            // Reindexar el array para evitar problemas con índices
                            $movimientos = $movimientos->values();
                        }
                        $this->comment('');
                        $this->comment('');

                    }
                }


            }

            $index++;
        }
    }

    function es_un_movimiento_de_depositos($movimiento, $siguiente_movimiento) {
        if (!is_null($movimiento->to_address_id)
            && !is_null($siguiente_movimiento->to_address_id)
            && $movimiento->to_address_id != $siguiente_movimiento->to_address_id) {

            return true;
        }
        return false;
    }


    /*
        Si el stock actual es distinto al stock resultante del ultimo movimiento,
        seteo el stock actual del articulo con el stock actual del ultimo movimiento
        y cambio todos los movimientos anteriores para que den como resultado el stock resultante del ultimo movimiento
    */

    function check_stock_actual($article, $movimientos) {

        $stock_resultante = $movimientos[count($movimientos) - 1]->stock_resultante;
        
        $epsilon = 0.1;

        if (abs($article->stock - $stock_resultante) > $epsilon) {
        // if ($article->stock != $stock_resultante) {

            // $this->info($article->name);

            $this->info('Article: '.$article->name.' num: '.$article->num);
            $this->comment('Stock actual diferente al resultante');
            $this->info('');
            $this->info('');
            
            if (!$this->corregir_stock) {

                return;
            } 

            $diferencia = (float)$article->stock - (float)$stock_resultante;

            $this->modificar_primer_movimiento_para_compensar($article, $movimientos, $diferencia);
            
            $this->recalcular_movimientos($article);

            // Mantenimiento::create([
            //     'notas'     => 'Stock actual diferente a stock_resultante del ultimo movimiento de stock. Article id: '.$article->id.'. Nombre: '.$article->name.'. Stock: '.$article->stock.'. stock_resultante: '.$stock_resultante,
            //     'user_id'   => $article->user_id,
            // ]);
        }
    }



    /*
        * Modifico el stock_resultante del primer movimiento
        para que cuando vuelva a recalcular el stock,
        me de el valor del stock actual del articulo
    */
    function modificar_primer_movimiento_para_compensar($article, $movimientos, $diferencia) {

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
                $this->info($article->num.' | '.$article->name);
                $this->comment('Stock diferente a suma de depositos');
                // Mantenimiento::create([
                //     'notas'     => 'Stock diferente a suma de depositos. Article id: '.$article->id.'. Nombre: '.$article->name.'. Stock: '.$article->stock.'. Suma depositos: '.$total,
                //     'user_id'   => $article->user_id,
                // ]);
            }

            if ($this->corregir_stock) {
                $article->stock = $total;
                $article->timestamps = false;
                $article->save();
            }
        }
    }

    function check_variants($article) {

        if (count($article->article_variants) >= 1) {

            $total = 0;

            foreach ($article->article_variants as $variant) {

                if (count($variant->addresses) >= 1) {

                    foreach ($variant->addresses as $variant_address) {

                        $total += $variant_address->pivot->amount;    
                        
                        if ($this->article_num == $article->num) {
                            $this->info('sumando: '.$variant_address->pivot->amount);
                        }
                    }
                }
            }

            if ($total != $article->stock) {
                $this->info('Stock diferente a suma de variantes, tendria que tener '.$total.'. Pero tiene '.$article->stock);

                if ($this->corregir_stock) {
                    $article->stock = $total;
                    $article->timestamps = false;
                    $article->save();
                }
                
            }
        }


        return $article;
    }
}
