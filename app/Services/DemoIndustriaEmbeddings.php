<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Vectores de cada rubro horneados con el mismo modelo que se usa para las consultas. */
class DemoIndustriaEmbeddings
{
    private static $archivos = [];

    public static function ruta($slug)
    {
        if (!preg_match('/^[a-z0-9_]+$/', (string) $slug)) {
            throw new \InvalidArgumentException('El rubro de los embeddings no es válido.');
        }
        return database_path('seeders/data/demo_' . $slug . '_embeddings.json');
    }

    public static function aplicar(Article $article, $slug)
    {
        $ruta = self::ruta($slug);
        if (!isset(self::$archivos[$ruta])) {
            self::$archivos[$ruta] = is_file($ruta) ? json_decode(file_get_contents($ruta), true) : [];
        }
        $archivo = self::$archivos[$ruta];
        $filas = is_array($archivo) && ($archivo['modelo'] ?? '') === 'text-embedding-3-small'
            && (int) ($archivo['dimensiones'] ?? 0) === 1536 ? ($archivo['articulos'] ?? []) : [];
        $fila = is_array($filas) && isset($filas[$article->name]) ? $filas[$article->name] : null;
        $article->load(['category', 'brand', 'descriptions']);
        $hash = sha1(app(ArticleEmbeddingService::class)->embedding_for_article($article));
        if (!is_array($fila) || ($fila['source_hash'] ?? '') !== $hash
            || !isset($fila['embedding']) || !is_array($fila['embedding']) || count($fila['embedding']) !== 1536) {
            Log::warning('DemoIndustriaEmbeddings: falta un vector vigente para ' . $slug . ': ' . $article->name);
            return false;
        }
        foreach ($fila['embedding'] as $valor) {
            if (!is_numeric($valor) || !is_finite((float) $valor)) {
                return false;
            }
        }
        app(ArticleEmbeddingService::class)->persistir_embedding($article->id, $fila['embedding']);
        DB::table('articles')->where('id', $article->id)->update([
            'embedding_source_hash' => $hash,
            'embedding_generated_at' => now(),
        ]);
        return true;
    }
}
