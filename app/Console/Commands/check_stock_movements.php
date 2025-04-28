<?php

namespace App\Console\Commands;

use App\Mail\MantenimientoMail;
use App\Models\Article;
use App\Models\Mantenimiento;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class check_stock_movements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_stock_movements  {article_id?} {article_num?} {user_id?} {--todos_los_articulos} {--corregir_stock} {--check_movimientos_repetidos}';

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

    public function handle()
    {

        $this->corregir_stock = $this->option('corregir_stock');

        $this->notas = '';
            

        if ($this->corregir_stock) {
            $this->comment('corregir_stock SI');
        } else {
            $this->comment('corregir_stock NO');
        }

        sleep(3);

        $this->todos_los_articulos = $this->option('todos_los_articulos');
        $this->check_movimientos_repetidos = $this->option('check_movimientos_repetidos');

        $this->article_id = $this->argument('article_id') ?? null;
        $this->article_num = $this->argument('article_num') ?? null;
        $this->user_id = $this->argument('user_id') ?? null;
        // $this->id_mayor_que = $this->argument('id_mayor_que') ?? null;

        $this->articulos_afectados = [];

        $this->info("Iniciando recalculo de stock...");

        $this->init_movimientos();

        $this->notificaciones = [];

        $articulos_chequeados = 0;

        $this->comment(count($this->articulos_afectados).' articulos para chequear');

        foreach ($this->articulos_afectados as $articuloId) {

            $articulos_chequeados++;
            
            $article = Article::find($articuloId);

            if (is_null($article)) {
                continue;
            }

            if ($this->check_article($article)) {

                $this->movimiento_creacion_deposito = null;

                $this->recalcular_movimientos($article);
            }

            if ($articulos_chequeados % 500 == 0) {
                $this->info('Se chequearon '.$articulos_chequeados.'. Ultimo article: id '.$article->id.', num: '.$article->num);
            }

        }

        // $this->enviar_notificaciones();

        Mantenimiento::create([
            'notas'     => 'Se repaso el stock de '.$articulos_chequeados.' articulos. Del user_id: '.$this->user_id.'. Notas: '.$this->notas,
            'user_id'   => $this->user_id,
        ]);   

        $this->info("Recalculo de stock completado.");

        return 0;
    }

    function enviar_notificaciones() {
        if (count($this->notificaciones) >= 1) {
            Mail::to('comerciocity.erp@gmail.com')->send(new MantenimientoMail($this->user_id, $this->notificaciones));
        }
    }

    function init_movimientos() {

        if ($this->article_num) {

            $this->articulos_afectados = Article::where('num', $this->article_num)
                                                ->where('user_id', $this->user_id)
                                                ->pluck('id');

        } else if ($this->article_id) {

            $this->articulos_afectados = Article::where('id', $this->article_id)
                                                    ->pluck('id');

        } else if ($this->todos_los_articulos) {

            $this->articulos_afectados = Article::where('user_id', $this->user_id)
                                            ->orderBy('id', 'ASC');

            // if ($this->id_mayor_que) {
            //     $this->articulos_afectados = $this->articulos_afectados->where('id', '>=', $this->id_mayor_que);
            // }
            
            $this->articulos_afectados = $this->articulos_afectados->pluck('id');

        } else {

            // Obtener movimientos generados en las últimas 24 horas
            $movimientosRecientes = StockMovement::whereDate('created_at', '>=', Carbon::today()->subDays(2))
                                                ->where('user_id', $this->user_id)
                                                ->get();

            // Obtener los artículos afectados
            $this->articulos_afectados = $movimientosRecientes->pluck('article_id')->unique();
        }


    }

    function check_article($article) {

        if ($article->default_in_vender) {
            return false;
        }

        // if (!is_null($this->user_id) && $this->user_id != '') {
        //     return $article->user_id == $this->user_id;
        // }

        return true;
    }


    /*
        ** Repaso todos los moviemitos de stock del articulo
        
        * Creo variable stock_actual (en 0), en esta variable voy a sumar todos los movimientos de stock para calcular el stock_actual que deberia de tener el articulo.
        
        * Si hay un primer movimiento de stock, seteo stock_actual con el stock_resultante de este primer movimiento

        * Solo actualizo el stock resutlante de los movimientos de stock
        
        * A lo utlimo llamo a check_stock_actual y ahi: 

            Si el stock actual del articulo es distinto al stock resultante del ultimo movimiento (osea lo que calcule en stock_actual),
    
            calculo el stock resultante que deberia de tener el primer movimiento de stock para que la suma de stock_actual de igual al stock actual del articulo
            
            y cambio todos los movimientos anteriores para que den como resultado el stock resultante del ultimo movimiento
    */

    function recalcular_movimientos($article) {

        if ($this->stock_ok($article)) {
            return;
        } 

        // Obtener todos los movimientos del artículo, ordenados por fecha
        $movimientos = $this->get_movimientos($article);

        if (!count($movimientos) >= 1) {
            // $this->comment('No habia movimientos');
            return;
        } else {
            if ($movimientos[count($movimientos)-1]->stock_resultante) {

                $this->comment('Article num° '.$article->num.' stock mal');
            }
        }


        // Inicializar el stock para recalcular
        $stock_actual = 0; 

        // Empezar stock_actual con el stock_resultante
        // $stock_actual = $movimientos[0]->stock_resultante;
        // Empezar stock_actual con amount
        $stock_actual = $movimientos[0]->amount;

        if ($this->check_movimientos_repetidos) {
            $this->_check_movimientos_repetidos($movimientos);
            $movimientos = $this->get_movimientos($article);
        }
        

        foreach ($movimientos as $movimiento) {

            if ($movimiento->id == $movimientos[0]->id) {

                // if ($this->es_reseteo_de_stock($movimiento)) {
                
                //     $stock_actual = $this->reset_stock($movimiento, $stock_actual);
                // }
                continue;
            }

            // if ($movimiento->concepto_movement) {
            //     $this->info($movimiento->concepto_movement->name);
            // }
            // Si es un movimiento entre depósitos, el stock no cambia
            if (
                !is_null($movimiento->concepto_movement)
                && 
                (
                    $movimiento->concepto_movement->name == 'Mov entre depositos'  
                    || $movimiento->concepto_movement->name == 'Mov manual entre depositos'
                )
            ) {

                $movimiento->stock_resultante = $stock_actual;
                // $movimiento->observations = $stock_actual;

            } else if ($this->se_crea_primer_deposito($movimiento)) {

                $this->movimiento_creacion_deposito = $movimiento;

                /*
                    * Si el movimiento es "El primer movimiento de CREACION DE DEPOSITO"
                    entonces se setea stock_actual con la cantidad del movimiento.
                */ 
                
                // $this->info('Se crea deposito para: '.$movimiento->article->name.', num: '.$movimiento->article->num.'. stock_movement_id: '.$movimiento->id);

                $stock_actual = $movimiento->amount;
                $movimiento->stock_resultante = $stock_actual;

            } else if ($this->es_reseteo_de_stock($movimiento)) {

                $this->info('se reseteo de stock');
                $stock_actual = $this->reset_stock($movimiento, $stock_actual);

            } else {

                // Calcular el stock resultante

                $stock_actual += $movimiento->amount;
                $movimiento->stock_resultante = $stock_actual;
            }
            // Guardar el movimiento con el stock recalculado
            $movimiento->timestamps = false;
            $movimiento->save();
        }

        $this->check_depositos($article);

        $article = $this->check_variants($article);

        // Esto para corregir el bug de colman
        // Cuando se pasa venta de chequeada a confirmada,
        // y el articulo tiene 0 en unidades chequeadas,
        // se le regresa al stock y se marca como "se elimino de venta"
        // $this->check_se_elimino_de_venta($article);
        if ($article->stock != $movimientos[count($movimientos)-1]->stock_resultante) {
            $this->comment('El stock deberia ser: '.$stock_actual.', pero es '.$article->stock);
        }
        $this->check_stock_actual($article, $movimientos);


        // $endTime = microtime(true);
        // $executionTime = $endTime - $startTime;
        // $this->comment('Resto del proceso: '.$executionTime.' segundos');
    }

    function es_reseteo_de_stock($movimiento) {
        return !is_null($movimiento->concepto_movement) && $movimiento->concepto_movement->name == 'Reseteo de Stock';
    }

    function reset_stock($movimiento, $stock_actual) {
        $anterior = StockMovement::where('article_id', $movimiento->article_id)
                                        ->where('id', '<', $movimiento->id)
                                        ->orderBy('id', 'DESC')
                                        ->first();

        if ($anterior) {
            $amount = 0 - $anterior->stock_resultante;
            // $this->info('Article num: '.$movimiento->article->num);
            // $this->info('Se va a poner la cantidad de: '.$amount);
            $movimiento->amount = $amount;
        }

        $stock_actual = 0;
        $movimiento->stock_resultante = $stock_actual;
        $movimiento->timestamps = false;
        $movimiento->save();

        return $stock_actual;
    }

    function stock_ok($article) {

        $ultimo_movimiento = StockMovement::where('article_id', $article->id)
                                    ->orderBy('id', 'DESC')
                                    ->first();
        if (
            $ultimo_movimiento  
            && $ultimo_movimiento->stock_resultante == $article->stock
        ) {
            return true;
        }
        return false;
    }

    function se_crea_primer_deposito($movimiento) {
        if (
            $movimiento->concepto_movement
            && $movimiento->concepto_movement->name == 'Creacion de deposito'
        ) {

            /* 
                Busco los movimientos de creacion de depositos 
                Y si el movimiento que llega por parametro, es igual al primer movimiento,
                entonces retorno TRUE, ya que en este movimiento es que se crea el deposito 

            */
            $movimientos_creacion_deposito = StockMovement::where('article_id', $movimiento->article_id)
                                                ->whereHas('concepto', function ($query) {
                                                    $query->where('name', 'Creacion de deposito');
                                                }) 
                                                ->orderBy('id', 'ASC')
                                                ->get();

            if (count($movimientos_creacion_deposito) >= 1) {
                return $movimientos_creacion_deposito[0]->id == $movimiento->id;
            }
        }

        return false;
    }

    function check_se_elimino_de_venta($article) {

        if (!$this->corregir_stock) {
            return;
        }

        $stock_movements = StockMovement::where('article_id', $article->id)
                                                ->orderBy('created_at', 'ASC')
                                                ->get();
            
        foreach ($stock_movements as $stock_movement) {
            
            if (str_contains($stock_movement->concepto->name, 'Se elimino de la venta')) {

                $num_venta = substr($stock_movement->concepto->name, 23);
                

                $stock_movement_de_la_venta = StockMovement::where('article_id', $article->id)
                                        // ->whereHas('concepto', function($q) {
                                        //     $q->where('')
                                        // })
                                        // ->whereHas('concepto', 'Venta N° '.$num_venta)
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

        // $this->comment(count($movimientos).' movimientos de '.$article->name);

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

            if (
                $movimiento->concepto_movement->name == $siguiente_movimiento->concepto->name

                && $movimiento->amount == $siguiente_movimiento->amount

                && $movimiento->concepto_movement->name != 'Creacion de deposito'
                && $movimiento->concepto_movement->name != 'Mov entre depositos'
                && $movimiento->concepto_movement->name != 'Mov manual entre depositos'
                && $movimiento->concepto_movement->name != 'Actualizacion de deposito'
                && $movimiento->concepto_movement->name != 'Reseteo de stock') {

                if (!$this->es_un_movimiento_de_depositos($movimiento, $siguiente_movimiento)) {

                    $createdAt1 = $movimiento->created_at; 
                    $createdAt2 = $siguiente_movimiento->created_at;

                    $diferencia_en_segundos = $createdAt1->diffInSeconds($createdAt2);

                    if ($diferencia_en_segundos < 5) {

                        $this->comment('Article: '.$movimiento->article->name.'. Num: '.$movimiento->article->num);
                        $this->comment('Movimiento repetido '.$movimiento->concepto_movement->name);

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
            $this->comment('Stock actual diferente al resultante. resultante: '.$stock_resultante);
            $this->info('');
            $this->info('');

            $this->notificar("Stock diferente al resultante. Articulo: {$article->name}, num: {$article->num}");
            
            if (!$this->corregir_stock) {

                return;
            } else {
                $article->stock = $stock_resultante;
                $article->timestamps = false;
                $article->save();
                $this->info('Se seteo el stock con el stock resultante');
            }
            return;

            $diferencia = (float)$article->stock - (float)$stock_resultante;
            
            // if ($this->encajar_con_stock_actual) {

            // } else if ($this->encajar_con_stock_resultante) {
            //     $diferencia = (float)$article->stock - (float)$stock_resultante;
            // }


            $this->modificar_primer_movimiento_para_compensar($article, $movimientos, $diferencia);
            
            $this->recalcular_movimientos($article);

            // Mantenimiento::create([
            //     'notas'     => 'Stock actual diferente a stock_resultante del ultimo movimiento de stock. Article id: '.$article->id.'. Nombre: '.$article->name.'. Stock: '.$article->stock.'. stock_resultante: '.$stock_resultante,
            //     'user_id'   => $article->user_id,
            // ]);
        }
    }

    function notificar($notificacion) {
        $this->notificaciones[] = $notificacion;
    }



    /*
        * Modifico el stock_resultante del primer movimiento
        para que cuando vuelva a recalcular el stock,
        me de el valor del stock actual del articulo
    */
    function modificar_primer_movimiento_para_compensar($article, $movimientos, $diferencia) {

        $this->info('Se va a compensar con la diferencia de '.$diferencia);
        $this->comment('hay '.count($movimientos).' movimientos');


        $primer_movimiento = $this->get_primer_movimiento_de_creacion_de_deposito_o_primer_movimiento($movimientos);

        $stock_resultante_del_primero = (float)$primer_movimiento->stock_resultante;

        $this->info('stock_resultante_del_primero: '.$stock_resultante_del_primero);

        $nuevo_stock_resultante = $stock_resultante_del_primero + $diferencia;

        $this->info('resutlado: '.$nuevo_stock_resultante);

        $primer_movimiento->stock_resultante = $nuevo_stock_resultante;

        $primer_movimiento->save();

    }

    function get_primer_movimiento_de_creacion_de_deposito_o_primer_movimiento($movimientos) {
        if ($this->movimiento_creacion_deposito) {
            return $this->movimiento_creacion_deposito;
        }
        return $movimientos[0];
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
                        
                        // if ($this->article_num == $article->num) {
                        //     $this->info('sumando: '.$variant_address->pivot->amount);
                        // }
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
