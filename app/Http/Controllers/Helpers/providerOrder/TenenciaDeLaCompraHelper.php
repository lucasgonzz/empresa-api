<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Http\Controllers\Helpers\ChequeHelper;
use App\Models\Article;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;

/**
 * 🔴 La TENENCIA de la compra a proveedor y de sus hijos (misión tenencia-compra-hermanos,
 * 17/10/2026; continuación de tenencia-compra-show).
 *
 * Facturas, alícuotas, costos extra y descuentos se resolvían por id pelado y ESCRIBÍAN: en una base
 * compartida (`u767360347_empresa`, 51 comercios, ids correlativos) un PUT/DELETE con el id de otro
 * comercio le pisaba o le borraba la fila, y un `store` con el `model_id` de una compra ajena le
 * colgaba filas y le recalculaba el total y la cuenta del proveedor.
 *
 * Criterio, el mismo de cheques y de `ProviderOrderController::compra_del_dueno_o_404()`: lo ajeno,
 * lo inexistente y lo que no es un id se contestan IGUAL (404) y se resuelve ANTES de escribir. El
 * empleado cuenta como su dueño: el `$user_id` que se pasa es `Controller::userId()`.
 *
 * Quién es el dueño de cada cosa:
 *  - compra y factura: su `user_id` (la factura vieja sin `user_id` se resuelve por su compra);
 *  - alícuota, costo extra y descuento: su `user_id` si lo tiene (se marca desde el alta, y es lo
 *    único que dice de quién es uno que todavía no cuelga de nada: el formulario los crea con
 *    `temporal_id` antes de guardar la compra), y si no, el de su padre.
 *
 * PHP 7.4: sin match, nullsafe ni argumentos nombrados.
 */
class TenenciaDeLaCompraHelper
{
    /**
     * El id de la compra si es del dueño (lectura estricta del id), o null.
     *
     * @param  mixed  $valor
     * @param  int  $user_id
     * @return int|null
     */
    static function compra($valor, $user_id)
    {
        return ChequeHelper::id_del_dueno(ProviderOrder::class, $valor, $user_id);
    }

    /**
     * El id de la factura de compra si es del dueño, o null.
     *
     * @param  mixed  $valor
     * @param  int  $user_id
     * @return int|null
     */
    static function factura($valor, $user_id)
    {
        $model = self::hijo(ProviderOrderAfipTicket::class, $valor, $user_id);

        return is_null($model) ? null : (int) $model->id;
    }

    /**
     * La fila hija (factura, alícuota, costo extra o descuento) si es del dueño, o null.
     *
     * @param  string  $clase  ProviderOrderAfipTicket, ProviderOrderAfipTicketIva,
     *                         ProviderOrderExtraCost o ProviderOrderDiscount.
     * @param  mixed  $valor  Lo que llegó en la ruta.
     * @param  int  $user_id
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    static function hijo($clase, $valor, $user_id)
    {
        // Sin dueño no hay nada de nadie (con null sería `where user_id is null`: fallaba abierta).
        if (empty($user_id)) {

            return null;
        }

        $id = ChequeHelper::id_del_pedido($valor);

        if ($id === 0) {

            return null;
        }

        return $clase::where('id', $id)
                        ->where(function ($q) use ($clase, $user_id) {

                            $q->where('user_id', $user_id)
                                ->orWhere(function ($sin_marca) use ($clase, $user_id) {

                                    $sin_marca->whereNull('user_id');

                                    self::cuyo_padre_es_del_dueno($sin_marca, $clase, $user_id);
                                });
                        })
                        ->first();
    }

    /**
     * Condición "cuelga de un padre del dueño", para las filas sin `user_id`.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @param  string  $clase
     * @param  int  $user_id
     * @return void
     */
    protected static function cuyo_padre_es_del_dueno($q, $clase, $user_id)
    {
        if ($clase === ProviderOrderAfipTicketIva::class) {

            $q->whereIn('provider_order_afip_ticket_id', function ($sub) use ($user_id) {

                $sub->select('id')->from('provider_order_afip_tickets')
                    ->where(function ($dueno) use ($user_id) {

                        $dueno->where('user_id', $user_id)
                              ->orWhere(function ($sin_marca) use ($user_id) {

                                  $sin_marca->whereNull('user_id')
                                            ->whereIn('provider_order_id', function ($compras) use ($user_id) {
                                                $compras->select('id')->from('provider_orders')->where('user_id', $user_id);
                                            });
                              });
                    });
            });

            return;
        }

        $q->whereIn('provider_order_id', function ($compras) use ($user_id) {

            $compras->select('id')->from('provider_orders')->where('user_id', $user_id);
        });
    }

    /**
     * El `provider_id` que llega en el cuerpo de la compra es de otro comercio o no existe. Vacío o
     * 0 (compra sin proveedor) pasa: es otra validación, no de tenencia. Incluye borrados: una
     * compra vieja puede seguir apuntando a un proveedor dado de baja.
     *
     * @param  mixed  $provider_id
     * @param  int  $user_id
     * @return bool
     */
    static function proveedor_ajeno($provider_id, $user_id)
    {
        if (is_null($provider_id) || $provider_id === '' || $provider_id === 0 || $provider_id === '0') {

            return false;
        }

        return is_null(ChequeHelper::id_del_dueno(Provider::class, $provider_id, $user_id, true));
    }

    /**
     * Algún renglón del cuerpo de la compra apunta a un artículo de otro comercio o que no existe.
     * Los renglones sin `id` no se miran.
     *
     * @param  mixed  $articles  El arreglo `articles` del request.
     * @param  int  $user_id
     * @return bool
     */
    static function algun_articulo_ajeno($articles, $user_id)
    {
        if (!is_array($articles) || count($articles) === 0) {

            return false;
        }

        $ids = [];

        foreach ($articles as $article) {

            $id = is_array($article) ? ($article['id'] ?? null) : (is_object($article) ? ($article->id ?? null) : null);

            if (is_null($id) || $id === '') {

                continue;
            }

            $id = ChequeHelper::id_del_pedido($id);

            // Un id que no es un id: no es de nadie.
            if ($id === 0) {

                return true;
            }

            $ids[$id] = $id;
        }

        if (count($ids) === 0) {

            return false;
        }

        $propios = 0;

        foreach (array_chunk(array_values($ids), 1000) as $lote) {

            $propios += Article::withTrashed()->where('user_id', $user_id)->whereIn('id', $lote)->count();
        }

        return $propios !== count($ids);
    }

    /**
     * El `model_id` (o `provider_order_id`) del cuerpo apunta a una compra de otro comercio o que no
     * existe. Vacío pasa: es la fila todavía sin padre (alta con `temporal_id`).
     *
     * @param  mixed  $valor
     * @param  int  $user_id
     * @return bool
     */
    static function compra_ajena_en_cuerpo($valor, $user_id)
    {
        if (is_null($valor) || $valor === '') {

            return false;
        }

        return is_null(self::compra($valor, $user_id));
    }

    /**
     * Lo mismo para una factura de compra (el `model_id` de una alícuota).
     *
     * @param  mixed  $valor
     * @param  int  $user_id
     * @return bool
     */
    static function factura_ajena_en_cuerpo($valor, $user_id)
    {
        if (is_null($valor) || $valor === '') {

            return false;
        }

        return is_null(self::factura($valor, $user_id));
    }

    /**
     * El 404 que se devuelve en lugar de confirmar qué hay del otro lado.
     *
     * @param  string  $que  "La factura", "El costo extra"…
     * @return \Illuminate\Http\JsonResponse
     */
    static function no_encontrado($que)
    {
        return response()->json(['message' => $que . ' no existe o no es de tu cuenta.'], 404);
    }
}
