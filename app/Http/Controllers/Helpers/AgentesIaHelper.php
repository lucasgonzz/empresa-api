<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Models\ExtencionEmpresa;
use App\Models\User;

/**
 * La extensión única «Agentes de IA» (slug `agentes_ia`) — misión agentes-ia-extension-unica (22/10/2026).
 *
 * Una sola extensión absorbe toda la IA del sistema: el agente personal (chat y WhatsApp), el escaneo de
 * facturas de compra, el módulo de WhatsApp y el bot a clientes, la asignación automática de imágenes, las
 * descripciones y fichas por código de barras, la conexión al MCP, las sugerencias y las categorías con IA.
 * Reemplaza a `asistente_ia`, `escaneo_factura_compra`, `whatsapp_ia`, `sugerencias_inteligentes`,
 * `sugerencias_compras` y `whatsapp` (las filas viejas se marcan `en_desuso`, no se borran).
 *
 * Este helper concentra lo que NO pasa por el middleware `check_extencion_empresa`: los lugares donde no hay
 * sesión (el webhook de Kapso, los jobs de la cola, los comandos del scheduler) y la puerta que usa el admin
 * para prender o apagar la extensión de un cliente.
 *
 * 🔴 Todo se mira sobre el DUEÑO y nunca sobre `Auth`: en un job o en un webhook no hay sesión, y ahí `Auth`
 * (o el `USER_ID` de config) puede ser otro comercio.
 */
class AgentesIaHelper
{
    /** Slug de la extensión única. */
    const SLUG = 'agentes_ia';

    /** Nombre visible en el configurador de extensiones. */
    const NOMBRE = 'Agentes de IA';

    /**
     * Slugs de las extensiones de IA que esta reemplaza. Se marcan `en_desuso` (el pivote no tiene FK ni
     * unique y un rollback las necesita): nadie las lee.
     *
     * @var string[]
     */
    const SLUGS_REEMPLAZADOS = [
        'asistente_ia',
        'escaneo_factura_compra',
        'whatsapp_ia',
        'sugerencias_inteligentes',
        'sugerencias_compras',
        'whatsapp',
    ];

    /**
     * true si ese dueño tiene la extensión. Sin dueño devuelve false (no cae en `Auth`).
     *
     * @param  \App\Models\User|null  $dueno
     * @return bool
     */
    public static function tiene($dueno)
    {
        return !is_null($dueno) && UserHelper::hasExtencion(self::SLUG, $dueno);
    }

    /**
     * true si el comercio de ESTA instancia tiene la extensión. El dueño se resuelve con
     * `AsistenteCanalHelper::dueno()` (`config('app.USER_ID')`, o el único dueño de la base). Si no se puede
     * resolver, devuelve false: ante la duda, no se procesa.
     *
     * Es lo que usa el webhook de Kapso, que no tiene sesión.
     *
     * @return bool
     */
    public static function instancia_tiene()
    {
        return self::tiene(AsistenteCanalHelper::dueno());
    }

    /**
     * true si el comercio al que pertenece ese usuario (el dueño, o el dueño de ese empleado) tiene la
     * extensión. Es lo que revalidan los jobs largos al arrancar: el usuario que viaja en el job se resuelve
     * a su dueño, sin mirar `Auth`.
     *
     * @param  int|string|null  $user_id  Id de un usuario (dueño o empleado) del comercio.
     * @return bool
     */
    public static function tiene_el_comercio_de($user_id)
    {
        if (empty($user_id)) {

            return false;
        }

        $usuario = User::find((int) $user_id);

        if (is_null($usuario)) {

            return false;
        }

        /* Un empleado hereda la extensión de su dueño: las extensiones son de la EMPRESA. */
        if (!empty($usuario->owner_id)) {

            $usuario = User::find((int) $usuario->owner_id);
        }

        return self::tiene($usuario);
    }

    /**
     * La fila del catálogo de extensiones, o null si todavía no se sembró (instancia vieja sin el seeder).
     *
     * @return \App\Models\ExtencionEmpresa|null
     */
    public static function extension()
    {
        return ExtencionEmpresa::where('slug', self::SLUG)->first();
    }

    /**
     * Prende o apaga la extensión del dueño. Idempotente: prender dos veces no duplica el vínculo
     * (`syncWithoutDetaching`) y apagar una que no está no hace nada. Solo toca `agentes_ia`: las demás
     * extensiones del dueño quedan como estaban.
     *
     * @param  \App\Models\User  $dueno
     * @param  bool  $activa
     * @return bool|null  El estado en que queda, o null si la fila del catálogo no existe.
     */
    public static function fijar($dueno, $activa)
    {
        $extension = self::extension();

        if (is_null($extension)) {

            return null;
        }

        if ($activa) {

            $dueno->extencions()->syncWithoutDetaching([$extension->id]);
        } else {

            $dueno->extencions()->detach([$extension->id]);
        }

        /* La relación puede estar cacheada en el modelo: se recarga para que el estado devuelto sea el real. */
        $dueno->unsetRelation('extencions');

        return self::tiene($dueno);
    }
}
