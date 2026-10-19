<?php

namespace App\Services\ProductFacts;

use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Models\Article;
use App\Models\ProductEnrichment;
use App\Models\User;
use App\Http\Controllers\Helpers\asistente_ia\BusquedaPorCodigoDeBarrasIaHelper;
use Illuminate\Support\Facades\DB;

class ProductEnrichmentService
{
    public function process(ProductEnrichment $task)
    {
        $owner = User::find($task->user_id);
        $person = User::find($task->auth_user_id);
        if (!$owner || (int) config('app.USER_ID') !== (int) $task->user_id || !$person || (int) ($person->owner_id ?: $person->id) !== (int) $owner->id
            || !PermisosIaHelper::puede($person, 'article.store')) {
            throw new ProductFactsException('Ya no tenés permiso para buscar información de productos.');
        }
        if (Article::where('user_id', $owner->id)->whereIn('bar_code', Gtin::equivalents($task->gtin))->exists()) {
            $this->transition($task, ['status' => 'duplicate', 'message' => 'Este código ya existe en tu catálogo.']);
            return;
        }
        $central = app(CentralProductFactsClient::class);
        // Cada ejecución/reintento reclama de nuevo. Una reserva guardada por un job muerto no sirve.
        $claim = $central->claim($task->gtin, ['auth_user_id' => $person->id, 'auth_user_name' => mb_substr((string) $person->name, 0, 190)]);
        $status = $claim['status'] ?? '';
        if ($task->fresh()->status !== 'searching') {
            if ($status === 'claimed' && !empty($claim['lease_token'])) {
                try { $central->complete($task->gtin, $claim['lease_token'], 'failed'); } catch (\Throwable $ignored) {}
            }
            return;
        }
        if ($status === 'pending') {
            $this->transition($task, ['status' => 'waiting', 'message' => 'Otro comercio está buscando esta ficha. Enseguida la compartimos.']);
            return;
        }
        if ($status === 'not_found') {
            $this->transition($task, ['status' => 'not_found', 'message' => 'Todavía no encontramos información verificada para este producto.']);
            return;
        }
        $research = app()->make(ProductFactsResearchService::class, ['owner' => $owner, 'person' => $person]);
        $product = null;
        $cacheHit = $status === 'found';
        if ($cacheHit) {
            // Campos normalizados directos del catálogo común: ninguna extracción ni Serper.
            $product = $claim['product'] ?? null;
        } elseif ($status === 'claimed' && !empty($claim['lease_token'])) {
            try {
                $this->reserveQuota($task, $owner);
                if ($task->fresh()->status !== 'searching') {
                    $central->complete($task->gtin, $claim['lease_token'], 'failed');
                    return;
                }
                $product = $research->research($task->gtin);
                $central->complete($task->gtin, $claim['lease_token'], $product ? 'found' : 'not_found', $product);
            } catch (\Throwable $e) {
                try { $central->complete($task->gtin, $claim['lease_token'], 'failed'); } catch (\Throwable $ignored) {}
                throw $e;
            }
        } else {
            throw new ProductFactsException('El catálogo compartido no pudo reservar la búsqueda. Podés continuar manualmente.');
        }
        if (!is_array($product) || empty($product['name'])) {
            $this->transition($task, ['status' => 'not_found', 'message' => 'No encontramos una coincidencia verificada para este código.']);
            return;
        }
        $classification = null;
        if ($task->fresh()->status !== 'searching') {
            return;
        }
        try { $classification = $research->classify($product); } catch (\Throwable $ignored) {}
        // Una respuesta que terminó después de cancelar nunca vuelve a aparecer como propuesta.
        ProductEnrichment::where('id', $task->id)->where('status', 'searching')->update([
            'status' => 'found', 'product' => json_encode($product),
            'classification' => $classification ? json_encode($classification) : null,
            'cache_hit' => $cacheHit, 'message' => $cacheHit ? 'Ficha recuperada del catálogo compartido.' : 'Encontramos información para revisar.',
        ]);
    }

    protected function transition(ProductEnrichment $task, array $values)
    {
        ProductEnrichment::where('id', $task->id)->where('status', 'searching')->update($values);
    }

    protected function reserveQuota(ProductEnrichment $task, User $owner)
    {
        DB::transaction(function () use ($task, $owner) {
            User::where('id', $owner->id)->lockForUpdate()->firstOrFail();
            if (BusquedaPorCodigoDeBarrasIaHelper::busquedas_web_de_hoy($owner->id) >= BusquedaPorCodigoDeBarrasIaHelper::tope_diario($owner)) {
                throw new ProductFactsException('Llegaste al tope diario de búsquedas por código de barras. Podés continuar manualmente; mañana se renueva.');
            }
            $attempts = $task->search_started_at && substr((string) $task->search_started_at, 0, 10) === now()->toDateString() ? $task->search_attempts + 1 : 1;
            $task->update(['search_attempts' => $attempts, 'search_started_at' => now()]);
        });
    }
}
