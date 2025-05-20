<?php

namespace App\Http\Controllers;

use App\Models\DeliveryDay;
use Illuminate\Http\Request;

class DeliveryDayController extends Controller
{
    public function index()
    {
        $activeDays = DeliveryDay::where('user_id', $this->userId())
                                ->orderBy('day_of_week', 'ASC')
                                ->get();

        return response()->json(['models' => $activeDays], 200);
    }

    public function store(Request $request)
    {

        $model = DeliveryDay::create([
            'day_of_week'   => $request->day_of_week,
            'user_id'       => $this->userId(),
        ]);

        return response()->json(['model' => $model], 201);
    }

    public function update(Request $request, $id)
    {

        $model = DeliveryDay::find($id);

        $model->day_of_week = $request->day_of_week;
        $model->save();

        return response()->json(['model' => $model], 200);
    }

    public function destroy($id)
    {

        $model = DeliveryDay::find($id);

        $model->delete();

        return response(null, 200);
    }
}
