<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\Afip\AfipImportesResolver;
use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Http\Controllers\Helpers\Afip\LeyendaIsibCabaHelper;
use App\Http\Controllers\Helpers\Afip\CandadoDeFacturaHelper;
use App\Http\Controllers\Helpers\Afip\FacturaDeVentaHelper;
use App\Http\Controllers\Helpers\Afip\TransporteDeFacturaHelper;
use App\Models\AfipTicket;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AfipTicketController extends Controller
{

    function get_importes($sale_id) {
        $sale = Sale::find($sale_id);

        $afip_ticket = $sale->afip_tickets[0];

        $afip_helper = new AfipHelper($afip_ticket);

        $importes = $sale->is_consolidacion_facturacion
            ? AfipImportesResolver::resolve($afip_ticket, $afip_helper)
            : $afip_helper->getImportes();

        $data = [
            'ver'           => 1,
            'fecha'         => date_format($afip_ticket->created_at, 'Y-m-d'),
            'cuit'          => $afip_ticket->cuit_negocio,
            'ptoVta'        => $afip_ticket->punto_venta,
            'tipoCmp'       => $afip_ticket->cbte_tipo,
            'nroCmp'        => $afip_ticket->cbte_numero,
            'importe'       => $afip_ticket->importe_total,
            'moneda'        => $afip_ticket->moneda_id,
            'ctz'           => 1,
            'tipoDocRec'    => AfipHelper::getDocType('Cuit'),
            'nroDocRec'     => $afip_ticket->cuit_cliente,
            'codAut'        => $afip_ticket->cae,
        ];
        $afip_link = 'https://www.afip.gob.ar/fe/qr/?'.base64_encode(json_encode($data));

        return response()->json([
            'importes'          => $importes,
            'afip_qr_link'      => $afip_link,
            /**
             * Leyenda ISIB CABA (Res. 169/AGIP/2026) ya resuelta para el Ticket 2.0: un renglon por
             * parte, [] si este comprobante no la lleva. Clave opcional: un SPA viejo la ignora.
             */
            'leyenda_isib_caba' => LeyendaIsibCabaHelper::partes($afip_ticket),
        ]);
    }

    function problemas_al_facturar() {

        /** Obtiene el usuario autenticado para filtrar ventas propias. */
        $user_id = $this->userId();

        /** Acumula ventas con problemas de facturación sin duplicados. */
        $errores_de_facturacion = [];

        /** Guarda los IDs de ventas ya agregadas para evitar duplicados. */
        $sale_ids_agregados = [];
        
        $afip_tickets_con_errores = AfipTicket::where(function($q) {
                                                $q->whereNull('cae')
                                                    ->orWhere('cae', '');
                                            })
                                            ->whereHas('sale', function($q2) use($user_id) {
                                                $q2->where('user_id', $user_id)
                                                   /**
                                                    * Excluye ventas individuales que ya fueron incluidas en una consolidación
                                                    * de facturación: su comprobante se emite a través de la venta consolidada.
                                                    * Las propias ventas contenedoras (is_consolidacion_facturacion=1) sí
                                                    * deben aparecer aquí si su ticket AFIP tiene problemas.
                                                    */
                                                   ->whereNull('consolidacion_facturacion_id');
                                            })
                                            /*
                                             * address y employee: la tabla de la pestaña "Facturacion" de Alertas
                                             * tiene columnas de sucursal y empleado que quedaban SIEMPRE vacias.
                                             * Eran dos defectos apilados: el front leia `address.stree` (typo) con
                                             * la key del item en ingles (`employee` vs columna `empleado`), y aun
                                             * arreglado eso, este endpoint no cargaba las relaciones. Exploracion
                                             * del modulo Alertas, 3/9/2026.
                                             */
                                            ->with('sale.afip_tickets.afip_observations', 'sale.afip_tickets.afip_errors', 'sale.address', 'sale.employee')
                                            ->orderBy('created_at', 'DESC')
                                            ->get();

        foreach ($afip_tickets_con_errores as $afip_ticket) {

            /** Si la venta ya fue agregada, evita volver a incluirla. */
            if (in_array($afip_ticket->sale_id, $sale_ids_agregados)) {
                continue;
            }

            $errores_de_facturacion[] = $afip_ticket->sale;
            $sale_ids_agregados[] = $afip_ticket->sale_id;
        }

        return response()->json(['models' => $errores_de_facturacion], 200);
    }       

    /**
     * Consulta en ARCA/AFIP un comprobante ya numerado (factura o nota de crédito) y actualiza el ticket local.
     *
     * @param int|string $afip_ticket_id ID del registro `AfipTicket`.
     * @return \Illuminate\Http\JsonResponse Payload con `sale` (modelo venta con relaciones) y `afip_ticket` actualizado.
     *
     * Notas:
     * - Las facturas enlazan la venta en `sale_id`.
     * - Las notas de crédito AFIP suelen tener `sale_id` nulo y enlazan la venta en `sale_nota_credito_id`.
     */
    function consultar_comprobante($afip_ticket_id) {
        if (!FacturaDeVentaHelper::es_id($afip_ticket_id)) {
            return response()->json(null, 404);
        }
        $ticket = AfipTicket::find($afip_ticket_id);
        $sale_id = $ticket ? ($ticket->sale_id ?: $ticket->sale_nota_credito_id) : null;
        if (!$ticket || !$sale_id || !Sale::where('user_id', $this->userId())->where('id', $sale_id)->exists()) {
            return response()->json(null, 404);
        }
        return CandadoDeFacturaHelper::ventas([$sale_id], function () use ($afip_ticket_id, $sale_id) {
            $ticket = AfipTicket::where('id', $afip_ticket_id)->lockForUpdate()->first();
            $venta = Sale::where('user_id', $this->userId())->where('id', $sale_id)->first();
            if (!$ticket || !$venta || (int) ($ticket->sale_id ?: $ticket->sale_nota_credito_id) !== (int) $sale_id) {
                return response()->json(null, 404);
            }
            $emisor = $ticket->afip_information;
            if (!$emisor || (int) $emisor->user_id !== (int) $this->userId() || !$ticket->afip_tipo_comprobante) {
                return response()->json(['message' => 'No se puede verificar la configuración fiscal de ese comprobante. Revisala antes de consultar.'], 422);
            }
            if (trim((string) $ticket->cbte_numero) === '' || !$ticket->cbte_tipo || !$ticket->punto_venta) {
                return response()->json(['message' => 'El intento no tiene un número fiscal completo para consultar. Revisá sus errores de facturación.'], 422);
            }
            if (($ticket->cuit_negocio && preg_replace('/\D/', '', $ticket->cuit_negocio) !== preg_replace('/\D/', '', $emisor->cuit))
                || (int) $ticket->punto_venta !== (int) $emisor->punto_venta) {
                return response()->json(['message' => 'La configuración del emisor no coincide con el comprobante guardado. Revisala antes de consultar.'], 422);
            }
            if (!$ticket->cae && $ticket->firma_datos_fiscales) {
                FacturaDeVentaHelper::fijar_contexto($ticket, FacturaDeVentaHelper::reconstruir_intento($ticket, $this->userId()));
            } else {
                // Un comprobante autorizado conserva su identidad aunque después tenga devoluciones.
                // Se consulta para backfill, con el emisor/tipo ya verificados y fijados para esta llamada.
                $ticket->setRelation('afip_information', $emisor);
                $ticket->setRelation('afip_tipo_comprobante', $ticket->afip_tipo_comprobante);
                if ($ticket->sale_id) {
                    $ticket->setRelation('sale', $venta);
                }
            }
            // Una consulta con CAE sigue ejecutándose: puede reparar IVA/ganancia. La transición
            // fiscal y el saldo son idempotentes dentro del helper, no se omite la lectura remota.
            app(TransporteDeFacturaHelper::class)->consultar($ticket);
            return response()->json([
                'sale' => $this->fullModel('Sale', $sale_id), 'afip_ticket' => $ticket->fresh(),
            ], 200);
        });
    }

    /**
     * Elimina un `AfipTicket` (factura o nota de crédito AFIP) y devuelve la venta asociada actualizada.
     *
     * @param int|string $id ID del ticket a eliminar.
     * @return \Illuminate\Http\JsonResponse Respuesta con `sale` recargada vía `fullModel`.
     */
    function destroy($id) {
        if (!FacturaDeVentaHelper::es_id($id)) {
            return response()->json(null, 404);
        }
        $model = AfipTicket::find($id);

        if (!$model) {
            return response()->json(null, 404);
        }
        $sale_id = $model->sale_id ?: $model->sale_nota_credito_id;
        if (!$sale_id || !Sale::where('user_id', $this->userId())->where('id', $sale_id)->exists()) {
            return response()->json(null, 404);
        }
        return CandadoDeFacturaHelper::ventas([$sale_id], function () use ($id, $sale_id) {
        // Un CAE pudo llegar después de la primera lectura. El borrado comparte exclusión con
        // emisión/conciliación y decide sólo con la fila actual, sin ocultar efectos fiscales.
        $model = AfipTicket::where('id', $id)->lockForUpdate()->first();
        if (!$model || (int) ($model->sale_id ?: $model->sale_nota_credito_id) !== (int) $sale_id
            || !Sale::where('user_id', $this->userId())->where('id', $sale_id)->exists()) {
            return response()->json(null, 404);
        }

        /**
         * 🔴 Bloqueado (decisión de Lucas, bug real en producción con masquito, 11/9/2026): un
         * comprobante con CAE ya fue autorizado por ARCA, y un CAE no se puede "deshacer" borrando
         * la fila local. Al quedar `deleted_at` seteado (el modelo usa SoftDeletes), el ticket se
         * vuelve invisible para el Libro IVA y los dos exportadores de TXT de AFIP -que respetan
         * ese scope, correctamente-, y ARCA queda con un comprobante autorizado que el sistema
         * nunca vuelve a declarar. La única forma correcta de reversarlo es una Nota de Crédito;
         * este método no la genera, solo rechaza el borrado.
         *
         * Mismo criterio de "CAE no vacío" que ya usa ConsolidarFacturacionHelper (líneas ~74-78 y
         * ~366-369): `!empty()`, no `!is_null()` a secas, porque en la práctica el CAE puede llegar
         * como string vacío además de NULL.
         */
        if (!empty($model->cae)) {
            return response()->json([
                'message' => 'No se puede eliminar un comprobante que ya tiene CAE autorizado por ARCA. Para anularlo hay que emitir una Nota de Crédito.',
            ], 422);
        }
        if (trim((string) $model->cbte_numero) !== '' && $model->resultado !== 'R') {
            return response()->json([
                'message' => 'No se puede eliminar un intento numerado cuya emisión no está resuelta. Consultá su resultado en ARCA antes de emitir otro comprobante.',
            ], 422);
        }

        /** ID de venta asociada: factura usa `sale_id`; NC AFIP usa `sale_nota_credito_id`. */
        $sale_id = !is_null($model->sale_id)
            ? $model->sale_id
            : $model->sale_nota_credito_id;
        $model->delete();
        return response()->json(['sale' => $this->fullModel('Sale', $sale_id)], 200);
        });
    }
}
