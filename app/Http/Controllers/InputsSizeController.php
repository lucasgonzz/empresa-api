<?php

namespace App\Http\Controllers;

use App\Models\InputsSize;
use Illuminate\Http\Request;

class InputsSizeController extends Controller
{
    public function index()
    {
        $models = InputsSize::withAll()->orderBy('id')->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request)
    {
        $model = InputsSize::create([
            'name' => $request->name,
            'slug' => $request->slug,
        ]);
        $this->sendAddModelNotification('InputsSize', $model->id);
        return response()->json(['model' => $this->fullModel('InputsSize', $model->id)], 201);
    }

    public function show($id)
    {
        return response()->json(['model' => $this->fullModel('InputsSize', $id)], 200);
    }

    public function update(Request $request, $id)
    {
        $model = InputsSize::find($id);
        $model->name = $request->name;
        $model->slug = $request->slug;
        $model->save();
        $this->sendAddModelNotification('InputsSize', $model->id);
        return response()->json(['model' => $this->fullModel('InputsSize', $model->id)], 200);
    }

    public function destroy($id)
    {
        $model = InputsSize::find($id);
        $model->delete();
        $this->sendDeleteModelNotification('InputsSize', $model->id);
        return response(null);
    }
}
