<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\SheetTypeHelper;
use Illuminate\Http\Request;

/**
 * Tipos de hoja de los diseños de PDF (misión diseno-ticket-comandera, 9/10/2026, contrato §3.1).
 *
 * - GET  api/sheet-types → los del sistema y los del dueño (rollos de comandera primero).
 * - POST api/sheet-types → agrega un ancho de comandera propio del negocio, o devuelve el que ya
 *   existe con ese ancho.
 *
 * Cada modelo sale con sus columnas (id, name, width, height, user_id, timestamps): las del
 * contrato son id, name, width, height y user_id (ints, height null en un rollo de comandera).
 *
 * La lógica vive en SheetTypeHelper; acá solo se valida y se responde.
 */
class SheetTypeController extends Controller
{
    /**
     * Los tipos de hoja que puede elegir el dueño en el formulario de Diseño de PDF.
     *
     * @return \Illuminate\Http\JsonResponse 200 {models: [{id, name, width, height, user_id, ...}]}
     */
    public function index()
    {
        return response()->json(['models' => SheetTypeHelper::del_dueno($this->userId())], 200);
    }

    /**
     * Agrega un ancho de comandera para el negocio ("Ticket {width} mm").
     *
     * - width entero entre 40 y 120 (mm); si no → 422 {message, errors: {width: [...]}}.
     * - Si el dueño ya ve un rollo de ese ancho (del sistema o suyo) → 200 con ese, sin duplicar.
     * - Si no → 201 con el nuevo.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate(
            [
                'width' => [
                    'required',
                    'integer',
                    'between:'.SheetTypeHelper::ANCHO_MINIMO_DE_COMANDERA.','.SheetTypeHelper::ANCHO_MAXIMO_DE_COMANDERA,
                ],
            ],
            [
                'width.between' => 'El ancho de la comandera tiene que estar entre '
                    .SheetTypeHelper::ANCHO_MINIMO_DE_COMANDERA.' y '.SheetTypeHelper::ANCHO_MAXIMO_DE_COMANDERA.' mm.',
                'width.integer' => 'El ancho de la comandera va en milímetros enteros.',
                'width.required' => 'Falta el ancho de la comandera.',
            ],
            ['width' => 'ancho de la comandera']
        );

        $resultado = SheetTypeHelper::asegurar_ticket_del_dueno($this->userId(), (int) $request->input('width'));

        return response()->json(
            ['model' => $this->fullModel('SheetType', $resultado['model']->id)],
            $resultado['creado'] ? 201 : 200
        );
    }
}
