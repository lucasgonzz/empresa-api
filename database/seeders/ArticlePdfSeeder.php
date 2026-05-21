<?php

namespace Database\Seeders;

use App\Models\ArticlePdf;
use Illuminate\Database\Seeder;

/**
 * Crea la plantilla ArticlePdf "Oferta" por defecto para el usuario configurado en app.USER_ID.
 *
 * Usa updateOrCreate para ser idempotente: ejecutar múltiples veces no genera duplicados.
 */
class ArticlePdfSeeder extends Seeder
{
    /**
     * Inserta o actualiza la plantilla de oferta para el usuario definido en config.
     *
     * @return void
     */
    public function run()
    {
        /* Datos que identifican unívocamente la plantilla (clave de búsqueda) */
        $search_key = [
            'user_id' => config('app.USER_ID'),
            'nombre'  => 'Oferta',
        ];

        /* Datos del registro a crear o actualizar */
        $values = [
            'titulo'                 => 'OFERTA',
            'mostrar_precio_anterior' => 1,
            'texto_personalizado'    => 'Oferta hasta agotar stock',
            'motrar_fecha_impresion' => 1,
        ];

        ArticlePdf::updateOrCreate($search_key, $values);
    }
}
