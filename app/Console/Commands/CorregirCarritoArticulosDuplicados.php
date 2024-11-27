<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Cart;
use Illuminate\Support\Facades\DB;

class CorregirCarritoArticulosDuplicados extends Command
{
    /**
     * Nombre y descripción del comando.
     *
     * @var string
     */
    protected $signature = 'carritos:corregir-duplicados';

    protected $description = 'Corrige artículos duplicados en los carritos consolidando sus cantidades';

    /**
     * Ejecuta el comando.
     *
     * @return int
     */
    public function handle()
    {
        // Obtener todos los carritos
        $carritos = Cart::where('user_id', 188)
                        ->with('articles')->get();

        foreach ($carritos as $carrito) {
            // Agrupar los artículos por ID y detectar duplicados
            $articulosAgrupados = $carrito->articles
                ->groupBy('id')
                ->filter(function ($grupo) {
                    return $grupo->count() > 1; // Solo procesar duplicados
                });

            foreach ($articulosAgrupados as $articuloId => $articulosDuplicados) {
                
                // Obtener todas las relaciones duplicadas del artículo con este carrito
                $relacionesDuplicadas = $articulosDuplicados->pluck('pivot.id');


                // Mantener solo una relación (la primera)
                $relacionAPreservar = $relacionesDuplicadas->shift();
                $this->info('relacionesDuplicadas: '.$relacionesDuplicadas);

                // Eliminar las relaciones duplicadas restantes
                // DB::table('article_cart')
                //     ->where('cart_id', $carrito->id)
                //     ->where('article_id', $articuloId)
                //     ->whereIn('id', $relacionesDuplicadas)
                //     ->delete();

                $this->info('Se corrigio carrito de '.$carrito->buyer_id);
            }

        }

        $this->info('Los artículos duplicados han sido corregidos en los carritos.');
        return Command::SUCCESS;
    }
}
