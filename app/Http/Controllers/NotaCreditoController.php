<?php

namespace App\Http\Controllers;

use App\Models\CurrentAcount;
use Illuminate\Http\Request;

class NotaCreditoController extends Controller
{
    public function index($from_date = null, $until_date = null) {

        $models = CurrentAcount::where('user_id', $this->userId())
                                ->where('status', 'nota_credito')
                                ->with('afip_ticket', 'sale', 'articles', 'discounts', 'surchages')
                                ->orderBy('created_at', 'DESC');

        if (!is_null($from_date)) {

            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }

        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }
}
