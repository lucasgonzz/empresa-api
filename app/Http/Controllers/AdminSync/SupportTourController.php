<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Models\SupportTour;
use App\Models\User;
use App\Services\SupportTourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportTourController extends Controller
{
    public function store(Request $request, SupportTourService $service): JsonResponse
    {
        $datos = $request->validate([
            'uuid' => 'required|uuid',
            'ticket_uuid' => 'required|uuid',
            'source' => 'required|in:sistema,whatsapp',
            'user_id' => 'sometimes|integer|min:1',
            'recipient_phone' => [
                Rule::requiredIf($request->input('source') === 'whatsapp' && $request->has('user_id')),
                'prohibited_unless:source,whatsapp', 'string', 'regex:/^[1-9][0-9]{7,14}$/',
            ],
            'tema' => ['required', Rule::in(array_keys(SupportTour::PASOS_POR_TEMA))],
            'titulo' => 'required|string|max:120',
            'textos' => 'sometimes|array',
            'textos.*' => 'required|string|max:240',
            'import_history_id' => 'required_if:tema,' . SupportTour::REVERTIR_IMPORTACION_ARTICULOS
                . '|prohibited_unless:tema,' . SupportTour::REVERTIR_IMPORTACION_ARTICULOS . '|integer|min:1|max:9007199254740991',
        ]);

        if (isset($datos['textos']) && array_keys($datos['textos']) !== range(0, SupportTour::PASOS_POR_TEMA[$datos['tema']] - 1)) {
            throw ValidationException::withMessages(['textos' => 'Mandá un texto para cada paso, en el orden del recorrido.']);
        }

        $datos['uuid'] = strtolower($datos['uuid']);
        $datos['ticket_uuid'] = strtolower($datos['ticket_uuid']);
        $tour = $service->ofrecer($this->dueno(), $datos);

        return response()->json(['ok' => true, 'uuid' => $tour->uuid]);
    }

    public function index(string $ticket_uuid): JsonResponse
    {
        $tours = SupportTour::where('owner_id', $this->dueno()->id)
            ->where(function ($query) use ($ticket_uuid) {
                $query->where('ticket_uuid', $ticket_uuid)
                    ->orWhere('origin_ticket_uuid', $ticket_uuid)
                    ->orWhere('question_ticket_uuid', $ticket_uuid);
            })
            ->with('question_message:id,synced_to_admin_at')
            ->orderBy('id')
            ->get();

        return response()->json(['model' => $tours->map->para_seguimiento()]);
    }

    public function importaciones_candidatas(Request $request, SupportTourService $service): JsonResponse
    {
        $datos = $request->validate([
            'user_id' => 'required|integer|min:1',
            'import_history_id' => 'sometimes|required|integer|min:1|max:9007199254740991',
        ]);
        $persona = User::find($datos['user_id']);
        $dueno = $this->dueno();
        abort_unless($persona && (int) ($persona->owner_id ?: $persona->id) === (int) $dueno->id,
            409, 'No se pudo identificar a la persona en este sistema.');

        return response()->json(['model' => $service->importaciones_candidatas(
            $persona, isset($datos['import_history_id']) ? (int) $datos['import_history_id'] : null
        )]);
    }

    public function recargos_disponibles(Request $request, SupportTourService $service): JsonResponse
    {
        $datos = $request->validate(['user_id' => 'sometimes|required|integer|min:1']);
        $dueno = $this->dueno();
        $persona = isset($datos['user_id']) ? User::find($datos['user_id']) : $dueno;

        return response()->json(['available' => $persona !== null && $service->puede_guiar_recargos($dueno, $persona)]);
    }

    private function dueno(): User
    {
        $dueno = AsistenteCanalHelper::dueno();
        abort_unless($dueno, 409, 'No se pudo identificar el comercio de este frente.');

        return $dueno;
    }
}
