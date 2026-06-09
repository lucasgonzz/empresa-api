<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\VenderKeyboardShortcutHelper;
use App\Models\VenderKeyboardShortcut;
use Illuminate\Http\Request;

/**
 * CRUD acotado de atajos de teclado del módulo Vender por usuario autenticado.
 */
class VenderKeyboardShortcutController extends Controller
{
    /**
     * Devuelve la configuración del usuario autenticado o los defaults si no existe.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show()
    {
        $user_id = $this->userId(false);

        $model = VenderKeyboardShortcut::where('user_id', $user_id)->first();

        $shortcuts = $model
            ? VenderKeyboardShortcutHelper::normalize_shortcuts($model->shortcuts)
            : VenderKeyboardShortcutHelper::default_shortcuts();

        $print_options = $model && $model->print_options
            ? VenderKeyboardShortcutHelper::normalize_print_options($model->print_options)
            : VenderKeyboardShortcutHelper::default_print_options();

        return response()->json([
            'model' => [
                'id' => $model ? $model->id : null,
                'user_id' => $user_id,
                'shortcuts' => $shortcuts,
                'print_options' => $print_options,
            ],
        ], 200);
    }

    /**
     * Persiste la configuración de atajos del usuario autenticado.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $user_id = $this->userId(false);

        $shortcuts = VenderKeyboardShortcutHelper::normalize_shortcuts(
            $request->input('shortcuts', [])
        );

        $print_options = VenderKeyboardShortcutHelper::normalize_print_options(
            $request->input('print_options', [])
        );

        if (VenderKeyboardShortcutHelper::has_duplicate_keys($shortcuts)) {
            return response()->json([
                'message' => 'No puede repetir la misma tecla en dos acciones.',
            ], 422);
        }

        $model = VenderKeyboardShortcut::updateOrCreate(
            ['user_id' => $user_id],
            [
                'shortcuts' => $shortcuts,
                'print_options' => $print_options,
            ]
        );

        return response()->json([
            'model' => [
                'id' => $model->id,
                'user_id' => $model->user_id,
                'shortcuts' => VenderKeyboardShortcutHelper::normalize_shortcuts($model->shortcuts),
                'print_options' => VenderKeyboardShortcutHelper::normalize_print_options($model->print_options),
            ],
        ], 200);
    }
}
