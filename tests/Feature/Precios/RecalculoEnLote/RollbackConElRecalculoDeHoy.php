<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Jobs\RollbackArticleImportHistory;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\User;

/**
 * 🔴 LA REFERENCIA del test de equivalencia del rollback (misión recalculo-precios-motor-rapido,
 * 28/9/2026): el job de reversión de una importación con el recalculo_precios_derivados() de
 * develop, TAL COMO ESTABA antes de pasarlo al motor en lote. setFinalPrice(..., true, ...) por
 * artículo, sobre los artículos releídos frescos de la base.
 *
 * Todo lo demás del job (restaurar columnas y relaciones, borrar los creados, marcar el
 * historial) es el código real: la única diferencia entre esta clase y el job es cómo se
 * recalculan los precios. No se "moderniza": dejaría de ser la referencia.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RollbackConElRecalculoDeHoy extends RollbackArticleImportHistory
{
    /**
     * El cuerpo de develop (commit base de la rama), sin una línea de más.
     *
     * @param  int[] $article_ids
     * @param  int   $user_id
     * @return void
     */
    protected function recalcular_precios_derivados(array $article_ids, int $user_id): void
    {
        $user = User::find($user_id);

        if (is_null($user)) {
            return;
        }

        $price_types = PriceType::where('user_id', $user->id)
                                    ->orderBy('position', 'ASC')
                                    ->get();

        Article::whereIn('id', $article_ids)->get()->each(function (Article $article) use ($user, $price_types) {
            ArticleHelper::setFinalPrice($article, $user->id, $user, $this->owner_user_id, true, $price_types);
        });
    }
}
