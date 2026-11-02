<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\WhatsappBotConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ¿El negocio tiene conectado el WhatsApp con sus CLIENTES? (misión agente-mejoras-uso-real,
 * 29/10/2026, §4 E3.2 del plan).
 *
 * EL CASO REAL (CONV 24 de Ferre Total, 29/10/2026): el dueño pidió una promo por WhatsApp de la bomba
 * presurizadora para ACP Group. El agente dio DIEZ VUELTAS —buscó el artículo, el cliente, discutió si
 * había tienda y si el artículo estaba publicado— y recién cuando el dueño dijo «Mandalo» contestó *"No
 * se pudo mandar: el negocio no tiene WhatsApp conectado"*. Ferre Total no tiene `whatsapp_bot_configs`:
 * no hay por dónde escribirles a los clientes. Eso se sabía desde el primer mensaje.
 *
 * Desde esta misión:
 *   - las herramientas de envío por WhatsApp (promoción y recordatorios de cobro) lo chequean PRIMERO,
 *     antes de resolver nada, y contestan con el código CODIGO_SIN_WHATSAPP y texto_sin_whatsapp();
 *   - el bloque de módulos apagados del prompt (AsistenteIaService::bloque_de_modulos_apagados) suma
 *     linea_para_el_prompt(), así el modelo lo dice en la primera respuesta y no promete el envío.
 *
 * 🔴 NO ES EL WHATSAPP DEL AGENTE. El dueño le habla al agente por el número de ComercioCity (el admin);
 * el WhatsApp con clientes es la línea de WhatsApp Business DEL NEGOCIO conectada por Kapso
 * (`whatsapp_bot_configs` activa). En la CONV 24 el dueño contestó *"Si lo tiene ya lo cargue en el
 * sistema"*: confundía los dos. El texto lo aclara.
 *
 * PHP 7.4: sin match, sin nullsafe, sin argumentos nombrados.
 */
class WhatsappDeClientesIaHelper
{
    /** El código del motivo cuando no hay WhatsApp con clientes conectado. */
    const CODIGO_SIN_WHATSAPP = 'sin_whatsapp_de_clientes';

    /**
     * 🔴 LO QUE SÍ SE PUEDE HACER PARA LA PERSONA (misión agente-mejoras-diario-30-10, B2, 31/10/2026). Va en
     * texto_sin_whatsapp() y en linea_para_el_prompt(), con el mismo texto. Los análisis diarios lo mostraron
     * dos veces: el 30/10 (#4, con 👎) el dueño pidió el texto de una promo para mandarlo él y el agente no se lo
     * dio («No armes el texto…» decía este helper); el 31/10 (#7) pidió un comprobante «a mi WhatsApp» y el
     * agente le habló de la línea de clientes. Lo que falta es el WhatsApp hacia los CLIENTES: el texto para
     * reenviar lo arma armar_texto_de_promocion y el comprobante para la persona es el link de
     * consultar_link_de_pdf (el chat no adjunta archivos). La conexión se ofrece aparte, nunca como condición.
     *
     * Ronda de arreglos de la misión (F6, 31/10/2026): se arma con las tres partes de abajo. El renglón del prompt
     * (linea_para_el_prompt()) y el corte de las PROMOCIONES llevan las tres; el corte de los RECORDATORIOS DE
     * COBRO no lleva la del texto para reenviar: armar_texto_de_promocion arma el texto de una promo, no el de un
     * recordatorio, y nombrárselo ahí era empujar al modelo a usar una herramienta que no corresponde.
     */
    const REGLA_PARA_LA_PERSONA = self::REGLA_SOLO_HACIA_LOS_CLIENTES . ' ' . self::REGLA_DEL_TEXTO_PARA_REENVIAR . ' '
        . self::REGLA_DEL_COMPROBANTE_Y_LA_CONEXION;

    /** La primera parte de REGLA_PARA_LA_PERSONA: lo que falta es solo el WhatsApp hacia los clientes (F6). */
    const REGLA_SOLO_HACIA_LOS_CLIENTES = 'Eso es solo hacia los CLIENTES.';

    /** La parte de REGLA_PARA_LA_PERSONA que manda al texto para reenviar: solo con las promociones (F6). */
    const REGLA_DEL_TEXTO_PARA_REENVIAR = 'Si la persona pide el texto para mandarlo ella, usá armar_texto_de_promocion.';

    /** La parte de REGLA_PARA_LA_PERSONA del comprobante para la persona y de la conexión ofrecida aparte (F6). */
    const REGLA_DEL_COMPROBANTE_Y_LA_CONEXION = 'Si pide un comprobante para ella ("a mí", "a mi WhatsApp"), pasale el link con consultar_link_de_pdf en esta misma respuesta '
        . '(este chat no adjunta archivos) y no nombres la línea de clientes. La conexión del WhatsApp ofrecela aparte, nunca como condición.';

    /**
     * true si el negocio tiene una configuración de WhatsApp con clientes ACTIVA (la misma condición que
     * usan los envíos: WhatsappBotConfig::getForUser).
     *
     * Protegido: un cliente sin el módulo puede no tener la tabla (grupo 137), y una base que no
     * contesta no puede voltear el prompt ni la herramienta. En los dos casos se toma como que NO hay
     * WhatsApp: es lo que va a pasar igual cuando se intente mandar.
     *
     * @param  int  $owner_id
     * @return bool
     */
    public static function conectado($owner_id): bool
    {
        try {

            if (!Schema::hasTable('whatsapp_bot_configs')) {

                return false;
            }

            return !is_null(WhatsappBotConfig::getForUser((int) $owner_id));

        } catch (\Throwable $e) {

            Log::warning('WhatsappDeClientesIaHelper: no se pudo mirar la configuración de WhatsApp.', [
                'owner_id' => (int) $owner_id,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * El motivo exacto, para el modelo, cuando no hay WhatsApp con clientes.
     *
     * Desde la misión agente-mejoras-diario-30-10 (B2, 31/10/2026) ya no dice «No armes el texto»: el envío a
     * los clientes no puede salir, pero el texto para que la persona lo mande ella sí (REGLA_PARA_LA_PERSONA).
     * Lo que sigue sin tener sentido es buscar clientes para este envío.
     *
     * Ronda de arreglos de la misión (F6, 31/10/2026): la parte del texto para reenviar
     * (REGLA_DEL_TEXTO_PARA_REENVIAR) va solo con las «promociones»; con los «recordatorios de cobro» va lo del
     * comprobante con consultar_link_de_pdf y lo de ofrecer la conexión aparte, sin nombrar armar_texto_de_promocion.
     *
     * @param  string  $que  Lo que no se puede mandar ("promociones", "recordatorios de cobro").
     * @return string
     */
    public static function texto_sin_whatsapp($que): string
    {
        $es_una_promocion = mb_strtolower(trim((string) $que)) === 'promociones';

        return 'El negocio no tiene conectado el WhatsApp con sus clientes (la línea de WhatsApp Business del negocio por la que el sistema '
            . 'les escribe a los clientes; no es el WhatsApp por el que la persona habla con vos), así que no se pueden mandar '
            . (string) $que . ' por WhatsApp. No se arregla desde la configuración del sistema ni lo podés hacer vos: lo conecta el '
            . 'equipo de ComercioCity. Decíselo así, en esta misma respuesta, y ofrecele pedírselo a soporte (pedir_ayuda_a_soporte). '
            . 'No busques clientes para este envío: no va a poder salir. '
            . ($es_una_promocion
                ? self::REGLA_PARA_LA_PERSONA
                : self::REGLA_SOLO_HACIA_LOS_CLIENTES . ' ' . self::REGLA_DEL_COMPROBANTE_Y_LA_CONEXION);
    }

    /**
     * El renglón del bloque de módulos apagados del prompt, sin el punto final (lo une el llamador).
     *
     * 🔴 Vive en el bloque de módulos apagados (AsistenteIaService::bloque_de_modulos_apagados), que va adentro
     * del bloque de carga del system de ESTE negocio (cacheado por negocio y por día), y solo cuando el negocio
     * no tiene el WhatsApp con clientes: no toca las tools ni lo común a todos los negocios. Desde la misión
     * agente-mejoras-diario-30-10 (B2) suma REGLA_PARA_LA_PERSONA adentro del paréntesis.
     *
     * @return string
     */
    public static function linea_para_el_prompt(): string
    {
        return 'WhatsApp con los clientes sin conectar (el negocio no tiene conectada su línea de WhatsApp Business: no podés '
            . 'mandarles promociones, recordatorios de cobro ni comprobantes por WhatsApp a los clientes; si te lo piden, decilo en la '
            . 'primera respuesta y ofrecé pedírselo a soporte con pedir_ayuda_a_soporte. '
            . rtrim(self::REGLA_PARA_LA_PERSONA, '.') . ')';
    }

    /**
     * La respuesta negativa de una herramienta de carga, con el código.
     *
     * @param  string  $que
     * @return array
     */
    public static function respuesta_sin_whatsapp($que): array
    {
        return RespuestaDeCargaIa::error_con_codigo(self::CODIGO_SIN_WHATSAPP, self::texto_sin_whatsapp($que));
    }
}
