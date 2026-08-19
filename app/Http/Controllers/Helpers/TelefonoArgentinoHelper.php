<?php

namespace App\Http\Controllers\Helpers;

/**
 * Normalizador E.164 de teléfonos argentinos: convierte lo que un comerciante
 * cargó a mano en `clients.phone` (o en `buyers.phone`) al número que WhatsApp
 * necesita para abrir un chat.
 *
 * 🔴 POR QUÉ ESTE ARCHIVO EXISTE Y NO ES UN MÉTODO DE WhatsappPhoneHelper:
 * `WhatsappPhoneHelper::normalize()` (WhatsappPhoneHelper.php:22-31) SOLO saca
 * los no-dígitos — no agrega el 54, no agrega el 9 de celular, no saca el 15 ni
 * el 0 de larga distancia — y tiene OTROS CONSUMIDORES (el módulo de WhatsApp
 * del grupo 137) cuyo contrato es exactamente ese: "quedate solo con los
 * dígitos". `matches()` (:43-61) compara los últimos 10 dígitos, pero es para
 * COMPARAR dos números, no para CONSTRUIR uno. Un `clients.phone` cargado como
 * `03444-15-622139` pasado por normalize() genera hoy un link que no abre
 * ningún chat. **No tocar normalize(): el comportamiento nuevo va acá.** Y un
 * normalizador E.164 es además más general que WhatsApp: sirve igual para un
 * SMS o para mostrar el número prolijo.
 *
 * ── LA REGLA, DERIVADA DE TELÉFONOS REALES ────────────────────────────────
 * Medida el 15/8/2026 sobre `prueba.clients.phone`. Los cuatro casos existen en
 * la base y son los tests de este helper:
 *
 *   1126322965  → 10 díg, área 11 (CABA) + 8    → 5491126322965
 *   2355-451028 → 10 díg, área 2355 + 6         → 5492355451028
 *   341563-4190 → 10 díg, área 341 (Rosario) +7 → 5493415634190
 *   92966497980 → 11 díg, ya trae el 9          → 5492966497980
 *
 * Pasos: sacar no-dígitos → sacar el `54` de país si ya venía → sacar el `0`
 * inicial de larga distancia → sacar el `15` que quedó después del código de
 * área → 10 dígitos anteponen `549`, 11 que arrancan con `9` anteponen `54`.
 *
 * 🔴 CUALQUIER OTRO LARGO DEVUELVE null, y el que llama NO muestra el botón. Es
 * preferible no ofrecer el botón a ofrecer uno que abre WhatsApp en la nada: un
 * número corto (un interno, un `15 622139` sin área) no se puede completar sin
 * adivinar el código de área, y adivinarlo manda el mensaje a un desconocido.
 * No "arreglar" esto rellenando con el 11 de CABA por defecto.
 */
class TelefonoArgentinoHelper
{
    /** Código de país de Argentina. */
    const PREFIJO_PAIS = '54';

    /** El 9 que E.164 pide para los celulares argentinos. */
    const PREFIJO_CELULAR = '9';

    /** Código de área + número, sin país y sin el 9: 11+8, 3xx+7 o 3xxx+6. */
    const LARGO_NACIONAL = 10;

    /** Largo del número nacional con el 15 de celular local todavía adentro. */
    const LARGO_CON_15 = 12;

    /**
     * Devuelve el número en E.164 sin el `+` (así lo pide la URL de
     * api.whatsapp.com), o null si no se puede armar uno confiable.
     *
     * @param string|null $raw Teléfono en cualquier formato, como lo cargó el usuario.
     * @return string|null Ej: '5491126322965'. null = no hay link posible.
     */
    public static function a_e164($raw)
    {
        if (is_null($raw)) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', (string) $raw);

        if (is_null($digitos) || $digitos === '') {
            return null;
        }

        /*
         * El 54 de país se saca acá y se vuelve a poner al final: así los que ya
         * lo traían y los que no terminan en el MISMO formato, en vez de quedar
         * dos variantes del mismo número. El guard de largo importa: ningún
         * número nacional de 10 dígitos arranca con 54 (los códigos de área
         * empiezan con 11, 2 o 3), pero si apareciera uno, sacarle el prefijo lo
         * dejaría en 8 y el helper devuelve null en vez de destrozarlo.
         */
        if (strlen($digitos) > self::LARGO_NACIONAL
            && substr($digitos, 0, 2) === self::PREFIJO_PAIS) {

            $digitos = substr($digitos, 2);
        }

        /* El 0 de larga distancia nacional: no viaja en E.164. */
        if (substr($digitos, 0, 1) === '0') {
            $digitos = substr($digitos, 1);
        }

        $digitos = self::sacar_15_de_celular($digitos);

        if (strlen($digitos) === self::LARGO_NACIONAL) {
            return self::PREFIJO_PAIS . self::PREFIJO_CELULAR . $digitos;
        }

        /*
         * 11 dígitos arrancando con 9: el número ya trae el prefijo de celular
         * (medido: `92966497980` está así en la base). Solo falta el país.
         */
        if (strlen($digitos) === (self::LARGO_NACIONAL + 1)
            && substr($digitos, 0, 1) === self::PREFIJO_CELULAR) {

            return self::PREFIJO_PAIS . $digitos;
        }

        return null;
    }

    /**
     * Saca el `15` de celular local cuando aparece justo después del código de
     * área (`03444-15-622139` → `3444622139`).
     *
     * 🔴 SOLO se toca un número de 12 dígitos, y solo si sacar el 15 lo deja en
     * los 10 válidos. Es la diferencia entre normalizar y romper: `1126322965` y
     * `3415634190` son números REALES de 10 dígitos que contienen un `15`
     * adentro (`...5634190`), y una regla que borre el primer `15` que encuentre
     * los convierte en basura. El código de área argentino mide 2 (11), 3 (341)
     * o 4 (2355) dígitos: se prueban esos tres offsets y ninguno más.
     *
     * @param string $digitos Solo dígitos, sin país ni 0 inicial.
     * @return string
     */
    protected static function sacar_15_de_celular($digitos)
    {
        if (strlen($digitos) !== self::LARGO_CON_15) {
            return $digitos;
        }

        foreach ([2, 3, 4] as $largo_area) {
            if (substr($digitos, $largo_area, 2) === '15') {
                return substr($digitos, 0, $largo_area) . substr($digitos, $largo_area + 2);
            }
        }

        return $digitos;
    }
}
