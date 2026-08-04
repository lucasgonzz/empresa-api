<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifica el estado de la columna de URL de imagen en la tabla `images` de la base
 * actual, para detectar instalaciones de la flota que quedaron mal provisionadas
 * (con la columna vieja `image_url` en vez de la canónica `hosting_url`).
 *
 * Grupo 215, prompt 01. Pensado para correrse manualmente contra cada base de la
 * flota después de un deploy, sin tener que entrar a mirar cada una a mano.
 */
class VerificarColumnaImagenes extends Command
{
    /**
     * Nombre y firma del comando de consola.
     *
     * @var string
     */
    protected $signature = 'imagenes:verificar-columna';

    /**
     * Descripción del comando.
     *
     * @var string
     */
    protected $description = 'Verifica si la tabla images tiene la columna canónica hosting_url o la columna vieja image_url, y cuántas filas tienen la URL vacía o nula.';

    /**
     * Ejecuta la verificación.
     *
     * @return int Código de salida: 0 si la base está sana (solo hosting_url, sin filas vacías), 1 si no.
     */
    public function handle()
    {
        // Existencia de cada una de las dos columnas posibles en la tabla images.
        $hasHostingUrl = Schema::hasColumn('images', 'hosting_url');
        $hasImageUrl = Schema::hasColumn('images', 'image_url');

        if ($hasHostingUrl && $hasImageUrl) {
            // Estado anómalo: existen las dos columnas a la vez.
            $this->error('La tabla images tiene AMBAS columnas (hosting_url y image_url). Hay que correr "php artisan migrate" en este server para unificarlas.');
            return 1;
        }

        if (!$hasHostingUrl && !$hasImageUrl) {
            // Estado inesperado que escapa al alcance de esta verificación (no debería pasar).
            $this->error('La tabla images no tiene ni hosting_url ni image_url. Revisar manualmente.');
            return 1;
        }

        if ($hasImageUrl) {
            // Columna vieja: la base todavía no corrió la migración de renombre.
            $this->error('La tabla images todavía usa la columna vieja image_url. Hay que correr "php artisan migrate" en este server.');
            return 1;
        }

        // A esta altura solo existe hosting_url: contar filas con URL vacía o nula.
        $emptyRowsCount = DB::table('images')
            ->where(function ($query) {
                $query->whereNull('hosting_url')->orWhere('hosting_url', '');
            })
            ->count();

        $this->info('La tabla images usa la columna canónica hosting_url. Filas con URL vacía o nula: ' . $emptyRowsCount . '.');

        if ($emptyRowsCount > 0) {
            $this->error('Hay filas con hosting_url vacía o nula. Revisar el origen de esos datos.');
            return 1;
        }

        $this->info('OK');
        return 0;
    }
}
