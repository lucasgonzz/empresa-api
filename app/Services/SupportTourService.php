<?php

namespace App\Services;

use App\Events\SupportTourOffered;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SupportSyncHelper;
use App\Http\Controllers\Helpers\SupportTicketHelper;
use App\Http\Controllers\Helpers\TelefonoArgentinoHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\SupportTour;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupportTourService
{
    public const VERSION_SOPORTE_WEB = 1;

    public static function sesion_web_propia(Request $request, User $persona): bool
    {
        $usuario_web = $request->user('web');
        if (!$request->hasSession() || $usuario_web === null || (int) $usuario_web->id !== (int) $persona->id) {
            return false;
        }

        $sesion = $request->session();

        return !$sesion->has('acceso_soporte_token_id')
            && !$sesion->get('master_login_bypass_user_last_activity', false)
            && (!$sesion->has('demo_ingreso_token_id') || PruebaHelper::puede_haber_prueba());
    }

    public static function tiene_chat_soporte(User $persona): bool
    {
        $dueno = $persona->owner_id ? $persona->owner : $persona;

        return PermisosIaHelper::tiene_extencion($dueno, 'support_chat');
    }

    public static function de_la_persona(User $persona): Builder
    {
        return SupportTour::where('owner_id', $persona->owner_id ?: $persona->id)
            ->where('recipient_id', $persona->id);
    }

    public function ofrecer(User $dueno, array $datos): SupportTour
    {
        $mensaje = null;
        $persona_id = $datos['user_id'] ?? $dueno->id;

        if ($datos['source'] === 'sistema') {
            // El UUID corresponde al mensaje de la persona, en el ticket espejo de este frente.
            $mensaje = SupportMessage::with('ticket')->where('uuid', $datos['uuid'])
                ->where('sender_type', 'user')->first();
            abort_unless($mensaje && (int) $mensaje->robotito_support_version === self::VERSION_SOPORTE_WEB
                && $mensaje->ticket && $mensaje->ticket->uuid === $datos['ticket_uuid']
                && (int) $mensaje->ticket->user_id === (int) $mensaje->sender_user_id
                && (!isset($datos['user_id']) || (int) $datos['user_id'] === (int) $mensaje->sender_user_id),
                409, 'No se pudo identificar a la persona de ese mensaje de soporte.');
            $persona_id = $mensaje->sender_user_id;
        }

        $textos = array_values($datos['textos'] ?? []);
        $total = SupportTour::PASOS_POR_TEMA[$datos['tema']];

        [$tour, $nuevo] = DB::transaction(function () use ($dueno, $datos, $mensaje, $persona_id, $textos, $total) {
            // El mismo usuario puede recibir una sola oferta por mensaje, incluso con reintentos simultáneos.
            $persona = User::whereKey($persona_id)->lockForUpdate()->first();
            abort_unless($persona && (int) ($persona->owner_id ?: $persona->id) === (int) $dueno->id,
                409, 'No se pudo identificar a la persona en este sistema.');
            if ($datos['source'] === 'whatsapp' && isset($datos['user_id'])) {
                $this->validar_telefono_del_empleado($dueno, $persona, $datos['recipient_phone']);
            }

            $previo = SupportTour::where('owner_id', $dueno->id)->where('uuid', $datos['uuid'])->first();
            if ($previo) {
                abort_unless((int) $previo->recipient_id === (int) $persona_id
                    && $previo->ticket_uuid === $datos['ticket_uuid'] && $previo->source === $datos['source']
                    && $previo->tema === $datos['tema'] && $previo->titulo === $datos['titulo'] && $previo->textos === $textos,
                    409, 'Ese mensaje ya tiene una oferta de Robotito con otros datos.');

                return [$previo, false];
            }

            if ($datos['tema'] === 'crear_ticket') {
                abort_unless(self::tiene_chat_soporte($persona), 409, 'El chat de soporte no está disponible en este sistema.');
            }
            if (in_array($datos['tema'], ['buscar_articulo_nombre', 'buscar_articulo_codigo'], true)) {
                abort_unless(PermisosIaHelper::tiene_extencion($dueno, 'comerciocity_interno')
                    && PermisosIaHelper::puede($persona, 'sale.store'),
                    409, 'La pantalla Vender no está disponible para esa persona.');
            }

            $anterior = $mensaje ? self::de_la_persona($persona)
                ->where('question_message_id', $mensaje->id)->lockForUpdate()->first() : null;

            $tour = SupportTour::create([
                'owner_id' => $dueno->id,
                'recipient_id' => $persona_id,
                'uuid' => $datos['uuid'],
                'ticket_uuid' => $datos['ticket_uuid'],
                'origin_ticket_uuid' => $anterior ? $anterior->origin_ticket_uuid : $datos['ticket_uuid'],
                'previous_uuid' => optional($anterior)->uuid,
                'source' => $datos['source'],
                'tema' => $datos['tema'],
                'titulo' => $datos['titulo'],
                'textos' => $textos,
                'total_steps' => $total,
                'steps_shown' => [],
            ]);

            if ($anterior) {
                $anterior->reformulated_at = now();
                if ($anterior->state === 'accepted') {
                    $anterior->state = 'reformulated';
                    $anterior->finished_at = $anterior->reformulated_at;
                }
                $anterior->save();
            }

            return [$tour, true];
        });

        if ($nuevo) {
            try {
                event(new SupportTourOffered((int) $persona_id));
            } catch (\Throwable $e) {
                Log::warning('No se pudo avisar el tour por socket. La oferta sigue disponible.', ['uuid' => $tour->uuid]);
            }
        }

        return $tour;
    }

    private function validar_telefono_del_empleado(User $dueno, User $persona, string $remitente): void
    {
        $canonico = strpos($remitente, '54') === 0 ? TelefonoArgentinoHelper::a_e164($remitente) : null;
        $coincide = static function (User $usuario) use ($remitente, $canonico): bool {
            if (WhatsappPhoneHelper::normalize($usuario->phone) === $remitente) {
                return true;
            }

            return $canonico !== null && TelefonoArgentinoHelper::a_e164($usuario->phone) === $canonico;
        };

        abort_unless($persona->owner_id && $coincide($persona) && !$coincide($dueno),
            409, 'El teléfono del remitente no identifica a ese empleado en el sistema.');

        $otro_empleado = $dueno->employees()->where('id', '<>', $persona->id)->whereNotNull('phone')
            ->get(['id', 'phone'])->contains($coincide);
        abort_if($otro_empleado, 409, 'El teléfono del remitente está asociado a más de un empleado.');
    }

    public function preguntar(User $persona, string $uuid, string $pregunta, ?int $paso): bool
    {
        $mensaje = DB::transaction(function () use ($persona, $uuid, $pregunta, $paso) {
            User::whereKey($persona->id)->lockForUpdate()->firstOrFail();
            $tour = self::de_la_persona($persona)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if ($tour->question_message_id !== null) {
                abort_unless($tour->question === $pregunta && $tour->question_message,
                    409, 'La pregunta ya quedó guardada para esta guía.');

                return $tour->question_message;
            }

            abort_unless($tour->state === 'accepted', 409, 'Empezá la guía para poder preguntar.');
            abort_unless(self::tiene_chat_soporte($persona), 409, 'El chat de soporte no está disponible en este sistema.');
            $vistos = $tour->steps_shown;
            abort_if($paso !== null && !in_array($paso, $vistos, true), 409, 'Ese paso todavía no quedó registrado.');
            $paso = $paso ?? ($vistos ? end($vistos) : null);

            // Conserva el hilo original si es web y sigue abierto. Un origen de WhatsApp se continúa en Soporte.
            $ticket = SupportTicket::where('uuid', $tour->ticket_uuid)->where('user_id', $persona->id)
                ->where(function ($hilos) {
                    $hilos->where('origen', SupportTicket::ORIGEN_SISTEMA)->orWhereNotNull('sistema_exclusivo_at');
                })
                ->where('status', 'open')->first() ?? SupportTicketHelper::get_or_create_open_ticket((int) $persona->id);

            $contexto = 'Pregunta para Robotito sobre «' . $tour->titulo . '»';
            if ($paso !== null) {
                $contexto .= ' en el paso ' . $paso;
            }

            $mensaje = SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type' => 'user',
                'sender_user_id' => $persona->id,
                'kind' => 'text',
                'body' => $contexto . ': ' . $pregunta,
                'delivered_at' => now(),
                'remote_delivery_status' => 'not_received',
                'robotito_support_version' => self::VERSION_SOPORTE_WEB,
                'agentes_version' => $ticket->origen === SupportTicket::ORIGEN_WHATSAPP ? SupportAgentesSync::VERSION : null,
            ]);

            $tour->forceFill([
                'question' => $pregunta,
                'question_step' => $paso,
                'question_at' => now(),
                'question_message_id' => $mensaje->id,
                'question_ticket_uuid' => $ticket->uuid,
            ])->save();

            return $mensaje;
        });

        // El reintento web y el del cron usan el UUID del mismo mensaje persistido.
        return $mensaje->synced_to_admin_at !== null || SupportSyncHelper::sync_message_to_admin($mensaje);
    }
}
