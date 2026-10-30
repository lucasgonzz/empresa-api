<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use Illuminate\Support\Facades\Log;

/**
 * La línea de progreso de una respuesta que se está generando (misión app-ia-y-memoria, 29/10/2026,
 * §4 E1.5 del plan): «Buscando las ventas…», «Revisando el stock…», «Armando el gráfico…».
 *
 * Mientras corre el loop de herramientas, antes de cada una se escribe en `ai_messages.progreso` una
 * etiqueta humana de lo que está haciendo; la app la muestra en el globo de "pensando" mientras
 * consulta el mensaje. Al terminar (listo o error) el job la vuelve a null.
 *
 * 🔴 ES UN UPDATE BARATO Y NADA MÁS: sin eventos, sin broadcast, sin tocar el resto de la fila. Y
 * nunca lanza: un progreso que no se pudo escribir no puede voltear una respuesta.
 */
class ProgresoIaHelper
{
    /** Lo que se muestra para una herramienta sin etiqueta propia. */
    const POR_DEFECTO = 'Consultando el sistema…';

    /** Techo de la columna. */
    const MAX = 120;

    /**
     * Etiqueta por herramienta. Las que no están caen en las reglas por prefijo de etiqueta() y, si
     * no, en POR_DEFECTO.
     */
    const ETIQUETAS = [
        'consultar_stock_de_articulos'              => 'Revisando el stock…',
        'consultar_stock_por_deposito'              => 'Revisando el stock por depósito…',
        'consultar_clientes'                        => 'Buscando los clientes…',
        'consultar_movimientos_de_cuenta_corriente' => 'Revisando la cuenta corriente…',
        'consultar_cuentas_corrientes'              => 'Revisando las cuentas corrientes…',
        'consultar_articulos_mas_vendidos'          => 'Buscando lo más vendido…',
        'consultar_precios_de_proveedores'          => 'Comparando precios de proveedores…',
        'consultar_ofertas_activas'                 => 'Revisando las ofertas…',
        'consultar_actividad_de_un_cliente'         => 'Mirando la actividad del cliente…',
        'consultar_interesados_en_un_articulo'      => 'Buscando interesados…',
        'consultar_ventas_impagas_de_un_cliente'    => 'Buscando lo que debe…',
        'consultar_ventas_sin_cobrar'               => 'Buscando las ventas sin cobrar…',
        'consultar_quien_compro_un_articulo'        => 'Buscando quién lo compró…',
        'consultar_compras_de_un_articulo'          => 'Buscando las compras…',
        'consultar_compras_a_un_proveedor'          => 'Buscando las compras al proveedor…',
        'consultar_proveedores'                     => 'Buscando los proveedores…',
        'consultar_resumen_de_ventas'               => 'Buscando las ventas…',
        'consultar_reporte_contable'                => 'Armando el reporte…',
        'consultar_datos'                           => 'Consultando los datos…',
        'resumir_datos'                             => 'Haciendo las cuentas…',
        'que_puedo_consultar'                       => 'Viendo qué puedo consultar…',
        'mostrar_imagenes_de_articulos'             => 'Buscando las fotos…',
        'buscar_producto_por_codigo_de_barras'      => 'Buscando el código en internet…',
        'consultar_link_de_pdf'                     => 'Preparando el PDF…',
        'consultar_escaneo_de_factura'              => 'Revisando la factura…',
        'consultar_tareas'                          => 'Revisando las tareas…',
        'mostrar_grafico'                           => 'Armando el gráfico…',
        'mostrar_tabla'                             => 'Armando la tabla…',
        'pedir_imagen'                              => 'Pidiendo la imagen…',
        'leer_contexto'                             => 'Repasando lo que sé del negocio…',
        'guardar_en_contexto'                       => 'Anotándolo…',
        'confirmar_carga_pendiente'                 => 'Registrando…',
        'cancelar_carga_pendiente'                  => 'Cancelando…',
        // Misión agente-mejoras-uso-real (29/10/2026, §4 E3): el Excel.
        'exportar_excel'                            => 'Armando el Excel…',
    ];

    /**
     * La etiqueta de una herramienta.
     *
     * @param  string  $tool_name
     * @return string
     */
    public static function etiqueta($tool_name): string
    {
        $tool_name = (string) $tool_name;

        if (isset(self::ETIQUETAS[$tool_name])) {

            return self::ETIQUETAS[$tool_name];
        }

        if (strpos($tool_name, 'proponer_') === 0) {

            return 'Preparando la carga…';
        }

        return self::POR_DEFECTO;
    }

    /**
     * Escribe la etiqueta de la herramienta en el mensaje que se está generando.
     *
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @param  string  $tool_name
     * @return void
     */
    public static function marcar($assistant_message, $tool_name)
    {
        self::escribir($assistant_message, mb_substr(self::etiqueta($tool_name), 0, self::MAX));
    }

    /**
     * Vuelve el progreso a null (lo llama el job al terminar, también en error).
     *
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @return void
     */
    public static function limpiar($assistant_message)
    {
        self::escribir($assistant_message, null);
    }

    /**
     * El UPDATE de la columna, y el mismo valor en el modelo en memoria como ORIGINAL (para que un
     * save() posterior del job con `progreso = null` sí lo escriba, y uno que no lo toca no lo pise).
     *
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @param  string|null  $valor
     * @return void
     */
    protected static function escribir($assistant_message, $valor)
    {
        if (!($assistant_message instanceof AiMessage) || (int) $assistant_message->id <= 0) {

            return;
        }

        /* Sin la migración no hay dónde escribirlo (y es el camino del chat del sistema y de WhatsApp). */
        if (!ColumnasDeLaAppIaHelper::hay_columna('ai_messages', 'progreso')) {

            return;
        }

        try {

            AiMessage::where('id', (int) $assistant_message->id)->update(['progreso' => $valor]);

            $assistant_message->progreso = $valor;
            $assistant_message->syncOriginalAttribute('progreso');

        } catch (\Throwable $e) {

            Log::info('ProgresoIaHelper: no se pudo escribir el progreso -- ' . $e->getMessage());
        }
    }
}
