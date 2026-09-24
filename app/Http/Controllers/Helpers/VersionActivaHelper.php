<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;

/**
 * VersionActivaHelper
 *
 * Responde, ANTES de que nadie inicie sesión, cuál es la dirección del sistema activo de esta
 * instancia (misión redireccion-version-antes-del-login, 24/9/2026).
 *
 * Para qué existe: cuando un negocio entra por el subdominio viejo (el frente en desuso) y todavía
 * no se logueó, el SPA necesita saber en la pantalla de login a qué dirección mandarlo, sin esperar
 * a que escriba su documento y su clave en el frente viejo. La dirección activa vive en
 * `users.default_version` (la escribe el admin en cada upgrade con
 * AdminSync\UpdateDefaultVersionController); este helper solo la LEE.
 *
 * La regla, y la única que se aplica: se contesta el `default_version` únicamente cuando `users`
 * tiene EXACTAMENTE UN dueño (`owner_id` null). Con cero o con dos o más dueños se contesta null y
 * el SPA sigue como siempre (redirige después del login, con el mecanismo que ya existía).
 *
 * 🔴 NO SE USA config('app.USER_ID') ACÁ, y no es un olvido. Es la diferencia con
 * AsistenteCanalHelper::dueno() (asistente_ia/), que sí toma USER_ID primero. Aquel canal es el
 * WhatsApp del dueño: el mensaje llega firmado por el admin con la clave de UN cliente, así que la
 * instancia sabe a quién le habla y USER_ID es el desempate correcto. Acá es al revés: le habla un
 * visitante anónimo que todavía no dijo quién es. En la base compartida `u767360347_empresa` los
 * frentes genéricos (`empresa`, `beta`, `prueba`, `mc`) atienden a decenas de dueños distintos con
 * el mismo código (BELLO, Punto Diet, Kablan y unos cuarenta dormidos), así que el USER_ID de la
 * carpeta NO dice a quién le habla el que entra. Resolver por USER_ID mandaría a un negocio al
 * frente de otro antes de que escriba su documento: es el error caro, y por eso ante la duda
 * (varios dueños) se contesta null y no se adivina.
 *
 * Consecuencia declarada: las bases con más de un dueño (Fenix, Galván y la base compartida) NO
 * quedan cubiertas por la detección previa al login. Siguen con el mecanismo de después del login.
 *
 * Sin datos del negocio: lo único que sale de acá es una dirección que el usuario ya ve en la
 * barra de su navegador. Ni el nombre, ni el id, ni el mail del dueño.
 *
 * IMPORTANTE (PHP 7.4): nada de nullsafe (?->), match, str_contains ni sintaxis de PHP 8.
 */
class VersionActivaHelper
{
    /**
     * La dirección del sistema activo (`default_version`) del único dueño de esta base, o null si
     * no se puede decir con seguridad.
     *
     * Devuelve null cuando:
     *  - `users` no tiene ningún dueño (base vacía o solo con empleados);
     *  - `users` tiene dos o más dueños (no se sabe a cuál le habla el visitante, ver el docblock
     *    de la clase para el porqué de no usar USER_ID);
     *  - el único dueño no tiene `default_version` cargado (null, cadena vacía o solo espacios).
     *
     * Los EMPLEADOS (`owner_id` no nulo) no cuentan ni para decidir si el dueño es uno solo ni
     * para el valor: el admin les escribe el mismo `default_version` que al dueño, pero la
     * pregunta es "cuál es el frente activo de este negocio" y esa la contesta el dueño.
     *
     * @return string|null La dirección sin espacios alrededor, o null.
     */
    public static function default_version_del_unico_dueno()
    {
        /*
         * Solo los dueños, ordenados por id. `limit(2)` alcanza y sobra: no hace falta contarlos
         * a todos, solo distinguir "uno" de "más de uno" (y de "ninguno"). En una base compartida
         * con decenas de dueños esto evita traerse la lista entera en cada carga del login.
         * Se seleccionan únicamente las dos columnas que se necesitan.
         */
        $duenos = User::whereNull('owner_id')
                        ->orderBy('id')
                        ->limit(2)
                        ->get(['id', 'default_version']);

        /*
         * Con cero dueños no hay a quién preguntarle, y con dos o más hay que adivinar a cuál de
         * los negocios de la base le está hablando el que entra: se devuelve null en los dos
         * casos y el SPA sigue el camino de siempre.
         */
        if (count($duenos) !== 1) {

            return null;
        }

        // El único dueño de la base.
        $dueno = $duenos[0];

        // La dirección sin espacios alrededor. (string) cubre el null de una columna sin cargar.
        $default_version = trim((string) $dueno->default_version);

        // Una dirección vacía no le sirve al SPA para redirigir a nadie: se dice "sin información".
        if ($default_version === '') {

            return null;
        }

        return $default_version;
    }
}
