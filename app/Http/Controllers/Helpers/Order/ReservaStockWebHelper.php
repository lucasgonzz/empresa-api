<?php

namespace App\Http\Controllers\Helpers\Order;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\PromocionVinoteca;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Disponibilidad comprometida por pedidos: nunca se guarda sobre el stock físico. */
class ReservaStockWebHelper
{
    public static function hay_esquema()
    {
        return Schema::hasTable('order_stock_reservations')
            && Schema::hasColumn('orders', 'reserva_stock_web_egreso')
            && Schema::hasColumn('online_configurations', 'reservar_stock_pedidos_web');
    }

    public static function bloquear_configuracion($user_id)
    {
        if (!self::hay_esquema()) {
            return null;
        }
        // Es el primer lock de TODOS los escritores de reservas, incluido apagar la opción.
        return OnlineConfiguration::where('user_id', $user_id)->orderBy('id')->lockForUpdate()->first();
    }

    public static function comprobar_tienda($url, $user_id)
    {
        $partes = parse_url($url);
        if (!$partes || empty($partes['host']) || !in_array($partes['scheme'] ?? '', ['https', 'http'], true)
            || !empty($partes['user']) || !empty($partes['pass']) || !empty($partes['query']) || !empty($partes['fragment'])) {
            throw ValidationException::withMessages(['reservar_stock_pedidos_web' => 'La tienda todavía no tiene verificada la compatibilidad de reservas.']);
        }
        // La URL solo la carga el operador mediante el comando. Nunca se acepta del formulario.
        try {
            $respuesta = Http::timeout(5)->withoutRedirecting()->acceptJson()
                ->get(rtrim($url, '/').'/api/commerce/reserva-stock-capability/'.(int) $user_id);
            if (!$respuesta->successful() || (int) $respuesta->json('contract') !== 1
                || (int) $respuesta->json('commerce_id') !== (int) $user_id) {
                throw new \RuntimeException('Contrato no disponible.');
            }
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['reservar_stock_pedidos_web' => 'La tienda todavía no tiene verificada la compatibilidad de reservas. Actualizá ambas versiones antes de activar.']);
        }
    }

    public static function apagar($user_id)
    {
        DB::table('order_stock_reservations')->where('user_id', $user_id)->delete();
        // Lo consumido ya es físico: apagar no devuelve ventas ni egresos confirmados.
        Order::where('user_id', $user_id)->where('reserva_stock_web_consumida', false)
            ->update(['reserva_stock_web' => false]);
    }

    public static function participa($order)
    {
        return self::hay_esquema() && (bool) $order->reserva_stock_web && !(bool) $order->reserva_stock_web_consumida;
    }

    public static function cambia_mercaderia($order, $request)
    {
        if ($request->has('address_id') && (int) $request->address_id !== (int) $order->address_id) {
            return true;
        }
        if (!is_array($request->articles)) {
            return false;
        }
        $antes = [];
        $variantes_previas = [];
        foreach ($order->articles as $article) {
            $clave = $article->id.':'.(int) $article->pivot->variant_id;
            $antes[$clave] = ($antes[$clave] ?? 0) + (float) $article->pivot->amount;
            $variantes_previas[$article->id][(int) $article->pivot->variant_id] = true;
        }
        $despues = [];
        foreach ($request->articles as $article) {
            $id = $article['id'] ?? 0;
            $variante = $article['pivot']['variant_id'] ?? null;
            // Misma compatibilidad de adjuntar_renglones(): una pantalla vieja puede omitir
            // variante si el artículo tiene un único renglón; conservarla no es una edición.
            if (is_null($variante) && isset($variantes_previas[$id]) && count($variantes_previas[$id]) === 1) {
                $variante = key($variantes_previas[$id]);
            }
            $clave = $id.':'.(int) $variante;
            $despues[$clave] = ($despues[$clave] ?? 0) + (float) ($article['pivot']['amount'] ?? 0);
        }
        ksort($antes);
        ksort($despues);
        return $antes !== $despues;
    }

    public static function deposito($user_id, $address_id)
    {
        $elegido = Address::sucursales_de($user_id)->where('id', $address_id)->first();
        if ($address_id && !$elegido) {
            throw ValidationException::withMessages(['address_id' => 'El depósito elegido no existe o no pertenece a tu comercio.']);
        }
        $principal = $elegido ?: Address::principal_de($user_id);
        return $principal ? (int) $principal->id : null;
    }

    public static function sincronizar_pedido($order)
    {
        if (!self::participa($order)) {
            return;
        }
        $renglones = [];
        foreach ($order->articles as $article) {
            if (!(bool) ($article->pivot->stock_a_consultar ?? false)) {
                self::agregar($renglones, $article->id, $article->pivot->variant_id, null, $article->pivot->amount);
            }
        }
        foreach (ComboEsquemaHelper::combos_del_pedido($order) as $combo) {
            foreach ($combo->articles as $article) {
                self::agregar($renglones, $article->id, null, null, (float) $article->pivot->amount * (float) $combo->pivot->amount);
            }
        }
        foreach ($order->promocion_vinotecas as $promo) {
            self::agregar($renglones, null, null, $promo->id, $promo->pivot->amount);
        }
        $order->address_id = self::deposito($order->user_id, $order->address_id);
        $order->save();
        self::reemplazar($order, $renglones, $order->address_id);
    }

    private static function agregar(&$renglones, $article_id, $variant_id, $promo_id, $amount)
    {
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
            throw ValidationException::withMessages(['articles' => 'La cantidad del pedido debe ser un número mayor o igual a cero.']);
        }
        $variant_id = $variant_id ? (int) $variant_id : null;
        $clave = $promo_id ? 'p:'.$promo_id : 'a:'.$article_id.':'.($variant_id ?: 0);
        if (!isset($renglones[$clave])) {
            $renglones[$clave] = ['article_id' => $article_id, 'article_variant_id' => $variant_id, 'promocion_vinoteca_id' => $promo_id, 'amount' => 0];
        }
        $renglones[$clave]['amount'] += (float) $amount;
    }

    private static function reemplazar($order, $renglones, $address_id)
    {
        ksort($renglones);
        $nuevas = [];
        foreach ($renglones as $renglon) {
            if ($renglon['amount'] <= 0) {
                continue;
            }
            $fisico = self::stock_fisico($order->user_id, $renglon, $address_id);
            // null significa sin control de stock, no una unidad para comprometer.
            if (is_null($fisico['amount'])) {
                continue;
            }
            $query = DB::table('order_stock_reservations')->where('user_id', $order->user_id)
                ->where('order_id', '<>', $order->id);
            if ($renglon['promocion_vinoteca_id']) {
                $query->where('promocion_vinoteca_id', $renglon['promocion_vinoteca_id']);
            } else {
                $query->where('article_id', $renglon['article_id'])
                    ->where('article_variant_id', $renglon['article_variant_id']);
                if ($fisico['por_deposito']) {
                    $query->where('address_id', $address_id);
                }
            }
            // No SUM SQL de snapshot: estas son lecturas actuales después de esperar el lock.
            $comprometido = $query->orderBy('id')->lockForUpdate()->get()->sum('amount');
            if ($renglon['amount'] > max(0, (float) $fisico['amount'] - (float) $comprometido) + 0.00001) {
                throw ValidationException::withMessages(['articles' => 'No alcanza el stock disponible para reservar las cantidades de este pedido.']);
            }
            $nuevas[] = array_merge($renglon, ['user_id' => $order->user_id, 'order_id' => $order->id,
                'address_id' => $renglon['promocion_vinoteca_id'] ? null : $address_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        self::liberar($order->id);
        if ($nuevas) {
            DB::table('order_stock_reservations')->insert($nuevas);
        }
    }

    private static function stock_fisico($user_id, $renglon, $address_id)
    {
        if ($renglon['promocion_vinoteca_id']) {
            $promo = PromocionVinoteca::where('user_id', $user_id)->where('id', $renglon['promocion_vinoteca_id'])->lockForUpdate()->first();
            if (!$promo) {
                throw ValidationException::withMessages(['articles' => 'La promoción ya no está disponible.']);
            }
            return ['amount' => $promo->stock, 'por_deposito' => false];
        }
        $article = Article::where('user_id', $user_id)->where('id', $renglon['article_id'])->lockForUpdate()->first();
        if (!$article) {
            throw ValidationException::withMessages(['articles' => 'El artículo ya no está disponible.']);
        }
        if ($renglon['article_variant_id']) {
            $variant = ArticleVariant::where('article_id', $article->id)->where('id', $renglon['article_variant_id'])->lockForUpdate()->first();
            if (!$variant) {
                throw ValidationException::withMessages(['articles' => 'La variante no pertenece al artículo del pedido.']);
            }
            $filas = DB::table('address_article_variant')->where('article_variant_id', $variant->id)->orderBy('id')->lockForUpdate()->get();
            return self::stock_deposito($filas, $address_id, $variant->stock);
        }
        if (ArticleVariant::where('article_id', $article->id)->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['articles' => 'Elegí la variante del artículo antes de reservar.']);
        }
        $filas = DB::table('address_article')->where('article_id', $article->id)->orderBy('id')->lockForUpdate()->get();
        return self::stock_deposito($filas, $address_id, $article->stock);
    }

    private static function stock_deposito($filas, $address_id, $global)
    {
        if ($filas->isEmpty()) {
            return ['amount' => $global, 'por_deposito' => false];
        }
        $fila = $filas->first(function ($fila) use ($address_id) { return (int) $fila->address_id === (int) $address_id; });
        return ['amount' => $fila ? $fila->amount : 0, 'por_deposito' => true];
    }

    public static function liberar($order_id)
    {
        if (self::hay_esquema()) {
            DB::table('order_stock_reservations')->where('order_id', $order_id)->delete();
        }
    }

    public static function antes_de_venta($sale, $request)
    {
        if (!$sale->order_id || !self::hay_esquema()) {
            return;
        }
        $order = Order::where('id', $sale->order_id)->lockForUpdate()->first();
        if (!$order || !self::participa($order)) {
            return;
        }
        $renglones = [];
        $consultas = $order->articles->filter(function ($article) { return (bool) ($article->pivot->stock_a_consultar ?? false); });
        foreach ($request->items ?? [] as $item) {
            $amount = SaleHelper::getAmount($sale, $item);
            if (!empty($item['is_article'])) {
                $variant_id = SaleHelper::getArticleVariantId($item);
                $consultar = $consultas->first(function ($article) use ($item, $variant_id) {
                    return (int) $article->id === (int) $item['id'] && (int) $article->pivot->variant_id === (int) $variant_id;
                });
                if (!$consultar) {
                    self::agregar($renglones, $item['id'], $variant_id, null, $amount);
                }
            } else if (!empty($item['is_combo'])) {
                foreach ($item['articles'] as $component) {
                    self::agregar($renglones, $component['id'], null, null, $amount * (float) $component['pivot']['amount']);
                }
            } else if (!empty($item['is_promocion_vinoteca'])) {
                self::agregar($renglones, null, null, $item['id'], $amount);
            }
        }
        // La guarda histórica de ventas puede reemplazar un depósito borrado. En una reserva
        // nueva el depósito elegido explícitamente tiene que validarse antes de aceptarlo.
        $sale->address_id = self::deposito($sale->user_id, $request->has('address_id') ? $request->address_id : $sale->address_id);
        $sale->save();
        self::reemplazar($order, $renglones, $sale->address_id);
    }

    public static function despues_de_venta($sale)
    {
        if (!$sale->order_id || !self::hay_esquema() || $sale->to_check || $sale->checked || !$sale->discount_stock) {
            return;
        }
        self::liberar($sale->order_id);
        Order::where('id', $sale->order_id)->where('reserva_stock_web', true)->update(['reserva_stock_web_consumida' => true]);
    }

    public static function confirmar_sin_venta($order)
    {
        if (!self::participa($order)) {
            return;
        }
        $filas = DB::table('order_stock_reservations')->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $snapshot = [];
        foreach ($filas as $fila) {
            $dato = (array) $fila;
            self::mover_sin_venta($order, $dato, -1);
            $snapshot[] = $dato;
        }
        $order->reserva_stock_web_egreso = $snapshot;
        $order->reserva_stock_web_consumida = true;
        $order->save();
        self::liberar($order->id);
    }

    public static function cancelar($order)
    {
        if (!self::hay_esquema()) {
            return;
        }
        foreach ($order->reserva_stock_web_egreso ?? [] as $fila) {
            self::mover_sin_venta($order, $fila, 1);
        }
        if ($order->reserva_stock_web_egreso) {
            $order->reserva_stock_web_egreso = [];
            $order->save();
        }
        self::liberar($order->id);
    }

    private static function mover_sin_venta($order, $fila, $sentido)
    {
        if ($fila['promocion_vinoteca_id']) {
            $promo = PromocionVinoteca::where('id', $fila['promocion_vinoteca_id'])->lockForUpdate()->first();
            if ($promo) {
                $promo->stock += $sentido * (float) $fila['amount'];
                $promo->save();
            }
            return;
        }
        $data = ['model_id' => $fila['article_id'], 'article_variant_id' => $fila['article_variant_id'],
            'order_id' => $order->id, 'amount' => $sentido * (float) $fila['amount'],
            'concepto_stock_movement_name' => 'Pedido Online'];
        $data[$sentido < 0 ? 'from_address_id' : 'to_address_id'] = $fila['address_id'];
        (new StockMovementController())->crear($data);
    }
}
