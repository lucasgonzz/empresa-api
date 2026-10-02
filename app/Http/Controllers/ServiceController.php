<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    /**
     * Alta de un servicio suelto desde Vender.
     *
     * Valida `name` y `price` antes de tocar la base: `services.price` es `decimal(10,2)`, y un
     * valor que no es número (el 2/10/2026 llegó `"3500}"` desde pack-descartables) reventaba el
     * insert con `1265 Data truncated` y la SPA mostraba un 500. Ahora responde 422 con el mensaje
     * del campo. Los negativos se permiten (se desconoce si algún cliente carga servicios como
     * descuento); el tope es el del decimal.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    function store(Request $request) {
        $request->validate([
            'name'  => 'required|max:255',
            'price' => 'required|numeric|between:-99999999.99,99999999.99',
        ], [
            'name.required'  => 'Ingrese el nombre del servicio.',
            'price.required' => 'Ingrese un precio para el servicio.',
            'price.numeric'  => 'El precio del servicio debe ser un número (ej: 3500 o 3500.50).',
            'price.between'  => 'El precio del servicio está fuera del rango permitido.',
        ]);

        $model = Service::create([
            'name'      => $request->name,
            'price'     => $request->price,
            'user_id'   => $this->userId(),
        ]);
        return response()->json(['model' => $model], 201);
    }
}
