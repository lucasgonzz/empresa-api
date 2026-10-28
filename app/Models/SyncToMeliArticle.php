<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncToMeliArticle extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('article');
    }

    /**
     * Columnas que el listado MUESTRA distinto de como están guardadas (ver "COLUMNAS MOSTRADAS"
     * en ColumnFiltersHelper): la lupa y el orden de "Estado" van por lo que se ve.
     *
     * Misión mercado-libre-pruebas (27/10/2026): la SPA muestra el estado traducido
     * (`sync_meli_estado_texto` de src/mixins/model_functions.js) y filtrar por "con error" no
     * encontraba nada porque se buscaba sobre `error`. Se cambia junto con esa función.
     *
     * @return array<string, string>  columna => expresión SQL de lo que la tabla muestra.
     */
    public static function columnas_mostradas_para_filtros()
    {
        $estado = "CASE"
            . " WHEN sync_to_meli_articles.status = 'pendiente' THEN 'En cola'"
            . " WHEN sync_to_meli_articles.status = 'en_progreso' THEN 'Enviando'"
            . " WHEN sync_to_meli_articles.status = 'exitosa' AND sync_to_meli_articles.error_message LIKE 'Aviso:%' THEN 'Con aviso'"
            . " WHEN sync_to_meli_articles.status = 'exitosa' THEN 'Actualizada'"
            . " WHEN sync_to_meli_articles.status = 'error' THEN 'Con error'"
            . " ELSE sync_to_meli_articles.status END";

        return [
            'status' => 'CONVERT((' . $estado . ') USING utf8mb4) COLLATE utf8mb4_unicode_ci',
        ];
    }

    function article() {
        return $this->belongsTo(Article::class);
    }
}
