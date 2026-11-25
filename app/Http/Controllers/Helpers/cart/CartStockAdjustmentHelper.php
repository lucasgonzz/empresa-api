<?php

namespace App\Http\Controllers\Helpers\cart;

use App\Http\Controllers\Helpers\MailNotificationConfigHelper;
use App\Jobs\ProcessSendCartAdjustedMail;
use App\Models\Buyer;
use App\Models\OnlineConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Ajusta los carritos activos de la tienda cuando cambia el stock de un artículo (misión carritos-ajuste-stock).
 *
 * Por cada renglón de un carrito activo que tenga el artículo:
 *  - si lo que el comprador tiene supera lo que hoy se puede comprar (tope de `StoreStockLimitHelper`), baja la
 *    cantidad al tope y guarda la diferencia en `amount_insuficiente` (RECORTE, con mail al comprador);
 *  - si ya había un faltante, la casilla `completar_al_reponer` sigue marcada y ahora entra más, sube la cantidad
 *    hasta lo pedido originalmente o hasta el tope (RESTAURACIÓN, con mail);
 *  - en cualquier otro caso no escribe ni avisa (un aviso sin cambio de estado era el defecto del código viejo).
 *
 * Cada carrito se evalúa contra el stock completo, sin repartirlo entre carritos. No crea ningún `Message`.
 * Un fallo del ajuste nunca puede romper el movimiento de stock que lo disparó: todo va en try/catch.
 */
class CartStockAdjustmentHelper
{
    /**
     * Días sin actividad a partir de los cuales un carrito deja de considerarse activo. Mismo valor que
     * `ACTIVE_CART_DAYS` de tienda-api (`CartStockNoticeHelper`).
     *
     * @var int
     */
    const ACTIVE_CART_DAYS = 15;

    /**
     * Ajusta todos los carritos activos que tengan el artículo, tras un movimiento de stock.
     *
     * @param \App\Models\Article $article Artículo cuyo stock acaba de moverse.
     * @return void
     */
    public static function adjust_carts_for_article($article)
    {
        try {
            self::run($article);
        } catch (\Throwable $e) {
            // El stock ya se movió: el ajuste de carritos no puede deshacerlo ni romper la venta.
            Log::error('CartStockAdjustmentHelper: no se pudieron ajustar los carritos del articulo', [
                'article_id' => isset($article->id) ? $article->id : null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cuerpo del ajuste (sin el try/catch de seguridad).
     *
     * @param \App\Models\Article $article
     * @return void
     */
    private static function run($article)
    {
        // ¿La base ya tiene la casilla del renglón? (puede faltar si el código se subió antes de migrar).
        $has_checkbox_column = Schema::hasColumn('article_cart', 'completar_al_reponer');

        // 1) Renglones de carritos activos: sin pedido, con actividad en la ventana y del mismo comercio.
        //    Un renglón huérfano (carrito borrado, no hay FK) no entra por el JOIN.
        $columns = ['ac.id', 'ac.variant_id', 'ac.amount', 'ac.amount_insuficiente', 'c.buyer_id'];
        if ($has_checkbox_column) {
            $columns[] = 'ac.completar_al_reponer';
        }

        $lines = DB::table('article_cart as ac')
                    ->join('carts as c', 'c.id', '=', 'ac.cart_id')
                    ->where('ac.article_id', $article->id)
                    ->whereNull('c.order_id')
                    ->where('c.user_id', $article->user_id)
                    ->where('c.updated_at', '>=', now()->subDays(self::ACTIVE_CART_DAYS))
                    ->get($columns);

        // Sin renglones activos no hace falta ni leer la configuración.
        if ($lines->isEmpty()) {
            return;
        }

        // 2) Configuración del dueño. Con la opción apagada los carritos no se tocan. Si la columna todavía
        //    no existe se ajusta (la opción nace prendida).
        $configuration = OnlineConfiguration::where('user_id', $article->user_id)->first();

        if (is_null($configuration)) {
            return;
        }

        if (Schema::hasColumn('online_configurations', 'ajustar_carritos_por_stock')
            && !(bool) $configuration->ajustar_carritos_por_stock) {
            return;
        }

        // Compradores de los carritos (una sola consulta) y gate del mail (se resuelve una vez, perezoso).
        $buyers = Buyer::whereIn('id', $lines->pluck('buyer_id')->filter()->unique()->all())->get()->keyBy('id');
        $send_mail = null;

        // Topes ya calculados en este movimiento, por variante (el tope sólo depende del renglón).
        $limits = [];

        foreach ($lines as $line) {
            $variant_key = is_null($line->variant_id) ? 'none' : (string) $line->variant_id;

            if (!array_key_exists($variant_key, $limits)) {
                $limits[$variant_key] = StoreStockLimitHelper::limit_for_line($configuration, $article->id, $line->variant_id);
            }

            $change = self::calculate_change($line, $limits[$variant_key], $has_checkbox_column);

            // 3) Sin cambio de estado: ni escritura ni mail.
            if (is_null($change)) {
                continue;
            }

            // Escritura directa por id de renglón: no toca carts.updated_at, un ajuste del ERP no cuenta como
            // actividad del comprador (si no, los carritos abandonados parecerían activos para siempre).
            DB::table('article_cart')->where('id', $line->id)->update($change['update']);

            // 4) Mail: sólo si el comercio lo tiene prendido y el carrito es de un comprador con mail válido.
            if (is_null($send_mail)) {
                $send_mail = MailNotificationConfigHelper::avisarAjusteCarrito($article->user_id);
            }

            if (!$send_mail || is_null($line->buyer_id) || !isset($buyers[$line->buyer_id])) {
                continue;
            }

            $buyer = $buyers[$line->buyer_id];
            $email = trim((string) $buyer->email);

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            ProcessSendCartAdjustedMail::dispatch(
                $article->user_id,
                $email,
                $buyer->name,
                $article->id,
                $line->variant_id,
                $change['type'],
                $change['requested_amount'],
                $change['new_amount']
            );
        }
    }

    /**
     * Decide qué le pasa a un renglón con el tope actual.
     *
     * @param object $line Renglón (amount, amount_insuficiente y, si existe, completar_al_reponer).
     * @param float|null $limit Tope del renglón (null = la tienda no lo limita).
     * @param bool $has_checkbox_column Si la base tiene article_cart.completar_al_reponer.
     * @return array|null null si no hay nada que escribir; si no, ['type', 'requested_amount', 'new_amount', 'update'].
     */
    private static function calculate_change($line, $limit, $has_checkbox_column)
    {
        // Sin tope la tienda deja comprar cualquier cantidad: no se toca nada.
        if (is_null($limit)) {
            return null;
        }

        // Cantidad actual, faltante actual y cantidad que el comprador pidió originalmente (X).
        $amount = (float) $line->amount;
        $missing = is_null($line->amount_insuficiente) ? 0.0 : (float) $line->amount_insuficiente;
        $requested = $amount + $missing;

        // Casilla del comprador (sin columna, o renglón de una tienda vieja: marcada).
        $checkbox = $has_checkbox_column ? (int) $line->completar_al_reponer === 1 : true;

        // Un carrito de cantidades enteras sólo admite tope entero: 2.5 disponibles son 2 unidades.
        $effective_limit = (round($requested, 4) == floor($requested)) ? floor($limit) : (float) $limit;

        if ($amount > $effective_limit + 0.00001) {
            // RECORTE: lo que tiene supera lo que se puede comprar.
            $new_amount = $effective_limit;
            $type = 'recortado';
            // Un faltante nuevo (el renglón no tenía) arranca con la casilla marcada.
            $new_checkbox = $missing > 0 ? $checkbox : true;
        } elseif ($missing > 0 && $checkbox && $effective_limit > $amount + 0.00001) {
            // RESTAURACIÓN (total o parcial): entró stock y el comprador quiere que se le complete.
            $new_amount = min($requested, $effective_limit);
            $type = 'restaurado';
            $new_checkbox = true;
        } else {
            return null;
        }

        $update = [
            'amount'              => $new_amount,
            'amount_insuficiente' => $requested - $new_amount,
        ];

        if ($has_checkbox_column) {
            $update['completar_al_reponer'] = $new_checkbox ? 1 : 0;
        }

        return [
            'type'             => $type,
            'requested_amount' => $requested,
            'new_amount'       => $new_amount,
            'update'           => $update,
        ];
    }
}
