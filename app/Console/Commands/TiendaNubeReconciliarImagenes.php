<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\TiendaNube\TiendaNubeProductImageService;
use Illuminate\Console\Command;

class TiendaNubeReconciliarImagenes extends Command
{
    protected $signature = 'tiendanube:reconciliar-imagenes {--usuario=} {--articulos=} {--limite=20} {--aplicar} {--evidencia=}';
    protected $description = 'Contrasta fotos locales y remotas; simula por defecto y separa los casos ambiguos.';

    public function handle()
    {
        $user = (string) $this->option('usuario');
        $ids = explode(',', (string) $this->option('articulos'));
        $limit = (string) $this->option('limite');
        if (!ctype_digit($user) || (int) $user < 1 || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100
            || count($ids) > (int) $limit || count(array_filter($ids, function ($id) { return !ctype_digit($id) || (int) $id < 1; })) > 0) {
            $this->error('Indicá dueño y lista explícita de artículos, con límite entre 1 y 100.');
            return 1;
        }
        $ids = array_values(array_unique($ids));
        $articles = Article::where('user_id', $user)->whereIn('id', $ids)->orderBy('id')->get();
        if ($articles->count() !== count($ids)) {
            $this->error('El alcance incluye artículos inexistentes o de otro dueño. No se procesó ninguno.');
            return 1;
        }
        $path = (string) $this->option('evidencia');
        if ($path === '' || !is_dir(dirname($path)) || file_exists($path)) {
            $this->error('Indicá un archivo nuevo de evidencia en un directorio existente.');
            return 1;
        }
        $handle = @fopen($path, 'x+');
        if (!$handle) {
            $this->error('No se pudo crear el archivo de evidencia.');
            return 1;
        }
        $write = function ($record) use ($handle) {
            $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('No se pudo guardar/verificar la evidencia; se frena la reparación.');
            }
            $end = ftell($handle);
            if (fseek($handle, $end - strlen($json)) !== 0 || fread($handle, strlen($json)) !== $json || fseek($handle, $end) !== 0) {
                throw new \RuntimeException('No se pudo releer/verificar la evidencia; se frena la reparación.');
            }
        };
        $failed = false;
        try {
            $write(['event' => 'alcance', 'at' => now()->toIso8601String(), 'database' => \DB::connection()->getDatabaseName(),
                'user_id' => (int) $user, 'article_ids' => $ids, 'apply' => (bool) $this->option('aplicar')]);
            $service = new TiendaNubeProductImageService((int) $user);
            foreach ($articles as $article) {
                try {
                    $report = $service->reconcile($article, (bool) $this->option('aplicar'), function ($before) use ($write) {
                        $write(['event' => 'antes', 'inventory' => $before]);
                    });
                    $write(['event' => 'despues', 'inventory' => $report]);
                    $ambiguous = collect($report['images'])->contains(function ($row) { return in_array($row['action'], ['ambigua', 'error'], true); });
                    $failed = $failed || $ambiguous;
                    $this->line('Artículo '.$article->id.': '.($ambiguous ? 'requiere revisión' : 'contraste completado'));
                } catch (\Throwable $e) {
                    $failed = true;
                    $write(['event' => 'error', 'article_id' => $article->id, 'message' => $e->getMessage()]);
                    // Un error puede llegar después de una primera foto confirmada. Dejamos
                    // tanto el estado local parcial como el remoto, sin volver a escribirlo.
                    $write(['event' => 'local_despues_del_error', 'article_id' => $article->id,
                        'images' => $article->images()->orderBy('id')->get(['id', 'hosting_url', 'tienda_nube_image_id', 'tiendanube_image_id'])->toArray()]);
                    try {
                        $write(['event' => 'remoto_despues_del_error', 'article_id' => $article->id, 'images' => $service->remote_images($article)]);
                    } catch (\Throwable $verification) {
                        $write(['event' => 'verificacion_posterior_pendiente', 'article_id' => $article->id, 'message' => $verification->getMessage()]);
                    }
                    $this->error('Artículo '.$article->id.': '.$e->getMessage());
                }
            }
        } finally {
            fclose($handle);
        }
        return $failed ? 1 : 0;
    }
}
