<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice en `images.imageable_id` (misión imagenes-catalogo-completo, agregado del 27/9/2026, plan
 * §12.1, extra 3).
 *
 * El "sin imagen" de todo el catálogo (y el filtro "Sin imágenes" del listado) es un NOT EXISTS por
 * artículo contra `images`; sin índice, cada artículo recorre la tabla entera, y en catálogos de 13
 * mil artículos eso es la previa lenta y el listado filtrado más lento todavía. `images` nació sin
 * ningún índice sobre la relación polimórfica (2023_01_31_112640_create_images_table).
 *
 * Es solo un índice: no cambia ningún dato ni nada de lo que ve la tienda.
 *
 * Con guard: si la columna ya tiene un índice que arranca por ella (alguien lo agregó a mano en un
 * cliente, o una base con otra historia), no se agrega otro igual. Y el down() solo saca el índice
 * que puso esta migración (por su nombre), nunca uno ajeno.
 */
class AddImageableIdIndexToImagesTable extends Migration
{
    /** Nombre del índice que agrega esta migración. */
    const NOMBRE_DEL_INDICE = 'img_imageable_id_idx';

    /**
     * Agrega el índice si la columna no tiene ya uno que arranque por ella.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('images') || !Schema::hasColumn('images', 'imageable_id')) {
            return;
        }

        if ($this->hay_indice_que_arranca_por('imageable_id')) {
            return;
        }

        Schema::table('images', function (Blueprint $table) {
            $table->index('imageable_id', self::NOMBRE_DEL_INDICE);
        });
    }

    /**
     * Saca el índice, solo si es el de esta migración.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('images') || !$this->existe_el_indice(self::NOMBRE_DEL_INDICE)) {
            return;
        }

        Schema::table('images', function (Blueprint $table) {
            $table->dropIndex(self::NOMBRE_DEL_INDICE);
        });
    }

    /**
     * ¿Algún índice de `images` tiene esta columna como PRIMERA? (uno que la tenga segunda no sirve
     * para buscar por ella sola).
     *
     * @param  string $columna
     * @return bool
     */
    protected function hay_indice_que_arranca_por($columna)
    {
        $filas = DB::select('SHOW INDEX FROM `images` WHERE Seq_in_index = 1 AND Column_name = ?', [$columna]);

        return count($filas) > 0;
    }

    /**
     * ¿Existe un índice de `images` con este nombre?
     *
     * @param  string $nombre
     * @return bool
     */
    protected function existe_el_indice($nombre)
    {
        $filas = DB::select('SHOW INDEX FROM `images` WHERE Key_name = ?', [$nombre]);

        return count($filas) > 0;
    }
}
