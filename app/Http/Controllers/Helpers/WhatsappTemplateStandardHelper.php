<?php

namespace App\Http\Controllers\Helpers;

use App\Models\WhatsappTemplate;

/**
 * Catálogo de las 6 plantillas estándar de WhatsApp que precarga ComercioCity para todas
 * las empresas con el bot configurado (grupo 137, Prompt 04). Nacen `is_system` = true
 * (no editables ni borrables desde el ERP) y `status` = `pendiente_meta`: el equipo de
 * ComercioCity las da de alta a mano en Meta y recién ahí las marca `aprobada`.
 *
 * Reusado tanto por `WhatsappTemplateStandardSeeder` (seeder general, llamado desde
 * `DatabaseSeeder::common_seeders()`, y standalone en producción) como por el flujo de
 * setup de un negocio nuevo si en el futuro se quisiera disparar apenas se configura el
 * bot (`WhatsappBotConfig`).
 */
class WhatsappTemplateStandardHelper
{
    /**
     * Definición de las 6 plantillas estándar `cc_cli_*`. El orden de `variables` es el
     * mismo orden en que aparecen los placeholders `{{1}}`, `{{2}}`... en `body_preview`.
     *
     * @var array<int, array<string, mixed>>
     */
    protected static $standard_templates = [
        [
            'name' => 'Recordatorio de pago',
            'meta_template_name' => 'cc_cli_recordatorio_pago',
            'category' => 'utility',
            'body_preview' => 'Hola {{1}}, te escribimos de {{2}}. Te recordamos que tenés un saldo pendiente de {{3}}. Podés abonarlo por los medios de siempre o responder este mensaje para coordinar el pago. ¡Gracias!',
            'variables' => ['Nombre del cliente', 'Nombre del negocio', 'Monto (con $)'],
        ],
        [
            'name' => 'Promoción',
            'meta_template_name' => 'cc_cli_promocion',
            'category' => 'marketing',
            'body_preview' => 'Hola {{1}}! En {{2}} tenemos una promo para vos: {{3}}. Válida hasta el {{4}}. Respondé este mensaje o entrá a nuestra tienda para aprovecharla.',
            'variables' => ['Nombre', 'Negocio', 'Detalle de la promo', 'Fecha límite'],
        ],
        [
            'name' => 'Respuesta a consulta',
            'meta_template_name' => 'cc_cli_respuesta',
            'category' => 'utility',
            'body_preview' => 'Hola {{1}}, te escribimos de {{2}} por tu consulta: {{3}}. Cualquier otra duda, respondé este mensaje y te ayudamos.',
            'variables' => ['Nombre', 'Negocio', 'Respuesta'],
        ],
        [
            'name' => 'Mensaje libre',
            'meta_template_name' => 'cc_cli_mensaje_libre',
            'category' => 'utility',
            'body_preview' => 'Hola {{1}}, te escribimos de {{2}}: {{3}}. Podés responder este mensaje para seguir la conversación.',
            'variables' => ['Nombre', 'Negocio', 'Mensaje'],
        ],
        [
            'name' => 'Comprobante de compra',
            'meta_template_name' => 'cc_cli_comprobante',
            'category' => 'utility',
            'body_preview' => 'Hola {{1}}, te enviamos el comprobante {{2}} de tu compra en {{3}}. Cualquier consulta, respondé este mensaje. ¡Gracias por tu compra!',
            'variables' => ['Nombre', 'Nº de comprobante', 'Negocio'],
        ],
        [
            // Misión recordatorio-cobro-whatsapp: la plantilla del recordatorio de cobro que
            // sale desde el módulo de alertas cuando la ventana de 24 h del cliente está
            // cerrada. El header DOCUMENT lo arma `RecordatorioCobroSenderService` con el PDF
            // de la cuenta corriente, así que acá solo van las variables del body.
            //
            // 🔴 La lista de ventas NO puede ser una variable de plantilla: Meta rechaza los
            // parámetros con saltos de línea, tabulaciones o 4 espacios seguidos. Por eso el
            // detalle una-por-renglón existe sólo en el camino de texto libre y acá viaja
            // apenas la cantidad de ventas y el total pendiente.
            //
            // 🔴 DOS COSAS DEL TEXTO QUE NO SE PUEDEN CAMBIAR DESPUÉS. Una vez que Meta aprueba
            // la plantilla, el body es inmutable: cualquier retoque obliga a darla de alta de
            // nuevo y a esperar otra revisión. Por eso están resueltas acá y no "más adelante":
            //
            // 1. {{3}} LLEVA EL SUSTANTIVO ("1 venta" / "7 ventas") y el body dice "Tenés {{3}}
            //    pendientes de pago". Si {{3}} fuera sólo el número sobre un body que dijera
            //    "{{3}} ventas pendientes", un cliente con una sola venta leería "Tenés 1 ventas
            //    pendientes de pago". El singular no se puede resolver desde texto fijo.
            // 2. EL BODY NO PROMETE EL ADJUNTO. Decía "Te adjuntamos el resumen de tu cuenta
            //    corriente", y el header DOCUMENT no siempre puede armarse (owner sin `api_url`,
            //    cliente sin movimientos en la cuenta). Prometerle al cliente un archivo que no
            //    llega es peor que no mencionarlo: ahora se lo ofrece por respuesta, que es algo
            //    que siempre se puede cumplir.
            'name' => 'Recordatorio de cobro',
            'meta_template_name' => 'cc_cli_recordatorio_cobro',
            'category' => 'utility',
            'body_preview' => 'Hola {{1}}, te escribimos de {{2}}. Tenés {{3}} pendientes de pago por un total de {{4}}. Si necesitás el resumen de tu cuenta corriente o querés coordinar el pago, respondé este mensaje. ¡Gracias!',
            'variables' => ['Nombre', 'Negocio', 'Cantidad de ventas (ej: "7 ventas")', 'Montos pendientes'],
        ],
    ];

    /**
     * Crea, para el owner recibido, las plantillas estándar que todavía no tenga
     * (matchea por `meta_template_name`). Idempotente: correrlo dos veces no duplica
     * filas.
     *
     * @param  int  $owner_id
     * @return void
     */
    public static function apply_for_owner($owner_id)
    {
        // Nombres técnicos ya cargados para este owner, para no duplicar en corridas repetidas.
        $existing_names = WhatsappTemplate::where('user_id', $owner_id)
            ->whereIn('meta_template_name', self::meta_template_names())
            ->pluck('meta_template_name')
            ->all();

        foreach (self::$standard_templates as $template) {
            if (in_array($template['meta_template_name'], $existing_names, true)) {
                continue;
            }

            WhatsappTemplate::create([
                'user_id' => $owner_id,
                'name' => $template['name'],
                'meta_template_name' => $template['meta_template_name'],
                'category' => $template['category'],
                'language' => 'es_AR',
                'body_preview' => $template['body_preview'],
                'variables' => $template['variables'],
                'status' => 'pendiente_meta',
                'is_system' => true,
            ]);
        }
    }

    /**
     * El `body_preview` del catálogo para un `meta_template_name`, o cadena vacía si ese nombre
     * no es de ninguna plantilla estándar.
     *
     * 🔴 EXISTE PARA QUE EL TEXTO DE UNA PLANTILLA VIVA EN UN SOLO ARCHIVO.
     * `RecordatorioCobroSenderService` lo necesita para previsualizar el mensaje cuando el owner
     * todavía no tiene la plantilla dada de alta: si lo tuviera copiado, la pantalla mostraría un
     * texto y a Meta se le daría de alta otro apenas alguien tocara uno de los dos.
     *
     * @param  string  $meta_template_name
     * @return string
     */
    public static function body_preview_de($meta_template_name)
    {
        foreach (self::$standard_templates as $template) {
            if ($template['meta_template_name'] === $meta_template_name) {
                return $template['body_preview'];
            }
        }

        return '';
    }

    /**
     * Nombres técnicos (`meta_template_name`) de las 6 plantillas estándar.
     *
     * @return array<int, string>
     */
    protected static function meta_template_names()
    {
        return array_map(function ($template) {
            return $template['meta_template_name'];
        }, self::$standard_templates);
    }
}
