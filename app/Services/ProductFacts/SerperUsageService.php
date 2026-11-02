<?php

namespace App\Services\ProductFacts;

use App\Models\SerperUsageEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Una fila durable por llamada real. Sin red en el camino del negocio. */
class SerperUsageService
{
    public static function record(array $context, $endpoint, $query, $response, $started)
    {
        try {
            $body = $response ? $response->json() : null;
            $credits = is_array($body) && isset($body['credits']) && is_numeric($body['credits']) && $body['credits'] >= 0
                ? (float) $body['credits'] : null;
            // /resolve es gratuito por contrato del proveedor, también si no responde. Viaja como search
            // para mantener compatibilidad con la ingesta del admin, identificado por su proceso exclusivo.
            if ($endpoint === 'search' && ($context['process'] ?? '') === SerperLinkResolver::PROCESO) {
                $credits = 0.0;
            }
            SerperUsageEvent::create([
                'event_uuid' => (string) Str::uuid(),
                'user_id' => $context['user_id'] ?? null,
                'auth_user_id' => $context['auth_user_id'] ?? null,
                'auth_user_name' => isset($context['auth_user_name']) ? mb_substr($context['auth_user_name'], 0, 190) : null,
                'process' => mb_substr($context['process'] ?? 'imagenes', 0, 80),
                'endpoint' => $endpoint,
                'query' => mb_substr((string) $query, 0, 255),
                'ok' => $response ? $response->successful() : false,
                'http_status' => $response ? $response->status() : null,
                // Una búsqueda paga sin créditos informados conserva consumo desconocido; /resolve es gratuito por contrato.
                'credits' => $credits,
                'duration_ms' => (int) max(0, round((microtime(true) - $started) * 1000)),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar una consulta de Serper.');
        }
    }

    public function sync()
    {
        $ownerId = (int) config('app.USER_ID');
        if ($ownerId <= 0) {
            return 0;
        }
        // Una base compartida puede tener otros dueños: la credencial de esta instancia sólo representa al suyo.
        $events = SerperUsageEvent::where('user_id', $ownerId)->whereNull('synced_at')->orderBy('id')->limit(100)->get();
        if ($events->isEmpty()) {
            return 0;
        }
        $payload = $events->map(function ($event) {
            $row = $event->only(['event_uuid', 'auth_user_id', 'auth_user_name', 'process', 'endpoint', 'query', 'ok', 'http_status', 'credits', 'duration_ms']);
            $row['occurred_at'] = $event->occurred_at->toIso8601String();
            return $row;
        })->all();
        $result = app(CentralProductFactsClient::class)->request('POST', 'serper-usage', ['events' => $payload]);
        if (!isset($result['accepted']) || (int) $result['accepted'] !== count($payload)) {
            throw new ProductFactsException('El admin no confirmó el lote de consumo de Serper.');
        }
        SerperUsageEvent::whereIn('id', $events->pluck('id'))->whereNull('synced_at')->update(['synced_at' => now()]);
        return count($payload);
    }
}
