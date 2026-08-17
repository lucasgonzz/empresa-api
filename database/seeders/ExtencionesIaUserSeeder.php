<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Engancha al usuario dueño (config('app.USER_ID')) las cuatro extensiones de IA que la
 * semilla de datos de prueba necesita activas para poder mostrarse.
 *
 * UserHelper::hasExtencion() mira la relacion user->extencions, y los comandos
 * sugerencias:generar (GenerarSugerenciasDeStock), compras:generar
 * (GenerarSugerenciasDeCompra) y ofertas:generar (GenerarOfertasSugeridas) cortan en la
 * primera linea si la extension correspondiente no esta enganchada -- o sea que sin este
 * seeder los datos que siembra el resto de la semilla no se pueden ni mirar.
 *
 * Este seeder NO crea las filas de extencion_empresas (eso ya lo hacen
 * ExtencionSugerenciasInteligentesSeeder, ExtencionSugerenciasComprasSeeder,
 * ExtencionMotorDeOfertasSeeder y ExtencionTrackingBuyersSeeder, llamados desde
 * common_seeders() antes que UserSeeder). Solo arma el enganche user <-> extension.
 */
class ExtencionesIaUserSeeder extends Seeder
{
    /**
     * Slugs de extencion_empresas que la semilla de datos de prueba necesita activas
     * para el usuario dueño. No incluye 'asistente_ia' ni 'whatsapp_ia': son de otra
     * mision, no de la de datos de demo.
     *
     * @var array<int,string>
     */
    const SLUGS = [
        'sugerencias_inteligentes',
        'sugerencias_compras',
        'motor_de_ofertas',
        'tracking_buyers',
    ];

    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        $user = User::find(config('app.USER_ID'));

        // Sin usuario dueño todavia (por ejemplo si algun FOR_USER reordena los seeders)
        // no hay a quien engancharle nada: se sale callado en vez de romper la corrida
        // completa de DatabaseSeeder.
        if (! $user) {
            return;
        }

        foreach (self::SLUGS as $slug) {

            $extencion = ExtencionEmpresa::where('slug', $slug)->first();

            // La extension puede no estar sembrada todavia si el seeder que la registra
            // no corrio (orden distinto en algun FOR_USER puntual). Se saltea esa sola,
            // no se corta la corrida entera por una que falta.
            if (! $extencion) {
                continue;
            }

            // syncWithoutDetaching en vez de attach(): agrega la que falta y no toca las
            // que ya estan enganchadas (ni estas cuatro ni ninguna otra extension del
            // usuario). extencion_empresa_user no tiene constraint unique, asi que attach()
            // a secas duplicaria la fila si el seeder corre mas de una vez.
            $user->extencions()->syncWithoutDetaching([$extencion->id]);
        }
    }
}
