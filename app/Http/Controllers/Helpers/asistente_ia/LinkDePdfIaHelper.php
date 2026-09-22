<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Models\Budget;
use App\Models\Sale;
use App\Models\User;

/**
 * El link del PDF de una venta o de un presupuesto (misión asistente-capacidades-y-hilos,
 * 22/9/2026): los mensajes #72 y #74 del 22/9 en demo3, *"el PDF de la venta se genera desde la
 * pantalla de Ventas"* y *"no tengo un link para pasarte"*.
 *
 * Pedido textual de Lucas: *"Cuando le pido el pdf de un presupuesto o de una venta, quiero que me
 * pase el link del pdf, el mismo que se comparte por whatsapp"*.
 *
 * 🔴 ES UNA HERRAMIENTA DE LECTURA, NO DE CARGA. Devuelve la URL como texto en la respuesta; no
 * adjunta nada, no genera nada y no deja tarjeta. El PDF lo arma el servidor cuando alguien abre
 * ese link, igual que hoy.
 *
 * Las dos rutas son las MISMAS que ya comparte la pantalla por WhatsApp:
 *   - venta → `{api_url}/sale/pdf/{id}` (`routes/web.php:447`), que es lo que arma
 *     `SaleWhatsappSenderService::build_pdf_url()`.
 *   - presupuesto → `{api_url}/budget/pdf/{id}/1/0` (`routes/web.php:479`), con los dos flags
 *     obligatorios de la ruta; `1/0` (con precios, sin imágenes) es el combo que usa el botón de
 *     WhatsApp de la SPA (`common-vue/sale-print-buttons/WhatsappBtn.vue:186`).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * 🔴 LAS TRES COSAS QUE ESTE HELPER TIENE QUE HACER BIEN Y NADIE MÁS HACE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * 1. **`sale/ticket-pdf/{id}` ES UNA RUTA MUERTA.** `SaleController@ticketPdf` no existe en ningún
 *    lado del repo (el único hit de ese nombre es la propia línea de `routes/web.php:448`), así que
 *    esa URL da 500. `sale/ticket-raw` son bytes de impresora, y `sale/afip-ticket-a4-pdf/{id}`
 *    toma el **afip_ticket_id**, no el de la venta. Para "pasame el PDF" va `sale/pdf/{id}` y nada
 *    más: por eso acá hay una constante y no un parámetro.
 *
 * 2. **`users.api_url` ES NULLABLE Y TIENE HISTORIA DE VALORES CORRUPTOS** (`/public/public`;
 *    existe el comando `normalizar_api_url` justamente para eso). `build_pdf_url()` hace un
 *    `rtrim()` crudo: con la columna vacía devuelve `/sale/pdf/123`, un link sin dominio que nadie
 *    detecta hasta que el cliente dice que no le abre. Acá se limpia con
 *    `ApiUrlHelper::canonical_public_url()` (que saca los `/public` repetidos y vuelve a poner UNO
 *    si la instalación lo necesita) y, si no queda nada, se cae a `ApiUrlHelper::public_base()`.
 *    🔴 Y SI AUN ASÍ NO HAY URL, SE DICE — nunca se devuelve un link a medias.
 *
 * 3. **LAS DOS RUTAS SON PÚBLICAS, SIN AUTH NI CHEQUEO DE DUEÑO** (`routes/web.php` no tiene
 *    middleware en ese bloque, y `pdf()` hace `Sale::find($id)` / `Budget::find($id)` pelados). Eso
 *    es así hoy y esta misión no lo cambia, pero el asistente NO puede ser el que convierta ese
 *    agujero en una función: acá la pertenencia se chequea SIEMPRE antes de devolver la URL, y un
 *    número de otro comercio se contesta como "no encontré", no con un link que funciona.
 *
 * PHP 7.4: sin enum, sin match, sin operador nullsafe.
 */
class LinkDePdfIaHelper
{
    /** Los dos comprobantes que esta herramienta sabe linkear. */
    const TIPO_VENTA = 'venta';

    const TIPO_PRESUPUESTO = 'presupuesto';

    /**
     * Permiso para pasar el link del PDF de una venta: es el de ver el listado de Ventas, que es
     * desde donde la pantalla lo comparte (`src/router/routes.js`, ruta `/ventas`).
     */
    const PERMISO_VENTA = 'sale.index';

    /** Ídem para presupuestos: la ruta `/presupuestos` pide `can: 'budget.index'`. */
    const PERMISO_PRESUPUESTO = 'budget.index';

    /**
     * Los dos flags obligatorios de `budget/pdf/{id}/{with_prices}/{with_images}`: con precios y sin
     * imágenes, que es el combo que la SPA usa para compartir por WhatsApp.
     */
    const FLAGS_PRESUPUESTO = '1/0';

    /**
     * Devuelve el link público del PDF, o el motivo por el que no lo hay.
     *
     * @param  int  $owner_id
     * @param  \App\Models\AiConversation|null  $conversation  Para resolver quién pregunta (permisos).
     * @param  string  $tipo  'venta' o 'presupuesto'.
     * @param  mixed  $numero  El número del comprobante, como lo dice la persona.
     * @return array<string, mixed>
     */
    public static function link($owner_id, $conversation, $tipo, $numero): array
    {
        $tipo = trim((string) $tipo);

        if (!in_array($tipo, [self::TIPO_VENTA, self::TIPO_PRESUPUESTO], true)) {

            return self::no_se_puede('Decime si el PDF es de una venta o de un presupuesto.');
        }

        if (!is_numeric($numero) || (int) $numero <= 0) {

            return self::no_se_puede('Necesito el número de ' . $tipo . ' para armar el link.');
        }

        $numero = (int) $numero;

        $persona = is_null($conversation) ? null : ContextoDeCargaIa::de_la_conversacion($conversation)->persona;

        $permiso = $tipo === self::TIPO_VENTA ? self::PERMISO_VENTA : self::PERMISO_PRESUPUESTO;

        if (!PermisosIaHelper::puede($persona, $permiso)) {

            return self::no_se_puede('No tenés permiso para ver ' . ($tipo === self::TIPO_VENTA ? 'las ventas' : 'los presupuestos') . ' desde tu usuario.');
        }

        /*
         * 🔴 LA PERTENENCIA, ANTES DE ARMAR NADA. Las rutas del PDF son públicas: un id de otro
         * comercio devolvería su comprobante con precios. Se busca SIEMPRE dentro del dueño de esta
         * conversación, y por `num` (el número que la persona ve), nunca por el id de la base.
         */
        if ($tipo === self::TIPO_VENTA) {

            $modelo = Sale::where('user_id', (int) $owner_id)->where('num', $numero)->first(['id', 'num', 'total']);

        } else {

            $modelo = Budget::where('user_id', (int) $owner_id)->where('num', $numero)->first(['id', 'num', 'total']);
        }

        if (is_null($modelo)) {

            return self::no_se_puede(
                'No encontré ' . ($tipo === self::TIPO_VENTA ? 'ninguna venta' : 'ningún presupuesto') . ' N° ' . $numero . ' en este negocio.'
            );
        }

        $base = self::base_publica($owner_id);

        if ($base === '') {

            return self::no_se_puede(
                'Este sistema no tiene cargada la dirección pública de su API, así que el link saldría sin dominio y no le abriría a nadie. '
                . 'Decíle al dueño que lo avise a ComercioCity: se corrige con el comando normalizar_api_url.'
            );
        }

        $ruta = $tipo === self::TIPO_VENTA
            ? '/sale/pdf/' . (int) $modelo->id
            : '/budget/pdf/' . (int) $modelo->id . '/' . self::FLAGS_PRESUPUESTO;

        return [
            'ok'         => true,
            'tipo'       => $tipo,
            'numero'     => (int) $modelo->num,
            'total'      => is_null($modelo->total) ? null : (float) $modelo->total,
            'link'       => $base . $ruta,
            'nota'       => 'Es el mismo link que comparte el botón de WhatsApp de la pantalla. Pasáselo tal cual, sin acortarlo ni cambiarlo.',
        ];
    }

    /**
     * La base pública de la API de ESTE comercio, ya limpia.
     *
     * Orden: `users.api_url` normalizada (saca los `/public` repetidos y vuelve a poner uno si la
     * instalación lo necesita) → `ApiUrlHelper::public_base()` (la de la config) → cadena vacía.
     *
     * @param  int  $owner_id
     * @return string
     */
    public static function base_publica($owner_id): string
    {
        $owner = User::find((int) $owner_id);

        $guardada = is_null($owner) ? '' : (string) $owner->api_url;

        $base = ApiUrlHelper::canonical_public_url($guardada);

        if (trim($base) === '') {

            $base = ApiUrlHelper::public_base();
        }

        return rtrim(trim((string) $base), '/');
    }

    /**
     * La respuesta cuando no hay link. Lleva `link` en null a propósito: el modelo tiene que poder
     * distinguir "no hay" de "está vacío", y la regla del prompt es contar el `error` tal cual.
     *
     * @param  string  $motivo
     * @return array<string, mixed>
     */
    protected static function no_se_puede($motivo): array
    {
        return [
            'ok'    => false,
            'link'  => null,
            'error' => (string) $motivo,
        ];
    }
}
