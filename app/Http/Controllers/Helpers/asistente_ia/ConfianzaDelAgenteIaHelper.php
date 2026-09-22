<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Los modos de autonomía del agente (`users.agente_confianza`), en un solo lugar (misión
 * asistente-capacidades-y-hilos, 22/9/2026).
 *
 * Eran dos —`cauteloso` (toda carga deja tarjeta) y `resuelto` (las cuatro inocuas se hacen
 * solas)— y ahora son TRES: `directo` ejecuta en el acto casi todo lo que el agente puede cargar,
 * sin dejar tarjeta.
 *
 * 🔴 POR QUÉ NACIÓ EL TERCER MODO. El 22/9/2026, en demo3, Lucas le pidió tres veces explícitas al
 * agente que dejara de pedir confirmación ("Si! No quiero que me pidas tanta confirmación, crea el
 * proveedor y la compra ya") y el agente contestó, en el mensaje siguiente, "Quedó pendiente de tu
 * confirmación… Decime que sí y lo creo". La instrucción de la persona NO tenía ningún efecto,
 * porque el prompt decía literalmente que esas cargas no se hacen solas "ni porque la persona lo
 * pida sin preguntar". La decisión de Lucas fue que eso se prenda desde la CONFIGURACIÓN —una vez,
 * a conciencia— y no con una frase suelta en medio de una conversación, que cualquier texto de una
 * tool podría imitar.
 *
 * 🔴 HAY DOS LECTURAS DE LA COLUMNA Y NO SON LA MISMA, a propósito:
 *
 *  - `guardada()` devuelve el modo TAL COMO ESTÁ GUARDADO, y cadena vacía si el dueño es nulo o la
 *    columna vino vacía o fuera del enum. Es la que usa todo lo que EJECUTA (la puerta de
 *    auto-confirmación de HerramientasDeCarga y los bloques de prompt): un valor que no se entiende
 *    nunca puede terminar auto-ejecutando una carga.
 *  - `con_default()` cae al default cuando no hay nada guardado. Es la que usa lo que MUESTRA
 *    (AsistenteConfigController), que tiene que contestar algo aunque la columna esté en null.
 *
 * Las dos conservan exactamente el comportamiento que cada lado ya tenía antes de esta misión.
 *
 * PHP 7.4: sin enum, sin match, sin union types.
 */
class ConfianzaDelAgenteIaHelper
{
    /** Toda carga deja tarjeta para que la persona la confirme con el dedo. */
    const CAUTELOSO = 'cauteloso';

    /** Las cargas inocuas y reversibles se hacen solas; el resto deja tarjeta. */
    const RESUELTO = 'resuelto';

    /** Casi todo se ejecuta en el acto, sin tarjeta (ver HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO). */
    const DIRECTO = 'directo';

    /** Los modos válidos, del más cauto al más suelto. */
    const MODOS = [self::CAUTELOSO, self::RESUELTO, self::DIRECTO];

    /**
     * El default, que coincide con el de la columna `users.agente_confianza`.
     *
     * 🔴 SIGUE SIENDO `resuelto`: nadie cambia de comportamiento porque esta misión exista. El modo
     * directo se prende a mano desde la configuración del asistente.
     */
    const POR_DEFECTO = self::RESUELTO;

    /**
     * El modo tal como está guardado en el dueño, o cadena vacía si no hay nada legible.
     *
     * @param  \App\Models\User|null  $owner
     * @return string
     */
    public static function guardada($owner): string
    {
        $valor = is_null($owner) ? '' : (string) $owner->agente_confianza;

        return in_array($valor, self::MODOS, true) ? $valor : '';
    }

    /**
     * El modo guardado, cayendo al default si no hay nada legible.
     *
     * @param  \App\Models\User|null  $owner
     * @return string
     */
    public static function con_default($owner): string
    {
        $guardada = self::guardada($owner);

        return $guardada === '' ? self::POR_DEFECTO : $guardada;
    }

    /**
     * true si el dueño tiene el modo directo GUARDADO (nunca por default).
     *
     * @param  \App\Models\User|null  $owner
     * @return bool
     */
    public static function es_directo($owner): bool
    {
        return self::guardada($owner) === self::DIRECTO;
    }
}
