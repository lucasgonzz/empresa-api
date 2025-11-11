<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class corregir_stock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'corregir_stock {article_id?} {--solo_informar}';

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
        
        $this->solo_informar = $this->option('solo_informar');

        if ($this->solo_informar) {
            
            $this->info('solo informar');

        } else {
            $this->info('se van a corregir los articulos');
        }

        sleep(3);

        // Articulos cuyo stock actual no coincide con el stock resultante del ultimo movimiento
        $articles = $this->get_articles_mal();

        // $articles = Article::where('user_id', $this->argument('user_id'))
        //                     ->where('num', 7999)
        //                     ->get();



        foreach ($articles as $article) {

            $res = $this->procesar_articulo($article);

            $se_elimino_movimiento = $res['se_elimino_movimiento'];
            $addresses = $res['addresses'];
            $stock = $res['stock'];

            if ($se_elimino_movimiento) {
                $this->info('');
                $this->info('');
                $this->info('SE VA A VOLVER A PROCESAR ARTICULO');
                $this->info('');
                $this->procesar_articulo($article);
            }

            if (
                count($addresses) >= 1
            ) {

                $this->info('');
                $this->info('***************************');
                $this->info('');
                $this->info('Resumen de depositos:');

                foreach ($addresses as $address_id => $info) {


                    $this->info('-> En '. $info['address']->street .' deberia haber '.$info['amount']);

                    if (!$this->solo_informar) {
                        
                        $article->addresses()->updateExistingPivot($address_id, [
                            'amount'    => $info['amount'],
                        ]);
                    }
                }
            } else {
                $this->info('No tenia addresses');
            }

            $this->info('');
            $this->info('***************************');
            $this->info('');

            if ($this->solo_informar) {

                $this->comment($article->name.' deberia tener '.$stock.'. Tiene '.$article->stock);

            } else {

                $article->stock = $stock;
                $article->timestamps = false;
                $article->save();

                $this->info($article->name.' corregido');
            }


        }


        $this->comment('Listo');
        
        return 0;
    }


    /*
        Chequeo todos los movimientos
        Calculo cuanto stock deberia haber en cada deposito
        Si el articulo tiene stock por depositos, y un movimiento no tiene indicado el deposito, lo elimino y retorno $movimiento_eliminado = true para volver a calcular stock
    */
    function procesar_articulo($article) {

        $this->comment('entro en '.$article->id);

        $movimientos = StockMovement::where('article_id', $article->id)
                                    ->orderBy('id', 'ASC')
                                    ->get();

        $this->comment(count($movimientos).' movimientos');

        $addresses = $this->get_addresses($article, $movimientos);

        $stock = 0;
        
        $se_elimino_movimiento = false;

        foreach ($movimientos as $movimiento) {

            if (is_null($movimiento->concepto_movement)) {
                $this->info('No hay concepto para '.$article->name);
            } else {

                if ($movimiento->concepto_movement->name == 'Reseteo de Stock') {
                    $stock = 0;
                    // Aca tendira que resetar tambien $addresses

                } else if (
                    $movimiento->concepto_movement->name == 'Mov entre depositos'
                    || $movimiento->concepto_movement->name == 'Mov manual entre depositos'
                ) {
                    $this->info('No se toca stock por movimiento entre depositos para '.$article->name.'. Stock = '.$stock);
                } else if (
                    $movimiento->concepto_movement->name == 'Creacion de deposito'
                ) {
                    $stock = (float)$movimiento->amount;
                    $this->info('Creacion de deposito para '.$article->name.'. Stock = '.$stock);
                } else {
                    $stock += (float)$movimiento->amount;
                    
                    if (
                        count($addresses) >= 1
                    ) {

                        if (
                            $movimiento->to_address_id
                            && $addresses[$movimiento->to_address_id]['movimiento_inicial_id'] != $movimiento->id
                        ) {

                            $addresses[$movimiento->to_address_id]['amount'] += $movimiento->amount;

                            $this->comment('Sumando '.$movimiento->amount.' a to_address_id '.$addresses[$movimiento->to_address_id]['address']->street.' en concepto de '.$movimiento->concepto_movement->name);

                            $this->comment('Quedo en '.$addresses[$movimiento->to_address_id]['amount']);


                        } else if (
                            $movimiento->from_address_id
                            && $addresses[$movimiento->from_address_id]['movimiento_inicial_id'] != $movimiento->id
                        ) {
                            
                            $addresses[$movimiento->from_address_id]['amount'] += $movimiento->amount;

                            $this->comment('Sumando '.$movimiento->amount.' a from_address_id '.$addresses[$movimiento->from_address_id]['address']->street.' en concepto de '.$movimiento->concepto_movement->name);

                            $this->comment('Quedo en '.$addresses[$movimiento->from_address_id]['amount']);
                        
                        } else if (
                            !$movimiento->from_address_id
                            && !$movimiento->to_address_id
                        ) {

                            $this->comment('DEPOSITO SIN INDICAR');

                            if (!$this->solo_informar) {

                                $movimiento->delete();
                                $se_elimino_movimiento = true;
                                $this->comment('Eliminando movimiento');

                                return $this->procesar_articulo($article);
                            }
                        }
                    }
                }


                if (!$this->solo_informar) {

                    $movimiento->stock_resultante = $stock;
                    $movimiento->save();
                }
            }

        }

        return [
            'se_elimino_movimiento' => $se_elimino_movimiento,
            'addresses' => $addresses,
            'stock' => $stock,
        ];
    }

    function get_addresses($article, $movimientos) {

        $addresses = [];

        if (count($article->addresses) >= 1) {
            
            foreach ($movimientos as $movimiento) {
                
                if (!is_null($movimiento->to_address_id)) {

                    if (!isset($addresses[$movimiento->to_address_id])) {
                        $addresses[$movimiento->to_address_id] = [

                            'address'    => Address::find($movimiento->to_address_id),
                            'amount'    => $movimiento->amount,
                            'movimiento_inicial_id' => $movimiento->id,
                        ];
                    }
                } else if (!is_null($movimiento->from_address_id)) {

                    if (!isset($addresses[$movimiento->from_address_id])) {
                        $addresses[$movimiento->from_address_id] = [

                            'address'    => Address::find($movimiento->from_address_id),
                            'amount'    => $movimiento->amount,
                            'movimiento_inicial_id' => $movimiento->id,
                        ];
                    }

                }
            }

            $this->info('Inicia addresses con:');
            foreach ($addresses as $id => $value) {
                $this->info($value['amount'].' en '. $value['address']->street);
            }
        } else {
            $this->info('No hay addresses');
        }
        $this->info('');
        $this->info('*************************');
        $this->info('');

        return $addresses;
    }

    function get_articles_mal() {

        $articulos_mal = [];
        $articles = Article::where('user_id', env('USER_ID'));

        $article_id = $this->argument('article_id');

        if ($article_id) {
            $articles->where('id', $article_id);
        }
        $articles = $articles->get();

        $this->info(count($articles).' articulos');      

        foreach ($articles as $article) {
            
            $last_stock_movement = StockMovement::where('article_id', $article->id)
                                                ->orderBy('id', 'DESC')
                                                ->first();

            if ($last_stock_movement) {
                $stock_resultante = $last_stock_movement->stock_resultante;
                if ($article->stock != $stock_resultante) {
                    $articulos_mal[] = $article;
                }
            }
        }

        return $articulos_mal;
    }
}
