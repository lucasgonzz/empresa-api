<?php

namespace App\Http\Controllers\Helpers;

use App\Models\SupportMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SupportSyncHelper
{
    /**
     * Envía un mensaje local a admin-api para mantener bandeja central sincronizada.
     *
     * @param SupportMessage $message
     * @return bool
     */
    public static function sync_message_to_admin(SupportMessage $message): bool
    {
        // Lee configuración compartida de integración con admin-api.
        $admin_url = config('services.admin_api.url');
        $outbound_key = config('services.admin_api.inbound_key');
        $client_uuid = config('services.admin_api.client_uuid');

        // Si falta configuración, evita romper el flujo del usuario.
        if (empty($admin_url) || empty($outbound_key) || empty($client_uuid)) {
            Log::warning('SupportSyncHelper: configuración incompleta para sync de soporte.');
            return false;
        }

        // Carga relaciones mínimas para construir payload robusto.
        $message->loadMissing('ticket', 'attachments', 'sender_user');

        // Arma payload compatible con el endpoint inbound de admin-api.
        $payload = [
            'client_uuid' => $client_uuid,
            'ticket_uuid' => optional($message->ticket)->uuid,
            'ticket_status' => optional($message->ticket)->status,
            'ticket_name' => optional($message->ticket)->name,
            'client_user_id' => optional($message->ticket)->user_id,
            'message_uuid' => $message->uuid,
            'sender_type' => $message->sender_type,
            'sender_user_id' => $message->sender_user_id,
            'sender_user_name' => optional($message->sender_user)->name,
            'sender_user_email' => optional($message->sender_user)->email,
            'kind' => $message->kind,
            'body' => $message->body,
            'sent_at' => optional($message->created_at)->toIso8601String(),
            'attachments' => $message->attachments->map(function ($attachment) {
                // Serializa metadata de adjuntos para replicación remota.
                return [
                    'disk' => $attachment->disk,
                    'path' => $attachment->path,
                    'mime' => $attachment->mime,
                    'size' => $attachment->size,
                ];
            })->values()->all(),
        ];

        try {
            // Crea request base autenticado hacia admin-api.
            $request = Http::withHeaders([
                    'X-Admin-Api-Key' => $outbound_key,
                    'Accept' => 'application/json',
                ])
                ->timeout(10);

            // Adjunta archivos binarios para replicar recursos en admin-api.
            foreach ($message->attachments as $attachment) {
                $disk = $attachment->disk ?: 'public';
                if (Storage::disk($disk)->exists($attachment->path)) {
                    $file_content = Storage::disk($disk)->get($attachment->path);
                    $request = $request->attach(
                        'attachments_files[]',
                        $file_content,
                        basename($attachment->path),
                        ['Content-Type' => $attachment->mime ?: 'application/octet-stream']
                    );
                }
            }

            // Ejecuta envío HTTP hacia admin-api con payload + adjuntos.
            $response = $request->post(rtrim($admin_url, '/') . '/api/inbound/support/messages', $payload);

            // Si fue exitoso, marca timestamp de sincronización.
            if ($response->successful()) {
                $message->synced_to_admin_at = now();
                $message->save();
                return true;
            }

            Log::warning('SupportSyncHelper: status ' . $response->status() . ' body ' . $response->body());
            return false;
        } catch (\Throwable $e) {
            // Registra excepción y mantiene mensaje como pendiente de sync.
            Log::warning('SupportSyncHelper exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reporta lectura de mensaje al admin-api para sincronizar read_at.
     */
    public static function sync_read_to_admin(SupportMessage $message): bool
    {
        $admin_url = config('services.admin_api.url');
        $outbound_key = config('services.admin_api.inbound_key');
        if (empty($admin_url) || empty($outbound_key)) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $outbound_key,
                    'Accept' => 'application/json',
                ])
                ->timeout(10)
                ->post(rtrim($admin_url, '/') . '/api/inbound/support/messages/read', [
                    'message_uuid' => $message->uuid,
                    'read_at' => optional($message->read_at)->toIso8601String(),
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('SupportSyncHelper sync_read exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reporta typing state al admin-api para reflejar "está escribiendo".
     */
    public static function sync_typing_to_admin($ticket_uuid, $actor_id): bool
    {
        $admin_url = config('services.admin_api.url');
        $outbound_key = config('services.admin_api.inbound_key');
        if (empty($admin_url) || empty($outbound_key)) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $outbound_key,
                    'Accept' => 'application/json',
                ])
                ->timeout(10)
                ->post(rtrim($admin_url, '/') . '/api/inbound/support/typing', [
                    'ticket_uuid' => $ticket_uuid,
                    'actor_type' => 'user',
                    'actor_id' => $actor_id,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('SupportSyncHelper sync_typing exception: ' . $e->getMessage());
            return false;
        }
    }
}

