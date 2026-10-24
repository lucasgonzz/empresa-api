<?php

namespace Tests\Feature\AgentesIa;

use App\Models\ExtencionEmpresa;
use Database\Seeders\ExtencionAgentesIaSeeder;

/**
 * Le da la extensión única «Agentes de IA» (`agentes_ia`) al comercio de un fixture.
 *
 * Misión agentes-ia-extension-unica (22/10/2026): el módulo de WhatsApp, el bot, el asistente, el escaneo de
 * facturas y las sugerencias pasaron a exigir `agentes_ia`. Las suites que arman su PROPIO comercio con
 * `User::create()` (la del bot de WhatsApp, por ejemplo) y no hablan de la extensión sino de otra cosa
 * —persistir un adjunto, agrupar mensajes seguidos, confirmar una respuesta— usan este trait para que el
 * comercio de prueba tenga el módulo, igual que lo tiene un cliente real que lo paga. No cambia ninguna
 * aserción: la que exige el 403 sin la extensión vive en `tests/Feature/AgentesIa/`.
 *
 * Siembra la fila del catálogo con el seeder real si la base del slot todavía no la tiene.
 */
trait OtorgaAgentesIa
{
    /**
     * Asigna `agentes_ia` al usuario (sin duplicar el vínculo) y recarga sus extensiones.
     *
     * @param  \App\Models\User  $usuario  El dueño del comercio de prueba.
     * @return void
     */
    protected function otorgar_agentes_ia($usuario)
    {
        $fila = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (is_null($fila)) {
            (new ExtencionAgentesIaSeeder())->run();
            $fila = ExtencionEmpresa::where('slug', 'agentes_ia')->first();
        }

        $usuario->extencions()->syncWithoutDetaching([$fila->id]);
        $usuario->load('extencions');
    }
}
