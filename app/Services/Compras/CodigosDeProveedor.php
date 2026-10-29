<?php

namespace App\Services\Compras;

use App\Models\Article;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/** La referencia pertenece al par artículo/proveedor, nunca al último proveedor del artículo. */
class CodigosDeProveedor
{
    public static function filtrar($query, $codigo, $provider_id = null, $parcial = false)
    {
        $operador = $parcial ? 'LIKE' : '=';
        $valor = $parcial ? '%' . (string) $codigo . '%' : (string) $codigo;

        return $query->where(function ($grupo) use ($provider_id, $operador, $valor) {
            $grupo->where(function ($legado) use ($provider_id, $operador, $valor) {
                $legado->where('articles.provider_code', $operador, $valor);
                if ($provider_id) {
                    $legado->where('articles.provider_id', (int) $provider_id);
                }
            })->orWhereExists(function ($relacion) use ($provider_id, $operador, $valor) {
                $relacion->select(DB::raw(1))->from('article_provider')
                    ->join('providers', 'providers.id', '=', 'article_provider.provider_id')
                    ->whereColumn('article_provider.article_id', 'articles.id')
                    ->whereColumn('providers.user_id', 'articles.user_id')
                    ->whereNull('providers.deleted_at')
                    ->where('article_provider.provider_code', $operador, $valor);
                if ($provider_id) {
                    $relacion->where('article_provider.provider_id', (int) $provider_id);
                }
            });
        });
    }

    public static function candidatos($owner_id, $provider_id, $codigo)
    {
        // Buscar desde el código indexado del pivot evita recorrer todo el catálogo por cada fila OCR.
        $ids = DB::table('article_provider')->join('articles', 'articles.id', '=', 'article_provider.article_id')
            ->join('providers', 'providers.id', '=', 'article_provider.provider_id')
            ->where('articles.user_id', $owner_id)->where('providers.user_id', $owner_id)
            ->whereNull('articles.deleted_at')->whereNull('providers.deleted_at')
            ->where('article_provider.provider_code', (string) $codigo);
        $legado = Article::where('user_id', $owner_id)->where('provider_code', (string) $codigo);
        if ($provider_id) {
            $ids->where('article_provider.provider_id', (int) $provider_id);
            $legado->where('provider_id', (int) $provider_id);
        }
        $candidatos = array_merge($ids->limit(2)->pluck('article_provider.article_id')->all(), $legado->limit(2)->pluck('id')->all());
        return Article::where('user_id', $owner_id)->whereIn('id', array_unique($candidatos))->orderBy('id')->limit(2)->get();
    }

    public static function codigo_para(Article $article, $provider_id)
    {
        if (!$provider_id) {
            return null;
        }
        $relacion = $article->relationLoaded('providers')
            ? $article->providers->firstWhere('id', (int) $provider_id)
            : $article->providers()->where('providers.id', (int) $provider_id)->first();
        if ($relacion && isset($relacion->pivot->provider_code) && trim((string) $relacion->pivot->provider_code) !== '') {
            return $relacion->pivot->provider_code;
        }
        return (int) $article->provider_id === (int) $provider_id ? $article->provider_code : null;
    }

    public static function nombre_exacto($nombre)
    {
        // La collation _ci de MySQL equipara Piña/Pina. Eso no alcanza para confirmar una compra.
        return mb_strtolower(trim((string) $nombre), 'UTF-8');
    }

    public static function resguardar_referencia_actual(Article $article)
    {
        if (!$article->provider_id || is_null($article->provider_code) || trim((string) $article->provider_code) === '') {
            return;
        }
        $article->loadMissing('providers');
        $relacion = $article->providers->firstWhere('id', (int) $article->provider_id);
        if ($relacion && !is_null($relacion->pivot->provider_code) && trim((string) $relacion->pivot->provider_code) !== '') {
            return;
        }
        if (!Provider::where('user_id', $article->user_id)->where('id', $article->provider_id)->exists()) {
            return;
        }
        // Algunos catálogos viejos sólo tenían el par escalar: salvarlo antes de cambiar de proveedor.
        if ($relacion) {
            DB::table('article_provider')->where('article_id', $article->id)->where('provider_id', $article->provider_id)
                ->where(function ($vacio) { $vacio->whereNull('provider_code')->orWhere('provider_code', ''); })
                ->update(['provider_code' => $article->provider_code, 'updated_at' => now()]);
        } else {
            DB::table('article_provider')->insertOrIgnore([
                'article_id' => $article->id, 'provider_id' => $article->provider_id,
                'provider_code' => $article->provider_code, 'cost' => $article->cost,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $article->unsetRelation('providers');
    }

    public static function articulos_de_carga_manual($filas, $owner_id, $provider_id)
    {
        if (!is_array($filas)) {
            return $filas;
        }
        $articulos = Article::where('user_id', $owner_id)->whereIn('id', array_column($filas, 'id'))->with('providers')->get()->keyBy('id');
        foreach ($filas as &$fila) {
            $article = isset($fila['id']) ? $articulos->get($fila['id']) : null;
            // El formulario sólo permite editar el código de los artículos nuevos/inactivos.
            // Una SPA anterior manda el escalar de los activos: no atribuirlo al proveedor elegido.
            if ($article && $article->status !== 'inactive') {
                $fila['provider_code'] = self::codigo_para($article, $provider_id);
            }
        }
        unset($fila);
        return $filas;
    }
}
