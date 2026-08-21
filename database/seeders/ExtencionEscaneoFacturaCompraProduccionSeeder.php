<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Misión escaneo-factura-compra — el seeder que se corre a mano en las bases de producción que
 * ya existen.
 *
 *   php artisan migrate
 *   php artisan db:seed --class=Database\Seeders\ExtencionEscaneoFacturaCompraProduccionSeeder --force
 *
 * Las dos líneas, y en ese orden. Y `--force` porque con APP_ENV=production el comando pide
 * confirmación por teclado: sin ese flag, corrido desde un script termina sin hacer nada y sin
 * decir que no hizo nada.
 *
 * Hace lo mismo que ExtencionEscaneoFacturaCompraSeeder —del que sale el alta, así que el slug
 * y el nombre viven en un solo lugar— y agrega lo único que a una base de cliente le importa y
 * a una base nueva no: **decir por consola y por log si la fila quedó creada recién o ya
 * estaba**. Sin eso, correrlo sobre una base que ya la tenía y sobre una que no se ven
 * exactamente igual desde afuera, y no hay forma de saber si el despliegue hizo algo.
 *
 * 🔴 No asigna la extensión a ningún comercio: solo la deja disponible en el catálogo. Quién la
 * tiene prendida se decide después, desde el configurador de extensiones. Y no toca
 * `extencion_empresa_user`, así que se puede correr sobre una base con clientes adentro sin
 * cambiarle nada a nadie.
 */
class ExtencionEscaneoFacturaCompraProduccionSeeder extends Seeder
{
    /** Slug de la extensión que gatea todo el módulo. */
    const SLUG = 'escaneo_factura_compra';

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        /* Se mira ANTES de sembrar: después del firstOrCreate la fila existe siempre. */
        $ya_estaba = ExtencionEmpresa::where('slug', self::SLUG)->exists();

        $seeder = new ExtencionEscaneoFacturaCompraSeeder();
        $seeder->run();

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if ($ya_estaba) {
            $estado = 'ya estaba en el catalogo, no se toco';
        } else {
            $estado = 'creada';
        }

        $lineas = [
            'Extension ' . self::SLUG . ': ' . $estado . '.',
            'Nombre en el catalogo: ' . (is_null($extencion) ? '(no se pudo leer la fila)' : $extencion->name),
            'Filas con este slug: ' . ExtencionEmpresa::where('slug', self::SLUG)->count() . ' (tiene que ser 1).',
            'Falta asignarle la extension a cada comercio desde el configurador de extensiones.',
        ];

        foreach ($lineas as $linea) {
            Log::info('ExtencionEscaneoFacturaCompraProduccionSeeder: ' . $linea);

            /*
             * `command` es null si alguien instancia el seeder a mano en vez de correrlo por
             * artisan (los tests lo hacen), así que no se asume que exista.
             */
            if (!is_null($this->command)) {
                $this->command->info($linea);
            }
        }
    }
}
