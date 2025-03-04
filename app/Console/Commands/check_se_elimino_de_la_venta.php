<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use Illuminate\Console\Command;

class check_se_elimino_de_la_venta extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_se_elimino_de_la_venta';

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
        $movimientos_con_eliminacion = StockMovement::where('concepto', 'like', 'Se elimino de la venta %')
                        ->where('user_id', 121)
                        ->get();

        $movimientos_faltantes = $movimientos_con_eliminacion->filter(function ($movimiento) {
            $numero_venta = str_replace('Se elimino de la venta ', '', $movimiento->concepto);

            if (!is_null($movimiento->article)) {

                $this->comment($movimiento->article->num.'. Buscando: Venta N° ' . $numero_venta);
            }
            if (!StockMovement::where('concepto', 'Venta N° ' . $numero_venta)->exists()) {
                $this->info('NO EXISTE');
            }
            return !StockMovement::where('concepto', 'Venta N° ' . $numero_venta)->exists();
        });

        $this->info('-------------------');

        foreach ($movimientos_faltantes as $movimientos_faltante) {

            if (!is_null($movimientos_faltante->article)) {

                $this->info($movimientos_faltante->article->num.': '.$movimientos_faltante->concepto);

                $movimientos_faltante->delete();
                $this->info('Se elimino movimiento');
            }
            
        }

        // dd($movimientos_faltantes);
    }
}
