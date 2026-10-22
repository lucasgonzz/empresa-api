<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder de la extensión única «Agentes de IA» (slug `agentes_ia`) — misión agentes-ia-extension-unica
 * (22/10/2026). Es el seeder general (lo llaman `DatabaseSeeder::common_seeders()`, `UserSetupHelper` y
 * `DemoSetupHelper`) y también el suelto de producción: el mismo.
 *
 * Hace dos cosas, las dos idempotentes:
 *
 *  1. Deja la fila `agentes_ia` en el catálogo (idempotente, como un `firstOrCreate` por slug) con su descripción y su módulo. Si ya
 *     existía —porque se corrió antes— no crea otra y solo completa lo que le falte.
 *  2. Marca `en_desuso = true` las seis extensiones de IA que esta reemplaza (`asistente_ia`,
 *     `escaneo_factura_compra`, `whatsapp_ia`, `sugerencias_inteligentes`, `sugerencias_compras` y
 *     `whatsapp`), con la descripción «Reemplazada por Agentes de IA». NO las borra: el pivote
 *     `extencion_empresa_user` no tiene FK ni unique y un rollback las necesita.
 *
 * 🔴 No asigna la extensión a ningún comercio y no toca `extencion_empresa_user`: quién la tiene se decide
 * por cliente (desde el admin, o desde el configurador de extensiones). Se puede correr sobre una base con
 * clientes adentro sin cambiarle nada a nadie.
 *
 * En producción:
 *   php artisan db:seed --class=Database\Seeders\ExtencionAgentesIaSeeder --force
 * NUNCA `ExtencionSeeder`, que hace `create()` y duplica el catálogo si se corre dos veces.
 */
class ExtencionAgentesIaSeeder extends Seeder
{
    /** Módulo del configurador de extensiones donde se agrupa. */
    const MODULO = 'Agentes de IA';

    /** Lo que el configurador muestra de la extensión. */
    const DESCRIPCION = 'Encendida habilita TODA la IA del sistema, en un solo paquete: el agente personal del dueño (chat del sistema y por WhatsApp, con su configuración, su consumo, el mostrador de informes y la conexión por MCP), la carga de facturas de compra desde una foto, el módulo de WhatsApp con clientes (chats, plantillas, respuestas sugeridas, recordatorio de cobro y el bot que atiende a los clientes finales), la asignación automática de imágenes de artículos, las descripciones y fichas por código de barras, las sugerencias de stock y de compra, la propuesta de ofertas y la categorización con IA. Apagada, nada de eso existe: la API contesta 403 con "Extensión requerida: agentes_ia" y el webhook del bot de WhatsApp responde 200 sin procesar el mensaje. NO la gatea: la importación de artículos con IA desde Excel, la búsqueda MANUAL de imágenes de la ficha del artículo ni el informe compartido por link. Reemplaza a asistente_ia, escaneo_factura_compra, whatsapp_ia, sugerencias_inteligentes, sugerencias_compras y whatsapp. Nadie la hereda: se activa por cliente.';

    /** Descripción de las seis que reemplaza. */
    const DESCRIPCION_REEMPLAZADA = 'Reemplazada por Agentes de IA (slug agentes_ia): ya no la lee ningún código del sistema, así que encenderla o apagarla no cambia nada. La fila y las asignaciones de cada comercio se conservan (marcar en desuso no es borrar).';

    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        /*
         * Equivale a un `firstOrCreate` por slug, pero con `forceFill`: el modelo no declara `$fillable`, y
         * corrido por fuera de `artisan db:seed` (un test, un tinker) el mass assignment descartaría los
         * atributos en silencio y la fila quedaría sin nombre.
         */
        $extension = ExtencionEmpresa::where('slug', AgentesIaHelper::SLUG)->first();

        if (is_null($extension)) {

            $extension = new ExtencionEmpresa();
            $extension->forceFill([
                'slug'        => AgentesIaHelper::SLUG,
                'name'        => AgentesIaHelper::NOMBRE,
                'description' => self::DESCRIPCION,
                'modulo'      => self::MODULO,
                'en_desuso'   => false,
            ])->save();
        }

        /*
         * Si la fila ya existía (porque otro seeder la creó sin descripción), se completa lo que falte y
         * se deja viva. El `name` no se pisa si alguien lo renombró a mano.
         */
        if (empty($extension->description) || empty($extension->modulo) || $extension->en_desuso) {

            $extension->description = $extension->description ?: self::DESCRIPCION;
            $extension->modulo      = $extension->modulo ?: self::MODULO;
            $extension->en_desuso   = false;
            $extension->save();
        }

        /* Las seis que reemplaza: en desuso, con el motivo. Update por slug, sin tocar el pivote. */
        ExtencionEmpresa::whereIn('slug', AgentesIaHelper::SLUGS_REEMPLAZADAS)
            ->update([
                'en_desuso'   => true,
                'description' => self::DESCRIPCION_REEMPLAZADA,
                'modulo'      => self::MODULO,
            ]);
    }
}
