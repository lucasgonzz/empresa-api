<?php

namespace App\Services;

use App\Http\Controllers\AiConversationController;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\PruebaConfig;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsistenteUnificadoDePrueba
{
    private $proxy;

    public function __construct(DemoChatProxy $proxy)
    {
        $this->proxy = $proxy;
    }

    public function tiene_acceso_personal(User $dueno): bool
    {
        // El canal de Admin identifica una sola instancia. Su historial no corresponde
        // a otro dueño que pudiera existir en la misma base.
        return UserHelper::hasExtencion('agentes_ia', $dueno)
            && app(AsistenteIaService::class)->hay_credenciales($dueno)
            && (int) optional(PruebaHelper::dueno())->id === (int) $dueno->id;
    }

    public function disponible_para(User $dueno): bool
    {
        if (!$this->tiene_acceso_personal($dueno)) {
            return false;
        }

        $token = (string) optional(DemoTrackingConfigHelper::actual())->eventos_token;
        $clave = trim((string) config('services.admin_api.inbound_key'));
        if ($token === '' || $clave === '') {
            return false;
        }

        // Guardar solo la capacidad, nunca el historial. Una rotación de claves invalida la caché.
        $cache = 'asistente_unificado_prueba:' . hash('sha256', json_encode([
            config('services.admin_api.url'), $dueno->id, $token, $clave,
        ]));

        try {
            return Cache::remember($cache, now()->addSeconds(30), function () {
                return ($this->proxy->solicitar_prueba('GET', '/manual', ['capacidad' => 1], 2)
                    ->getData(true)['disponible'] ?? false) === true;
            });
        } catch (\Throwable $exception) {
            Log::warning('No se pudo comprobar la capacidad del asistente unificado de la prueba.');
            return false;
        }
    }

    public function trasladar(User $dueno): array
    {
        $snapshot = $this->proxy->solicitar_prueba('GET')->getData(true);
        if (($snapshot['disponible'] ?? false) !== true || !is_array($snapshot['mensajes'] ?? null)) {
            return ['disponible' => false];
        }

        $pendiente = in_array($snapshot['conversacion']['estado'] ?? '', ['esperando', 'pensando', 'listo'], true);

        return DB::transaction(function () use ($dueno, $snapshot, $pendiente) {
            $prueba = PruebaConfig::where('owner_id', $dueno->id)->lockForUpdate()->first();
            if ($prueba === null) {
                return ['disponible' => false];
            }

            $conversacion = AiConversation::where('id', $prueba->chat_trasladado_id)
                ->where('user_id', $dueno->id)
                ->where('auth_user_id', $dueno->id)
                ->where('origen', AiConversation::ORIGEN_PRUEBA_TRASLADADA)
                ->lockForUpdate()
                ->first();

            if ($prueba->chat_trasladado_id !== null && $conversacion === null) {
                return ['disponible' => true, 'conversacion_id' => null];
            }

            // El panel sigue el turno personal pendiente. Una respuesta anterior
            // publicada tarde no debe taparlo mientras se genera.
            if ($conversacion && $conversacion->messages()->where('estado', 'pendiente')
                ->where('created_at', '>=', now()->subMinutes(AiConversationController::MINUTOS_VENCIMIENTO_PENDIENTE))
                ->exists()) {
                return ['disponible' => true, 'conversacion_id' => $conversacion->id, 'pendiente' => true];
            }

            $ids_trasladados = $conversacion
                ? $conversacion->messages()->whereNotNull('prueba_mensaje_id')->pluck('prueba_mensaje_id')->flip()
                : collect();

            foreach ($snapshot['mensajes'] as $mensaje) {
                $fila = $this->normalizar_mensaje($mensaje);
                if ($fila === null || $ids_trasladados->has($fila['prueba_mensaje_id'])) {
                    continue;
                }

                if ($conversacion === null) {
                    $conversacion = AiConversation::create([
                        'user_id' => $dueno->id,
                        'auth_user_id' => $dueno->id,
                        'origen' => AiConversation::ORIGEN_PRUEBA_TRASLADADA,
                        'titulo' => 'Tu conversación de la prueba',
                        'created_at' => $fila['created_at'],
                    ]);
                    $prueba->chat_trasladado_id = $conversacion->id;
                    $prueba->save();
                }

                $conversacion->messages()->create($fila);
                $ids_trasladados->put($fila['prueba_mensaje_id'], true);
                if ($conversacion->last_message_at === null || $fila['created_at']->gt($conversacion->last_message_at)) {
                    $conversacion->last_message_at = $fila['created_at'];
                }
            }

            if ($conversacion && $conversacion->isDirty('last_message_at')) {
                $conversacion->save();
            }

            return [
                'disponible' => true,
                'conversacion_id' => optional($conversacion)->id,
                'pendiente' => $pendiente,
            ];
        });
    }

    private function normalizar_mensaje($mensaje): ?array
    {
        if (!is_array($mensaje) || !in_array($mensaje['rol'] ?? '', ['lead', 'asistente'], true)
            || !is_int($mensaje['id'] ?? null) || $mensaje['id'] < 1
            || !is_string($mensaje['contenido'] ?? null) || trim($mensaje['contenido']) === ''
            || !is_string($mensaje['creado_at'] ?? null) || trim($mensaje['creado_at']) === ''
            || ($mensaje['estado'] ?? 'publicado') !== 'publicado') {
            return null;
        }

        try {
            $fecha = Carbon::parse($mensaje['creado_at'])->setTimezone(config('app.timezone'));
        } catch (\InvalidArgumentException $exception) {
            return null;
        }

        return [
            'prueba_mensaje_id' => $mensaje['id'],
            'rol' => $mensaje['rol'] === 'lead' ? 'user' : 'assistant',
            'contenido' => $mensaje['contenido'],
            'estado' => 'listo',
            'canal' => AiMessage::CANAL_SISTEMA,
            'created_at' => $fecha,
            'updated_at' => $fecha,
        ];
    }
}
