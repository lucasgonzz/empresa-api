<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Jobs\ProcessProductEnrichmentJob;
use App\Models\Article;
use App\Models\ProductEnrichment;
use App\Services\ProductFacts\Gtin;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductEnrichmentController extends Controller
{
    protected function checkPermission()
    {
        abort_unless(PermisosIaHelper::puede($this->user(false), 'article.store'), 403, 'No tenés permiso para crear artículos.');
        abort_unless((int) config('app.USER_ID') === (int) $this->userId(), 403, 'La búsqueda compartida no está configurada para este comercio. Podés continuar manualmente.');
    }

    public function store(Request $request)
    {
        $this->checkPermission();
        $request->validate(['bar_code' => 'required|string|max:14']);
        $gtin = Gtin::canonical($request->bar_code);
        if (!$gtin) {
            return response()->json(['status' => 'invalid', 'message' => 'Este código no es un GTIN comercial válido. Podés completar el artículo manualmente.'], 422);
        }
        if (Article::where('user_id', $this->userId())->whereIn('bar_code', Gtin::equivalents($gtin))->exists()) {
            return response()->json(['status' => 'duplicate', 'message' => 'Este código ya existe en tu catálogo.'], 409);
        }
        $existing = ProductEnrichment::where('user_id', $this->userId())->where('auth_user_id', $this->userId(false))
            ->where('gtin', $gtin)->whereIn('status', ['queued', 'searching', 'waiting'])->where('created_at', '>=', now()->subMinutes(8))->latest('id')->first();
        if ($existing) {
            return response()->json($existing->payload(), 202);
        }
        $task = ProductEnrichment::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->userId(),
            'auth_user_id' => $this->userId(false), 'bar_code' => trim($request->bar_code), 'gtin' => $gtin,
            'status' => 'queued', 'message' => 'Búsqueda en cola. Podés seguir completando el artículo.']);
        // Nunca ejecutar trabajo pago dentro del request aunque un entorno tenga QUEUE_CONNECTION=sync.
        $connection = config('queue.default') === 'sync' ? 'database' : config('queue.default');
        try {
            ProcessProductEnrichmentJob::dispatch($task->id)->onConnection($connection)->afterCommit();
        } catch (\Throwable $e) {
            $task->update(['status' => 'failed', 'message' => 'No se pudo iniciar la búsqueda. Podés continuar manualmente.']);
        }
        return response()->json($task->payload(), 202);
    }

    protected function task($uuid)
    {
        $this->checkPermission();
        return ProductEnrichment::where('user_id', $this->userId())->where('auth_user_id', $this->userId(false))->where('uuid', $uuid)->firstOrFail();
    }

    public function show($uuid)
    {
        $task = $this->task($uuid);
        if (in_array($task->status, ['queued', 'searching', 'waiting'], true) && $task->created_at->lt(now()->subMinutes(8))) {
            ProductEnrichment::where('id', $task->id)->whereIn('status', ['queued', 'searching', 'waiting'])->update([
                'status' => 'failed', 'message' => 'La búsqueda demoró demasiado. Podés continuar manualmente o reintentar.',
            ]);
            $task->refresh();
        }
        return response()->json($task->payload());
    }

    public function destroy($uuid)
    {
        $task = $this->task($uuid);
        $task->update(['status' => 'cancelled', 'message' => 'Seguís con la carga manual.']);
        return response()->json($task->payload());
    }
}
