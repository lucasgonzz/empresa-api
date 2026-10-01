<?php

namespace App\Http\Controllers\Helpers\combo;

/**
 * Se lanza ADENTRO de la transacción de guardado de un combo cuando queda calculado, publicado en la
 * tienda (`online = 1`) y con precio calculado <= 0 (misión combos-calculados, F3).
 *
 * Es una excepción y no un `return` porque el rechazo se decide recién DESPUÉS de hacer la cuenta
 * (el precio depende de los artículos que se acaban de adjuntar) y el combo ya está escrito: lanzarla
 * dentro del `DB::transaction` deshace el guardado entero. El controlador la atrapa y responde 422 con
 * el mensaje. Los procesos de fondo (recálculos automáticos) NO pasan por acá: no rechazan, solo
 * dejan un warning (ver `ComboCalculadoHelper::recalcular_combos()`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ComboSinPrecioParaPublicarException extends \RuntimeException {

    /** El mensaje que ve la persona. */
    const MENSAJE = 'No se puede publicar en la tienda un combo con precio 0: algún artículo del combo no tiene precio cargado. Cargale el precio al artículo o desmarcá "Mostrar en la tienda".';

    public function __construct() {
        parent::__construct(self::MENSAJE);
    }
}
