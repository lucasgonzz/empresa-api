<?php

namespace App\Http\Controllers;

use App\Exports\NotasCreditoFullExport;
use App\Models\CurrentAcount;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class NotaCreditoController extends Controller
{
    /**
     * Lista notas de crédito del usuario con filtros opcionales por rango de fechas de creación.
     *
     * @param string|null $from_date Fecha desde (Y-m-d); null sin filtrar por fecha.
     * @param string|null $until_date Fecha hasta (Y-m-d); null filtra solo el día from_date cuando from_date viene informado.
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($from_date = null, $until_date = null)
    {
        $models = $this->notas_credito_query($from_date, $until_date)
            ->with('afip_ticket.afip_errors', 'afip_ticket.afip_observations', 'sale', 'articles', 'discounts', 'surchages', 'nota_credito_descriptions')
            ->get();

        return response()->json(['models' => $models], 200);
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
