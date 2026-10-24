<?php

namespace App\Http\Controllers;

use App\Exports\NotasCreditoFullExport;
use App\Http\Controllers\Helpers\Devoluciones\FacturarNotaCreditoExistenteHelper;
use App\Http\Controllers\Helpers\Devoluciones\NotaCreditoNoFacturableException;
use App\Http\Controllers\Helpers\ListadoPorFechaPaginadoHelper;
use App\Models\CurrentAcount;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class NotaCreditoController extends Controller
{
    /**
     * Lista notas de crédito del usuario con filtros opcionales por rango de fechas de creación.
     *
     * Con `per_page` en la query string (modo opt-in, ver `ListadoPorFechaPaginadoHelper`) responde UNA
     * página del día o del rango, con `models` como paginador de Laravel. Sin `per_page` responde el
     * array entero de siempre. El Excel (`notas_credito_query`) no pasa por acá y sigue trayendo todo.
     *
     * @param \Illuminate\Http\Request $request
     * @param string|null $from_date Fecha desde (Y-m-d); null sin filtrar por fecha.
     * @param string|null $until_date Fecha hasta (Y-m-d); null filtra solo el día from_date cuando from_date viene informado.
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $from_date = null, $until_date = null)
    {
        // Las relaciones viven en CurrentAcount::scopeConRelacionesDeNotaCredito, porque las usa
        // también el buscador general (modo Historico). Historia de lo que carga:
        // - 'client' (grupo 332, 4/8/2026): el front lee la relacion embebida en vez de buscar en
        //   el store, que no descarga el catalogo completo de clientes.
        // - 'provider' (misión devoluciones-compras-y-rediseno, 1/10/2026): una NC a proveedor
        //   (devolución de compra) no tiene cliente, y el listado tiene que poder mostrar a quién se
        //   le hizo. Aditivo: el front que no lo lee no se entera.
        // - 'sale.afip_tickets': las facturas de la venta; la pantalla las ofrece para facturar una
        //   NC guardada sin facturar.
        $models = $this->notas_credito_query($from_date, $until_date)
            ->conRelacionesDeNotaCredito();

        if (ListadoPorFechaPaginadoHelper::pide_paginado($request)) {
            return response()->json(ListadoPorFechaPaginadoHelper::respuesta($models, $request), 200);
        }

        $models = $models->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Factura ante ARCA una nota de crédito de cliente que se guardó sin facturar.
     *
     * Contrato: `POST nota-credito/{id}/facturar` con `afip_ticket_id` opcional (la factura de la
     * venta sobre la que se emite; sin él, la única autorizada). Responde 200 con
     * `{model, facturada, message}` (`facturada` es false si ARCA no la autorizó, y el motivo viaja
     * en `model.afip_ticket.afip_errors`), 422 `{message}` si una regla de negocio no la deja
     * facturar y 500 `{message}` si falla el emisor. Ver FacturarNotaCreditoExistenteHelper.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int                        $id  movimiento de cuenta corriente (status nota_credito)
     * @return \Illuminate\Http\JsonResponse
     */
    public function facturar(Request $request, $id)
    {
        try {

            $nota_credito = FacturarNotaCreditoExistenteHelper::facturar($id, $this->userId(), $request->afip_ticket_id);

        } catch (NotaCreditoNoFacturableException $e) {

            return response()->json(['message' => $e->getMessage()], 422);

        } catch (\Throwable $e) {

            // El handler global solo reporta lo que no se captura: se empuja a mano.
            report($e);

            return response()->json(['message' => 'No se pudo emitir la nota de crédito ante ARCA. Volvé a intentar en unos minutos.'], 500);
        }

        $facturada = !is_null($nota_credito->afip_ticket) && !empty($nota_credito->afip_ticket->cae);

        return response()->json([
            'model'     => $nota_credito,
            'facturada' => $facturada,
            'message'   => $facturada
                            ? 'Nota de crédito facturada ante ARCA.'
                            : 'ARCA no autorizó la nota de crédito. Revisá los errores del comprobante.',
        ], 200);
    }

    /**
     * Descarga un Excel con las notas de crédito del mismo criterio de fechas que el listado.
     *
     * @param string $from_date Fecha desde (Y-m-d).
     * @param string|null $until_date Fecha hasta (Y-m-d); opcional.
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function excel_export($from_date, $until_date = null)
    {
        $models = $this->notas_credito_query($from_date, $until_date)
            ->with(['client', 'sale', 'credit_account.moneda', 'articles', 'services', 'nota_credito_descriptions', 'afip_ticket'])
            ->get();

        return Excel::download(
            new NotasCreditoFullExport($models),
            'notas_credito_'.date_format(Carbon::now(), 'd-m-y').'.xlsx'
        );
    }

    /**
     * Query base de movimientos en estado nota_credito para el usuario owner, ordenados por creación.
     *
     * @param string|null $from_date Inicio de rango (inclusive) si no es null.
     * @param string|null $until_date Fin de rango (inclusive); si es null con from_date definido, se filtra solo ese día.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function notas_credito_query($from_date = null, $until_date = null)
    {
        $models = CurrentAcount::where('user_id', $this->userId())
            ->where('status', 'nota_credito')
            ->orderBy('created_at', 'DESC');

        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        return $models;
    }
}
