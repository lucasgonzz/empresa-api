<?php

namespace App\Services;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupportAgentesSync
{
    public const VERSION = 1;

    public static function persona_propia(Request $request): ?User
    {
        $persona = $request->user();

        return $persona instanceof User && (int) $persona->id === (int) UserHelper::userId(false)
            && SupportTourService::sesion_web_propia($request, $persona)
            ? $persona
            : null;
    }

    public static function puede_usar_soporte(?User $persona): bool
    {
        return $persona !== null && SupportTourService::tiene_chat_soporte($persona);
    }

    public static function validar_callback_admin(Request $request, SupportTicket $ticket): void
    {
        if ($ticket->origen !== SupportTicket::ORIGEN_WHATSAPP
            && !$ticket->messages()->where('agentes_version', self::VERSION)->exists()) {
            return;
        }

        $clave = (string) config('services.admin_api.api_key');
        abort_unless($clave !== '' && hash_equals($clave, (string) $request->header('X-Admin-Api-Key')), 401);
    }

    public static function identidad(User $persona): ?array
    {
        $dueno = AsistenteCanalHelper::dueno();
        $uuid = trim((string) config('services.admin_api.client_uuid'));
        if (!$dueno || (int) ($persona->owner_id ?: $persona->id) !== (int) $dueno->id
            || $uuid === '' || trim((string) config('services.admin_api.inbound_key')) === '') {
            return null;
        }

        return [
            'client_uuid' => $uuid,
            'client_user_id' => (int) $persona->id,
            'es_dueno' => $persona->owner_id ? 0 : 1,
        ];
    }

    public function canales(User $persona): array
    {
        $canales = $this->consultar($persona, 'canales');

        return [
            'asistente' => $this->telefono($canales['asistente'] ?? null),
            'soporte' => $this->telefono($canales['soporte'] ?? null),
        ];
    }

    public function actualizar_lista(User $persona): array
    {
        $respuesta = $this->consultar($persona, 'soporte');
        $this->guardar_referencias_erp($persona, $respuesta['ticket_numbers'] ?? []);
        $ids = [];

        foreach ($respuesta['models'] ?? [] as $modelo) {
            $ticket = $this->guardar_ticket($persona, $modelo);
            if ($ticket) {
                $ids[] = $ticket->id;
            }
        }

        return $ids;
    }

    public function actualizar_referencias_erp(User $persona): void
    {
        if (!$this->tickets_erp_sin_referencia($persona)->exists()) {
            return;
        }
        $respuesta = $this->consultar($persona, 'soporte', ['solo_referencias_erp' => 1]);
        // Las sesiones que solo pueden ver ERP no importan conversaciones de WhatsApp.
        $this->guardar_referencias_erp($persona, $respuesta['ticket_numbers'] ?? []);
    }

    private function guardar_referencias_erp(User $persona, $referencias): void
    {
        if (!is_array($referencias) || $referencias === []) {
            return;
        }

        foreach ($this->tickets_erp_sin_referencia($persona)->pluck('uuid') as $uuid) {
            $numero = $referencias[$uuid] ?? null;
            if (!is_int($numero) || $numero < 1) {
                continue;
            }

            $this->tickets_erp_sin_referencia($persona)
                ->where('uuid', $uuid)
                ->update(['admin_ticket_id' => $numero]);
        }
    }

    private function tickets_erp_sin_referencia(User $persona)
    {
        return DB::table('support_tickets')
            ->where('user_id', $persona->id)
            ->where('origen', SupportTicket::ORIGEN_SISTEMA)
            ->whereNull('admin_ticket_id');
    }

    public function actualizar_conversacion(User $persona, SupportTicket $ticket): bool
    {
        $respuesta = $this->consultar($persona, 'soporte/' . $ticket->uuid);
        $modelo = $respuesta['model'] ?? null;

        if (!is_array($modelo) || ($modelo['uuid'] ?? null) !== $ticket->uuid) {
            return false;
        }

        return DB::transaction(function () use ($persona, $modelo) {
            $actualizado = $this->guardar_ticket($persona, $modelo);
            if ($actualizado) {
                foreach ($modelo['messages'] ?? [] as $mensaje) {
                    $this->guardar_mensaje($actualizado, $mensaje);
                }
            }

            return $actualizado !== null;
        });
    }

    private function consultar(User $persona, string $recurso, array $opciones = []): array
    {
        $identidad = self::identidad($persona);
        $url = rtrim((string) config('services.admin_api.url'), '/');
        if ($identidad === null || $url === '') {
            return [];
        }

        try {
            $respuesta = Http::acceptJson()
                ->withHeaders(['X-Admin-Api-Key' => config('services.admin_api.inbound_key')])
                ->timeout(5)
                ->get($url . '/api/inbound/agentes/' . $recurso, $identidad + $opciones);

            return $respuesta->successful() && is_array($respuesta->json()) ? $respuesta->json() : [];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('No se pudo consultar el puente de Agentes de Admin.', ['recurso' => $recurso]);

            return [];
        }
    }

    private function guardar_ticket(User $persona, array $modelo): ?SupportTicket
    {
        if (($modelo['origen'] ?? null) !== SupportTicket::ORIGEN_WHATSAPP
            || !Str::isUuid($modelo['uuid'] ?? '')
            || !in_array($modelo['status'] ?? null, ['open', 'closed'], true)) {
            return null;
        }

        return DB::transaction(function () use ($persona, $modelo) {
            $datos = [
                'name' => isset($modelo['name']) ? Str::limit($modelo['name'], 255, '') : null,
                'status' => $modelo['status'],
                'opened_at' => self::fecha_en_bd($modelo['opened_at'] ?? null) ?? now(),
                'closed_at' => self::fecha_en_bd($modelo['closed_at'] ?? null),
            ];
            DB::table('support_tickets')->upsert(array_merge($datos, [
                'uuid' => $modelo['uuid'],
                'user_id' => $persona->id,
                'origen' => SupportTicket::ORIGEN_WHATSAPP,
                'created_at' => now(),
                'updated_at' => now(),
            ]), ['uuid'], ['uuid']);

            $ticket = SupportTicket::where('uuid', $modelo['uuid'])
                ->where('user_id', $persona->id)
                ->where('origen', SupportTicket::ORIGEN_WHATSAPP)
                ->lockForUpdate()->first();

            if ($ticket) {
                $ticket->fill($datos);
                $ticket->sistema_exclusivo_at = $ticket->sistema_exclusivo_at ?? self::fecha_en_bd($modelo['sistema_exclusivo_at'] ?? null);
                $numero = $modelo['admin_ticket_id'] ?? null;
                if (is_int($numero) && $numero > 0) {
                    $ticket->admin_ticket_id = $numero;
                }
                $ultima_fecha = self::fecha_en_bd($modelo['last_message_at'] ?? null);
                $fecha_guardada = $ticket->getRawOriginal('remote_last_message_at');
                if ($ultima_fecha !== null && ($fecha_guardada === null || $ultima_fecha > $fecha_guardada)) {
                    $ticket->remote_last_message_at = $ultima_fecha;
                }
                if ($ticket->isDirty()) {
                    $ticket->save();
                }
            }

            return $ticket;
        });
    }

    private function guardar_mensaje(SupportTicket $ticket, array $modelo): void
    {
        if (!Str::isUuid($modelo['uuid'] ?? '') || !in_array($modelo['sender_type'] ?? null, ['user', 'admin'], true)) {
            return;
        }

        $mensaje = SupportMessage::firstOrNew(['uuid' => $modelo['uuid']]);
        if ($mensaje->exists && (int) $mensaje->support_ticket_id !== (int) $ticket->id) {
            return;
        }
        if (!$mensaje->exists) {
            $mensaje->fill([
                'support_ticket_id' => $ticket->id,
                'sender_type' => $modelo['sender_type'],
                'sender_user_id' => $modelo['sender_type'] === 'user' ? $ticket->user_id : null,
                'kind' => $modelo['kind'] ?? 'text',
                'body' => $modelo['body'] ?? null,
                'created_at' => self::fecha_en_bd($modelo['created_at'] ?? null) ?? now(),
                'delivered_at' => self::fecha_en_bd($modelo['delivered_at'] ?? null),
                'read_at' => self::fecha_en_bd($modelo['read_at'] ?? null),
                'synced_to_admin_at' => now(),
            ]);
            $mensaje->save();
        }

        // Los adjuntos que ya llegaron por el puente ERP conservan su copia local.
        if ($mensaje->attachments()->whereNull('remote_id')->exists()) {
            return;
        }
        foreach ($modelo['attachments'] ?? [] as $adjunto) {
            $this->guardar_adjunto($mensaje, $adjunto);
        }
    }

    private function guardar_adjunto(SupportMessage $mensaje, array $adjunto): void
    {
        $url = $adjunto['playback_url'] ?? $adjunto['public_url'] ?? $adjunto['download_url'] ?? '';
        $admin = rtrim((string) config('services.admin_api.url'), '/');
        if ((int) ($adjunto['id'] ?? 0) <= 0 || !Str::startsWith($url, $admin . '/')) {
            return;
        }

        SupportMessageAttachment::updateOrCreate([
            'support_message_id' => $mensaje->id,
            'remote_id' => (int) $adjunto['id'],
        ], [
            'disk' => 'remoto',
            'path' => '',
            'public_url' => $url,
            'mime' => $adjunto['mime'] ?? null,
            'size' => $adjunto['size'] ?? null,
        ]);
    }

    private function telefono($valor): ?string
    {
        $numero = is_string($valor) ? preg_replace('/[ ()-]/', '', $valor) : '';

        return preg_match('/^\+?[0-9]{8,15}$/D', $numero) ? ltrim($numero, '+') : null;
    }

    public static function fecha_en_bd($valor): ?string
    {
        if (!is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->setTimezone(config('app.timezone'))->toDateTimeString();
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            return null;
        }
    }
}
