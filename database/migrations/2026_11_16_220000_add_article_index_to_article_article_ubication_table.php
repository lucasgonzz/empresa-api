<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddArticleIndexToArticleArticleUbicationTable extends Migration
{
    const INDICE = 'article_ubication_article_ubication_idx';
    const MARCA = 'comerciocity:ubicaciones_por_articulo:20261116';

    public function up()
    {
        if (!Schema::hasTable('article_article_ubication')) {
            return;
        }

        // Cada fullModel de artículo carga esta relación. En Servian, un recorrido masivo
        // hacía escanear unas 905.000 filas por GET y por PUT. Un índice cuyo primer campo
        // sea article_id ya resuelve esa búsqueda: no duplicamos uno agregado a mano.
        $indices = DB::select('SHOW INDEX FROM `article_article_ubication` WHERE Seq_in_index = 1 AND Column_name = ?', ['article_id']);
        foreach ($indices as $indice) {
            $visible = !isset($indice->Visible) || strtoupper($indice->Visible) !== 'NO';
            $habilitado = !isset($indice->Ignored) || strtoupper($indice->Ignored) !== 'YES';
            if ($visible && $habilitado) {
                return;
            }
        }

        // El comentario acredita la propiedad también cuando rollback corre en otro proceso.
        // Solo el nombre no distingue un homónimo que el cliente ya tuviera antes del upgrade.
        DB::statement('ALTER TABLE `article_article_ubication` ADD INDEX `' . self::INDICE
            . '` (`article_id`, `article_ubication_id`) COMMENT \'' . self::MARCA . '\'');
    }

    public function down()
    {
        if (!Schema::hasTable('article_article_ubication')) {
            return;
        }

        $filas = DB::select('SHOW INDEX FROM `article_article_ubication` WHERE Key_name = ?', [self::INDICE]);
        usort($filas, function ($a, $b) {
            return (int) $a->Seq_in_index - (int) $b->Seq_in_index;
        });
        $columnas = array_map(function ($fila) {
            return $fila->Column_name;
        }, $filas);

        // Una instalación puede tener índices equivalentes con otro nombre. El rollback
        // conserva esos índices y también un homónimo manual con otra definición.
        if ($columnas !== ['article_id', 'article_ubication_id']
            || (int) $filas[0]->Non_unique !== 1
            || $filas[0]->Index_comment !== self::MARCA) {
            return;
        }

        Schema::table('article_article_ubication', function (Blueprint $table) {
            $table->dropIndex(self::INDICE);
        });
    }
}
