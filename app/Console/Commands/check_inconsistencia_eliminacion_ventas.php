<?php

namespace App\Console\Commands;

use App\Http\Controllers\StockMovementController;
use App\Models\Article;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class check_inconsistencia_eliminacion_ventas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_inconsistencia_eliminacion_ventas';

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
        set_time_limit(0);

        $inconsistencies = [];

        $this->info("Iniciando revisión de inconsistencias...");

        // Consulta optimizada para obtener conteos en una sola operación
        $movements = DB::table('stock_movements')
                    // ->where('article_id', 12559)
                    ->selectRaw("
                        article_id,
                        -- Extraemos el número de venta, asegurando consistencia entre creación y eliminación
                        TRIM(SUBSTRING_INDEX(
                            REPLACE(LOWER(concepto), 'eliminacion de venta', ''),
                            'n°', -1
                        )) as sale_number,
                        -- Contamos los movimientos de eliminación
                        SUM(CASE WHEN LOWER(concepto) LIKE 'eliminacion de venta' THEN 1 ELSE 0 END) as deletion_count,
                        -- Contamos los movimientos de creación
                        SUM(CASE WHEN LOWER(concepto) LIKE 'venta n°%' AND LOWER(concepto) NOT LIKE 'eliminacion de%' THEN 1 ELSE 0 END) as creation_count                    
                        "
                    )
                    ->where('user_id', 121)
                    ->groupBy('article_id', 'sale_number')
                    ->get();



        $this->info("Total artículos con movimientos: " . $movements->groupBy('article_id')->count());

        // Procesar inconsistencias
        foreach ($movements as $movement) {
            if ($movement->deletion_count > $movement->creation_count) {
                $inconsistencies[] = $movement;

                $article = Article::find($movement->article_id);

                if (!is_null($article)) {

                    // $this->info("Artículo num: {$article->num}, venta: {$movement->sale_number}");
                    // $this->comment($movement->deletion_count.' eliminaciones y '.$movement->creation_count.' creaciones');
                }

                // dd($movement);
            }
        }

        // Crear los movimientos faltantes

        $stockController = new StockMovementController();
        $owner = new \stdClass();
        $owner->id = 121;

        foreach ($inconsistencies as $inconsistency) {
            $missingMovements = $inconsistency->deletion_count - $inconsistency->creation_count;

            // Verificar si existe el artículo
            $article = Article::find($inconsistency->article_id);
            if (!$article) {
                // $this->error("Artículo no encontrado: {$inconsistency->article_id}");
                continue;
            }

            // Obtener el primer movimiento como base
            $primer_movimiento = StockMovement::where('article_id', $inconsistency->article_id)
                ->where('concepto', 'Eliminacion de venta N°'.$inconsistency->sale_number)
                // ->whereRaw("TRIM(REPLACE(concepto, 'Venta N° ', '')) = ?", [$inconsistency->concept_base])
                ->first();

            if (!$primer_movimiento) {
                // $this->error("Movimiento base no encontrado para artículo {$inconsistency->article_id} y concepto {$inconsistency->concept_base}");
                continue;
            }



            // Crear movimientos faltantes
            for ($i = 0; $i < $missingMovements; $i++) {
                $amount = $primer_movimiento->amount;

                // Asegurar que el amount sea negativo (movimiento de entrada)
                if ($amount > 0) {
                    $amount = -$amount;
                }

                $request = new \Illuminate\Http\Request();

                // Asignar valores necesarios al request
                $request->merge([
                    'model_id' => $article->id,
                    'concepto' => 'Venta N° '.$inconsistency->sale_number,
                    'amount' => (int)$amount,
                    'sale_id' => $primer_movimiento->sale_id,
                ]);

                $this->info('Se creo movimiento para');
                $this->comment('article num: '.$article->num);
                $this->comment('sale num: '.$inconsistency->sale_number);
                $this->info($amount);
                $this->info('');

                // Llamar al método store del controlador
                try {
                    $stockController->store($request, false, $owner, null, $i * 10);
                } catch (\Exception $e) {
                    $this->error("Error al crear movimiento para artículo {$article->id}: " . $e->getMessage());
                    continue 2; // Saltar al siguiente artículo en caso de error
                }
            }

            // $this->info("Se crearon {$missingMovements} movimientos para el artículo {$article->id}.");
            

        }
        $this->info('Revisión completada.');
    }
}
