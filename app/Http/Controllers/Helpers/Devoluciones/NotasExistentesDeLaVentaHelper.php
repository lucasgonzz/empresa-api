<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Models\CurrentAcount;
use App\Models\Sale;

/**
 * Detecta que una devolución nueva vuelve a devolver unidades que una nota de crédito de la MISMA
 * venta ya devolvió, para que el usuario decida antes de crear una segunda nota (misión
 * nc-aviso-existente-y-sin-cliente, 8/10/2026).
 *
 * 🔴 Por qué existe: el tope de unidades de ValidarDevolucionHelper lee el libro de movimientos de
 * stock y solo corre con "Regresar stock" o "Actualizar unidades devueltas" tildados. Una
 * devolución de solo plata no tiene ningún chequeo: si la venta se anuló con una nota en negro y
 * después se factura, devolver de nuevo para "anular la factura" duplica el crédito del cliente
 * sin que nada avise. Acá se mira lo que las notas realmente guardan —sus renglones—, así que
 * funciona aunque no se devuelva stock.
 *
 * No bloquea: devuelve las notas y la pantalla ofrece facturar la existente, crear otra igual o
 * cancelar. El bloqueo duro de stock sigue siendo ValidarDevolucionHelper.
 */
class NotasExistentesDeLaVentaHelper {

    /**
     * Notas de crédito de la venta que ya devolvieron las unidades que se piden de nuevo.
     *
     * Un artículo "choca" cuando ya tiene unidades devueltas en alguna nota y lo ya devuelto más lo
     * que se pide supera lo vendido. Una devolución parcial legítima (vendidas 10, devueltas 3,
     * se piden 5) no choca. Se compara por artículo, sin mirar variantes: el renglón de la nota
     * no guarda la variante.
     *
     * @param  int|null  $sale_id
     * @param  array     $items  Renglones del request (`is_article`, `id`, `unidades_devueltas`).
     * @return array     Lista de notas (vacía si no hay choque). Ver `describir_nota()` por su forma.
     */
    static function buscar($sale_id, $items) {

        if (is_null($sale_id) || !is_array($items)) {
            return [];
        }

        $sale = Sale::withTrashed()->with('articles')->find($sale_id);

        if (is_null($sale)) {
            return [];
        }

        $pedidas = Self::unidades_pedidas($items);

        if (!count($pedidas)) {
            return [];
        }

        $notas = CurrentAcount::where('sale_id', $sale->id)
                    ->where('status', 'nota_credito')
                    ->with('articles', 'afip_ticket')
                    ->orderBy('id')
                    ->get();

        if ($notas->isEmpty()) {
            return [];
        }

        $ya_devueltas = [];

        foreach ($notas as $nota) {
            foreach ($nota->articles as $article) {
                $ya_devueltas[$article->id] = (isset($ya_devueltas[$article->id]) ? $ya_devueltas[$article->id] : 0) + (float) $article->pivot->amount;
            }
        }

        $que_chocan = [];

        foreach ($pedidas as $article_id => $unidades) {

            $vendidas = Self::unidades_vendidas($sale, $article_id);

            if (is_null($vendidas) || empty($ya_devueltas[$article_id])) {
                continue;
            }

            if ($ya_devueltas[$article_id] + $unidades > $vendidas + 0.0001) {
                $que_chocan[] = $article_id;
            }
        }

        if (!count($que_chocan)) {
            return [];
        }

        $facturas = FacturarNotaCreditoExistenteHelper::facturas_de_la_venta($sale->id);

        $resultado = [];

        foreach ($notas as $nota) {

            $unidades_de_la_nota = [];

            foreach ($nota->articles as $article) {
                if (in_array($article->id, $que_chocan) && (float) $article->pivot->amount > 0) {
                    $unidades_de_la_nota[] = [
                        'article_id' => $article->id,
                        'name'       => $article->name,
                        'unidades'   => (float) $article->pivot->amount,
                    ];
                }
            }

            if (count($unidades_de_la_nota)) {
                $resultado[] = Self::describir_nota($nota, $unidades_de_la_nota, $facturas);
            }
        }

        return $resultado;
    }

    /**
     * Forma con la que viaja una nota al modal de la pantalla.
     *
     * @param  \App\Models\CurrentAcount            $nota
     * @param  array                                $unidades  Renglones que chocan.
     * @param  \Illuminate\Support\Collection       $facturas  Facturas autorizadas de la venta.
     * @return array
     */
    static function describir_nota($nota, $unidades, $facturas) {

        $ticket = $nota->afip_ticket;

        $facturada = !is_null($ticket) && !empty($ticket->cae);

        $pendiente_en_arca = !$facturada && !is_null($ticket) && !empty($ticket->cbte_numero);

        // Las facturas sobre las que esta nota todavía cabe (no supera el total de la factura).
        $facturas_posibles = [];

        if (!$facturada && !$pendiente_en_arca) {
            foreach ($facturas as $factura) {
                try {
                    FacturarNotaCreditoExistenteHelper::exigir_que_el_total_cierre($nota, $factura);
                    $facturas_posibles[] = [
                        'id'            => $factura->id,
                        'cbte_numero'   => $factura->cbte_numero,
                        'cbte_tipo'     => $factura->cbte_tipo,
                        'importe_total' => $factura->importe_total,
                    ];
                } catch (NotaCreditoNoFacturableException $e) {
                    // Esa factura ya no admite otra nota: no se ofrece.
                }
            }
        }

        return [
            'id'                => $nota->id,
            'num_receipt'       => $nota->num_receipt,
            'detalle'           => $nota->detalle,
            'haber'             => $nota->haber,
            'created_at'        => !is_null($nota->created_at) ? $nota->created_at->format('Y-m-d H:i:s') : null,
            'unidades'          => $unidades,
            'facturada'         => $facturada,
            'cbte_numero'       => !is_null($ticket) ? $ticket->cbte_numero : null,
            'pendiente_en_arca' => $pendiente_en_arca,
            'puede_facturarse'  => count($facturas_posibles) > 0,
            'facturas'          => $facturas_posibles,
        ];
    }

    /**
     * Unidades pedidas por artículo (suma si el mismo artículo viene en más de un renglón).
     *
     * @param  array  $items
     * @return array  article_id => unidades (solo mayores a cero).
     */
    static function unidades_pedidas($items) {

        $pedidas = [];

        foreach ($items as $item) {

            if (!isset($item['is_article']) || !isset($item['id'])) {
                continue;
            }

            $unidades = ValidarDevolucionHelper::unidades_del_item($item);

            if ($unidades <= 0) {
                continue;
            }

            $id = (int) $item['id'];

            $pedidas[$id] = (isset($pedidas[$id]) ? $pedidas[$id] : 0) + $unidades;
        }

        return $pedidas;
    }

    /**
     * Unidades vendidas de un artículo en la venta (todos sus renglones y variantes). Null si el
     * artículo no está en la venta: una nota de monto libre no tiene contra qué compararse.
     *
     * @param  \App\Models\Sale  $sale
     * @param  int               $article_id
     * @return float|null
     */
    static function unidades_vendidas($sale, $article_id) {

        $total = null;

        foreach ($sale->articles as $article) {
            if ($article->id == $article_id) {
                $total = (float) $total + (float) $article->pivot->amount;
            }
        }

        return $total;
    }
}
