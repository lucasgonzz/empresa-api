<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\OfertaComunicacionHelper;
use App\Models\Article;
use Illuminate\Support\Facades\Log;

/**
 * La tienda online del negocio, vista por el agente (misión agente-mejoras-uso-real, 29/10/2026, §4
 * E3.2 del plan): su dirección y si tiene artículos publicados.
 *
 * EL CASO REAL (CONV 24 de Ferre Total, 29/10/2026): para la promo de la bomba Pluvius el agente dijo
 * *"El negocio no tiene tienda online cargada"*, después *"el negocio sí tiene tienda, lo que no tiene
 * es publicado ese artículo"* y después *"entonces el artículo sí está publicado"*. Las tres cosas las
 * inventó a partir de UN dato: `users.online` (la dirección de la tienda) está vacío. Ferre Total tiene
 * 6.319 artículos publicados.
 *
 * DE DÓNDE SALE LA DIRECCIÓN. Se buscó en todo el sistema dónde puede estar guardada la URL de la
 * tienda: `online_configurations` NO la tiene (colores, textos, mails, login con Google, nada de la
 * dirección), `users.app_url` es la del sistema y `users.tienda_nube_url` no la lee nadie y es de otra
 * plataforma (los links de artículo de acá son de la tienda de ComercioCity). La única es `users.online`
 * (OfertaComunicacionHelper::url_de_la_tienda, la que usan las ofertas y los mails), y en una demo la de
 * DemoSetupHelper::online_por_defecto(). Si no hay ninguna, NO SE SABE la dirección: eso no dice nada de
 * si el negocio tiene tienda ni de si el artículo está publicado, y los textos lo tienen que decir así.
 *
 * PHP 7.4: sin match, sin nullsafe, sin argumentos nombrados.
 */
class TiendaDelNegocioIaHelper
{
    /**
     * La dirección de la tienda del negocio, o null si el sistema no la tiene guardada.
     *
     * @param  \App\Models\User|null  $owner
     * @return string|null
     */
    public static function url($owner)
    {
        $guardada = OfertaComunicacionHelper::url_de_la_tienda($owner);

        if (!is_null($guardada)) {

            return $guardada;
        }

        /*
         * Una demo sin `users.online` (el payload del armado no lo trajo) igual tiene su tienda fija en
         * comerciocity.store: es la misma regla con la que el armado de la demo la completa. En un
         * cliente real devuelve null.
         */
        $de_la_demo = DemoSetupHelper::online_por_defecto();

        if (is_string($de_la_demo) && trim($de_la_demo) !== '') {

            return substr(trim($de_la_demo), 0, 4) === 'http' ? trim($de_la_demo) : 'https://' . trim($de_la_demo);
        }

        return null;
    }

    /**
     * Cuántos artículos activos del negocio figuran publicados en la tienda (`articles.online = 1`). Es
     * lo que permite decir "tiene 6.319 artículos publicados" en vez de "no tiene tienda". Protegido:
     * si la cuenta falla, null (no se dice nada que no se sepa).
     *
     * @param  int  $owner_id
     * @return int|null
     */
    public static function articulos_publicados($owner_id)
    {
        try {

            return (int) Article::where('user_id', (int) $owner_id)->where('online', 1)->count();

        } catch (\Throwable $e) {

            Log::warning('TiendaDelNegocioIaHelper: no se pudieron contar los artículos publicados.', [
                'owner_id' => (int) $owner_id,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }
}
