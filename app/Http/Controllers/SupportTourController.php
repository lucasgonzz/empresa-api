<?php

namespace App\Http\Controllers;

use App\Models\SupportTour;
use App\Models\User;
use App\Services\SupportTourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupportTourController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $tours = SupportTourService::de_la_persona($this->persona($request))
            ->where('state', 'pending')->orderBy('id')->get();

        return response()->json(['model' => $tours->map->para_navegacion()]);
    }

    public function accept(Request $request, string $uuid, SupportTourService $service): JsonResponse
    {
        $persona = $this->persona($request);
        $tour = DB::transaction(function () use ($persona, $uuid, $service) {
            $tour = $this->bloquear_tour($persona, $uuid);
            abort_unless(in_array($tour->state, ['pending', 'accepted'], true), 409, 'Esta guía ya terminó.');
            if ($tour->tema === SupportTour::GUIA_DINAMICA) {
                $service->validar_reproduccion($persona, $tour);
            }

            if ($tour->state === 'pending') {
                $tour->state = 'accepted';
                $tour->shown_at = $tour->shown_at ?? now();
                $tour->accepted_at = now();
                $tour->save();
            }

            return $tour;
        });

        return response()->json(['model' => $tour->para_navegacion()]);
    }

    public function event(Request $request, string $uuid): JsonResponse
    {
        $persona = $this->persona($request);
        $datos = $request->validate([
            'event' => 'required|in:shown,step,completed,abandoned,dismissed',
            'step' => 'required_if:event,step|prohibited_unless:event,step|integer|min:1',
        ]);

        DB::transaction(function () use ($persona, $uuid, $datos) {
            $tour = $this->bloquear_tour($persona, $uuid);
            $evento = $datos['event'];

            if ($evento === 'shown') {
                if ($tour->shown_at === null) {
                    $tour->shown_at = now();
                    $tour->save();
                }

                return;
            }

            if ($evento === 'step') {
                $paso = (int) $datos['step'];
                $vistos = $tour->steps_shown;
                abort_if($paso > $tour->total_steps, 422, 'Ese paso no pertenece a esta guía.');
                if (!in_array($paso, $vistos, true)) {
                    abort_unless($tour->state === 'accepted' && $tour->question_at === null,
                        409, 'La guía no está en curso.');
                    $vistos[] = $paso;
                    $tour->steps_shown = $vistos;
                    $tour->save();
                }

                return;
            }

            if ($tour->state === $evento) {
                return;
            }

            $estado_anterior = $evento === 'dismissed' ? 'pending' : 'accepted';
            abort_unless($tour->state === $estado_anterior, 409, 'La guía cambió de estado.');
            if ($evento === 'completed') {
                abort_unless($tour->question_at === null && count($tour->steps_shown) === $tour->total_steps,
                    409, 'Todavía quedan pasos para completar esta guía.');
            }

            $tour->state = $evento;
            $tour->finished_at = now();
            $tour->save();
        });

        return response()->json(['ok' => true]);
    }

    public function question(Request $request, string $uuid, SupportTourService $service): JsonResponse
    {
        $persona = $this->persona($request);
        $datos = $request->validate([
            'question' => 'required|string|max:1500',
            'step' => 'sometimes|integer|min:1',
        ]);

        if (!$service->preguntar($persona, $uuid, $datos['question'], $datos['step'] ?? null)) {
            return response()->json([
                'ok' => false,
                'code' => 'question_saved',
                'message' => 'La pregunta quedó guardada y todavía no llegó a Soporte.',
            ], 502);
        }

        return response()->json(['ok' => true]);
    }

    public function feedback(Request $request, string $uuid): JsonResponse
    {
        $persona = $this->persona($request);
        $datos = $request->validate([
            'liked' => 'required|boolean',
            'reasons' => 'sometimes|array|max:6',
            'reasons.*' => [
                'required', 'string', 'distinct',
                Rule::in($request->boolean('liked') ? SupportTour::MOTIVOS_POSITIVOS : SupportTour::MOTIVOS_NEGATIVOS),
            ],
            'comment' => 'sometimes|nullable|string|max:1500',
        ]);

        DB::transaction(function () use ($persona, $uuid, $datos) {
            $tour = $this->bloquear_tour($persona, $uuid);
            abort_unless($tour->state === 'completed', 409, 'Terminá la guía para poder calificarla.');

            $valoracion = [
                'liked' => (bool) $datos['liked'],
                'reasons' => array_values($datos['reasons'] ?? []),
                'comment' => $datos['comment'] ?? null,
            ];
            if ($tour->feedback === null || array_intersect_key($tour->feedback, $valoracion) != $valoracion) {
                $tour->feedback = $valoracion + ['at' => now()->toIso8601String()];
                $tour->save();
            }
        });

        return response()->json(['ok' => true]);
    }

    private function persona(Request $request): User
    {
        $persona = $request->user();
        abort_unless($persona instanceof User && SupportTourService::sesion_web_propia($request, $persona), 403);

        return $persona;
    }

    private function bloquear_tour(User $persona, string $uuid): SupportTour
    {
        return SupportTourService::de_la_persona($persona)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
    }
}
