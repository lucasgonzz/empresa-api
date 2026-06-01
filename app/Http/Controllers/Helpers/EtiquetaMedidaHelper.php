<?php

namespace App\Http\Controllers\Helpers;

use App\Models\EtiquetaMedida;

/**
 * Helper para medidas predeterminadas de etiquetas por usuario (unidades en mm).
 */
class EtiquetaMedidaHelper
{
    /**
     * Medidas estándar (mm). Si nombre es null, el front muestra "Ancho mm × Alto mm".
     *
     * @var array<int, array<string, mixed>>
     */
    public const DEFAULT_MEASURES = [
        ['nombre' => null, 'ancho' => 30, 'alto' => 15],
        // ['nombre' => null, 'ancho' => 42, 'alto' => 29],
        // ['nombre' => null, 'ancho' => 44, 'alto' => 55],
        ['nombre' => null, 'ancho' => 30, 'alto' => 20],
        ['nombre' => null, 'ancho' => 50, 'alto' => 25],
        // ['nombre' => null, 'ancho' => 55, 'alto' => 44],
        ['nombre' => null, 'ancho' => 80, 'alto' => 25],
        ['nombre' => null, 'ancho' => 80, 'alto' => 50],
        // ['nombre' => '100mm x 50mm Vertical', 'ancho' => 100, 'alto' => 50],
        ['nombre' => null, 'ancho' => 100, 'alto' => 75],
        // ['nombre' => null, 'ancho' => 50, 'alto' => 20],
        // ['nombre' => '50mm x 25mm con auxiliares', 'ancho' => 50, 'alto' => 25],
        // ['nombre' => null, 'ancho' => 62, 'alto' => 29],
    ];

    /**
     * Inserta las medidas predeterminadas para un usuario dueño (owner) si aún no existen.
     *
     * @param int $user_id ID del usuario owner (owner_id null)
     *
     * @return void
     */
    public static function seed_defaults_for_user($user_id)
    {
        foreach (self::DEFAULT_MEASURES as $medida) {
            $match = [
                'user_id' => $user_id,
                'es_predeterminada' => true,
            ];

            if (!empty($medida['nombre'])) {
                $match['nombre'] = $medida['nombre'];
            } else {
                $match['ancho'] = $medida['ancho'];
                $match['alto'] = $medida['alto'];
                $match['nombre'] = null;
            }

            EtiquetaMedida::firstOrCreate($match, [
                'ancho' => $medida['ancho'],
                'alto' => $medida['alto'],
                'nombre' => !empty($medida['nombre']) ? $medida['nombre'] : null,
                'es_predeterminada' => true,
            ]);
        }
    }

    /**
     * Etiqueta legible para una medida (nombre o dimensiones en mm).
     *
     * @param EtiquetaMedida|object $medida
     *
     * @return string
     */
    public static function label_for_medida($medida)
    {
        if (!empty($medida->nombre)) {
            return $medida->nombre;
        }

        return (int) $medida->ancho.'mm × '.(int) $medida->alto.'mm';
    }
}
