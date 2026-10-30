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
     * @param  string  $que  Lo que no se puede mandar ("promociones", "recordatorios de cobro").
     * @return string
     */
    public static function texto_sin_whatsapp($que): string
    {
        return 'El negocio no tiene conectado el WhatsApp con sus clientes (la línea de WhatsApp Business del negocio por la que el sistema '
            . 'les escribe a los clientes; no es el WhatsApp por el que la persona habla con vos), así que no se pueden mandar '
            . (string) $que . ' por WhatsApp. No se arregla desde la configuración del sistema ni lo podés hacer vos: lo conecta el '
            . 'equipo de ComercioCity. Decíselo así, en esta misma respuesta, y ofrecele pedírselo a soporte (pedir_ayuda_a_soporte). '
            . 'No armes el texto ni busques clientes o artículos para este envío: no va a poder salir.';
    }

    /**
     * El renglón del bloque de módulos apagados del prompt, sin el punto final (lo une el llamador).
     *
     * @return string
     */
    public static function linea_para_el_prompt(): string
    {
        return 'WhatsApp con los clientes sin conectar (el negocio no tiene conectada su línea de WhatsApp Business: no podés '
            . 'mandarles promociones, recordatorios de cobro ni comprobantes por WhatsApp a los clientes; si te lo piden, decilo en la '
            . 'primera respuesta y ofrecé pedírselo a soporte con pedir_ayuda_a_soporte)';
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
