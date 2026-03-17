<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class provider_codes_repetidos_en_distintos_providers extends Command
{
    /**
     * provider_codes_repetidos_en_distintos_providers {eliminar_repetidos?}
     *
     * - Detecta provider_code que aparece en más de un provider_id (para el mismo user_id)
     * - Lista los artículos involucrados
     * - Si eliminar_repetidos es true, conserva SOLO 1 artículo por provider_code (más reciente) y elimina el resto
     */
    protected $signature = 'provider_codes_repetidos_en_distintos_providers {eliminar_repetidos?}';

    protected $description = 'Busca provider_code repetidos en distintos provider_id y opcionalmente elimina duplicados conservando el más reciente por provider_code.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $eliminar_repetidos = (bool) $this->argument('eliminar_repetidos');
        $user_id = config('app.USER_ID');

        // 1) provider_code que aparece en 2+ provider_id distintos
        //    - excluimos provider_code null
        $duplicated_codes_query = Article::query()
            ->select('provider_code', DB::raw('COUNT(DISTINCT provider_id) as provider_qty'), DB::raw('COUNT(*) as total'))
            ->where('user_id', $user_id)
            ->whereNotNull('provider_code')
            ->groupBy('provider_code')
            ->having('provider_qty', '>', 1)
            ->orderBy('provider_code');

        $total_codes = (clone $duplicated_codes_query)->count(DB::raw('1'));
        $this->comment("provider_code presentes en múltiples provider_id: {$total_codes}");

        $processed = 0;

        foreach ($duplicated_codes_query->cursor() as $row) {
            $processed++;

            $provider_code = $row->provider_code;
            $provider_qty = (int) $row->provider_qty;
            $total = (int) $row->total;

            $this->comment("({$processed}/{$total_codes}) provider_code: {$provider_code} | provider_id distintos: {$provider_qty} | total artículos: {$total}");

            // 2) Traemos los artículos de ese provider_code (streaming)
            $articles_query = Article::query()
                ->select('id', 'name', 'provider_id', 'provider_code', 'bar_code', 'updated_at')
                ->where('user_id', $user_id)
                ->where('provider_code', $provider_code)
                ->orderBy('provider_id')
                ->orderByDesc('updated_at')
                ->orderByDesc('id');

            foreach ($articles_query->cursor() as $article) {
                $updated = $article->updated_at ? $article->updated_at->format('d/m/y') : 'N/A';
                $this->comment("- [provider_id {$article->provider_id}] {$article->name} (ID: {$article->id}), bar_code: {$article->bar_code}, updated_at: {$updated}");
            }

            if ($eliminar_repetidos) {
                // Regla de eliminación cruzada:
                // conservar 1 SOLO artículo por provider_code (el más reciente global)
                $keep_id = Article::query()
                    ->where('user_id', $user_id)
                    ->where('provider_code', $provider_code)
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->value('id');

                Article::query()
                    ->where('user_id', $user_id)
                    ->where('provider_code', $provider_code)
                    ->where('id', '!=', $keep_id)
                    ->select('id')
                    ->orderBy('id')
                    ->chunkById(1000, function ($rows) use ($provider_code, $keep_id) {
                        $ids_to_delete = $rows->pluck('id')->all();

                        if (!empty($ids_to_delete)) {
                            Article::whereIn('id', $ids_to_delete)->delete();
                            $this->comment("Eliminados " . count($ids_to_delete) . " artículos para provider_code {$provider_code}. Se conservó ID {$keep_id}.");
                        }
                    });
            }
        }

        if ($eliminar_repetidos) {
            $this->comment("Listo. Se eliminaron los duplicados cruzados por provider_code.");
        }

        return 0;
    }
}