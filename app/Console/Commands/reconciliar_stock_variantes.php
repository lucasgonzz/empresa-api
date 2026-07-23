<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;

/**
 * Comando de reconciliación defensiva del stock de variantes (prompt 521).
 *
 * Fuente única de verdad: `address_article_variant.amount` (stock de una variante en un
 * depósito puntual). A partir de ahí este comando recalcula los campos DERIVADOS:
 *   - `article_variants.stock`            = suma de sus depósitos.
 *   - `articles.addresses` (pivot amount) = suma por depósito de las variantes del artículo.
 *   - `articles.stock`                    = suma de los depósitos recalculados del artículo.
 *
 * Es puramente defensivo: no genera StockMovement (esos ya se generan en el flujo normal
 * vía UpdateVariantsStockHelper -> StockMovementController -> CheckVariants). Solo corrige
 * los derivados si por algún motivo quedaron desincronizados (bypass viejo, bug, migración
 * de datos, etc). Es idempotente: correrlo N veces con los mismos datos de depósito da
 * siempre el mismo resultado.
 *
 * No reemplaza ni modifica `set_article_address_stock_from_variants` (que hace algo similar
 * pero recorriendo TODOS los depósitos del usuario, incluso los que la variante no usa).
 * Este comando es más acotado: solo toca los depósitos que las variantes realmente tienen
 * asociados.
 */
class reconciliar_stock_variantes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reconciliar_stock_variantes {user_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalcula article_variants.stock y articles.stock (con sus depositos) a partir de address_article_variant.amount, sin generar movimientos.';

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
     * Recorre los artículos con variantes del usuario indicado y recalcula los derivados
     * de stock (variante -> artículo padre) a partir de los depósitos por variante.
     *
     * @return int
     */
    public function handle()
    {
        // Id del usuario (owner o empleado, igual que set_article_address_stock_from_variants)
        // cuyos artículos con variantes se van a reconciliar.
        $user_id = $this->argument('user_id');

        // Solo artículos que tienen variantes: si no tiene variantes, su stock no es derivado
        // y no corresponde tocarlo desde este comando.
        $articles = Article::where('user_id', $user_id)->get();

        // Cantidad de artículos efectivamente reconciliados, para el resumen final.
        $reconciliados = 0;

        foreach ($articles as $article) {

            // Se carga fresco (con sus variantes y los depósitos de cada variante) para
            // asegurar que el pivot 'amount' que se lee sea el vigente en DB.
            $article->load('article_variants.addresses');

            if (count($article->article_variants) < 1) {
                continue;
            }

            // Acumulador de stock por depósito para el artículo padre: address_id => amount.
            // Se arma sumando lo que cada variante tiene en cada depósito.
            $stock_por_deposito = [];

            foreach ($article->article_variants as $variant) {

                // Stock derivado de la variante: suma de sus depósitos (fuente única de verdad).
                $stock_variante = 0;

                foreach ($variant->addresses as $variant_address) {

                    $amount = (float)$variant_address->pivot->amount;

                    $stock_variante += $amount;

                    if (!array_key_exists($variant_address->id, $stock_por_deposito)) {
                        $stock_por_deposito[$variant_address->id] = 0;
                    }

                    $stock_por_deposito[$variant_address->id] += $amount;
                }

                // Se escribe el derivado de la variante directo (sin movimiento, sin tocar
                // updated_at) solo si cambió, para no generar ruido innecesario en DB.
                if ((float)$variant->stock !== $stock_variante) {
                    $variant->stock = $stock_variante;
                    $variant->timestamps = false;
                    $variant->save();
                }
            }

            // Se resincronizan los depósitos del artículo padre con lo recalculado a partir
            // de sus variantes (reemplaza el pivot completo, igual que hace el comando
            // existente set_article_address_stock_from_variants).
            $article->addresses()->sync([]);

            // Stock total del artículo padre: suma de los depósitos recalculados.
            $stock_articulo = 0;

            foreach ($stock_por_deposito as $address_id => $amount) {

                $article->addresses()->attach($address_id, [
                    'amount' => $amount,
                ]);

                $stock_articulo += $amount;
            }

            $article->stock = $stock_articulo;
            $article->timestamps = false;
            $article->save();

            $reconciliados++;
        }

        $this->info('Reconciliacion terminada. Articulos con variantes procesados: '.$reconciliados);

        return 0;
    }
}
