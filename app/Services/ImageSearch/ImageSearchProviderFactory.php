<?php

namespace App\Services\ImageSearch;

use App\Models\User;

/**
 * Elige el proveedor de búsqueda de imágenes de una asignación inteligente (misión
 * imagenes-catalogo-completo, 27/9/2026): Serper si el servidor tiene SERPER_API_KEY, y si no,
 * Google Custom Search con las credenciales del dueño.
 *
 * La elección se guarda en la asignación al crearla (image_assignment_runs.proveedor) y el job la
 * respeta en cada tramo: una corrida larga no cambia de proveedor a mitad de camino.
 */
class ImageSearchProviderFactory
{
    /**
     * ¿El servidor tiene la clave de Serper cargada?
     *
     * @return bool
     */
    public static function serper_configurado()
    {
        return trim((string) config('services.serper.api_key')) !== '';
    }

    /**
     * El nombre del proveedor que le toca a una asignación nueva de este dueño.
     *
     * @param  \App\Models\User $owner
     * @return string  'serper' | 'google'
     */
    public static function nombre_para(User $owner)
    {
        return self::serper_configurado() ? 'serper' : 'google';
    }

    /**
     * El proveedor para este dueño: el que se pide por nombre (el de una asignación ya creada) o,
     * sin nombre, el que le toca hoy.
     *
     * @param  \App\Models\User $owner
     * @param  string|null      $nombre  'serper' | 'google' | null.
     * @return \App\Services\ImageSearch\ImageSearchProvider
     */
    public static function para(User $owner, $nombre = null)
    {
        $nombre = is_null($nombre) ? self::nombre_para($owner) : (string) $nombre;

        if ($nombre === 'serper') {
            return new SerperImageSearchProvider();
        }

        return new GoogleCustomSearchImageProvider($owner);
    }
}
