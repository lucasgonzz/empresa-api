<?php

namespace App\Http\Controllers;

use App\Models\RoadMapClientObservation;
use Illuminate\Http\Request;

class RoadMapClientObservationController extends Controller
{
    function store(Request $request) {
        $model = RoadMapClientObservation::create([
            'road_map_id'   => $request->road_map_id,
            'client_id'     => $request->client_id,
            'text'          => $request->text,
        ]);

        return response()->json(['model' => $model], 201);
    }
}
