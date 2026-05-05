<?php

namespace App\Http\Controllers;

use App\Models\SaleSenderInfo;
use Illuminate\Http\Request;

/**
 * CRUD de remitentes para etiqueta de envío (cabecera del PDF).
 */
class SaleSenderInfoController extends Controller
{
    /**
     * Listado de remitentes del usuario actual.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = SaleSenderInfo::where('user_id', $this->userId())
            ->orderBy('name')
            ->withAll()
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Detalle de un remitente.
     *
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        SaleSenderInfo::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['model' => $this->fullModel('SaleSenderInfo', $id)], 200);
    }

    /**
     * Alta de remitente.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $model = SaleSenderInfo::create([
            'user_id' => $this->userId(),
            'name' => $request->input('name'),
            'mail' => $request->input('mail'),
            'cuit' => $request->input('cuit'),
            'provincia' => $request->input('provincia'),
            'localidad' => $request->input('localidad'),
            'postal_code' => $request->input('postal_code'),
        ]);

        return response()->json(['model' => $this->fullModel('SaleSenderInfo', $model->id)], 201);
    }

    /**
     * Actualización de remitente.
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $model = SaleSenderInfo::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        $model->fill([
            'name' => $request->input('name', $model->name),
            'mail' => $request->input('mail'),
            'cuit' => $request->input('cuit'),
            'provincia' => $request->input('provincia'),
            'localidad' => $request->input('localidad'),
            'postal_code' => $request->input('postal_code'),
        ]);
        $model->save();

        return response()->json(['model' => $this->fullModel('SaleSenderInfo', $model->id)], 200);
    }

    /**
     * Eliminación de remitente.
     *
     * @param int|string $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $model = SaleSenderInfo::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        $model->delete();

        return response(null, 204);
    }
}
