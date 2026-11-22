<?php

namespace Tests\Feature\Migraciones;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión migraciones-idempotentes (22/11/2026).
 *
 * Golden-Breeze falló al actualizar (`Duplicate column imagenes_tope`): una vuelta atrás anterior restauró la
 * tabla `migrations` pero dejó las columnas/tablas que ese intento había creado. Al reintentar, el `up()` de
 * cada migración sin guardas chocaba con lo ya existente.
 *
 * Qué se mide (mismo recorrido que el upgrade, con la migración real y el migrador real):
 *  - CA1: sobre una base migrada completa, borrar la fila de `migrations` de cada migración del camino
 *    v4.3.27 -> develop y volver a correr `migrate` termina sin error y deja el esquema idéntico.
 *  - CA2: sobre una base en estado "fantasma" (parte de lo que crea la migración ya está, la fila no) `migrate`
 *    termina bien y completa lo que faltaba (reproduce Golden-Breeze).
 *
 * No usa DatabaseTransactions: el DDL de MySQL confirma solo. El esquema se restaura al final de cada caso.
 */
class MigracionesIdempotentesTest extends TestCase
{
    /**
     * Migraciones agregadas entre v4.3.27 y develop (git diff --diff-filter=A v4.3.27 HEAD).
     *
     * @return array
     */
    public function migraciones_del_camino()
    {
        $archivos = [
            '2026_11_02_110000_cotizacion_por_articulo',
            '2026_11_02_150000_add_plan_ia_gasto_to_users_table',
            '2026_11_03_130000_agregar_precio_preciso_de_consolidacion_a_renglones',
            '2026_11_03_170000_create_local_actividad_eventos_table',
            '2026_11_03_180000_add_demo_ingreso_token_id_to_version_session_transfers_table',
            '2026_11_03_190000_create_prueba_gratuita_tables',
            '2026_11_03_200000_add_es_precio_final_to_price_types_table',
            '2026_11_03_230000_add_tiempos_de_respuesta_to_ai_messages',
            '2026_11_04_180000_add_referencia_to_ia_imagen_pedidos',
            '2026_11_04_183000_add_huella_de_articulo_to_ia_imagen_pedidos',
            '2026_11_04_183100_add_first_to_images_table',
            '2026_11_05_110000_add_cupo_imagenes_to_prueba_config_and_image_assignment_items',
            '2026_11_05_120000_add_embeddings_tokens_usados_to_prueba_config',
            '2026_11_05_160000_create_importaciones_de_prueba',
            '2026_11_05_170000_marcar_ajustes_consolidados_en_renglones',
            '2026_11_05_180000_add_update_provider_explicit_to_article_provider_order',
            '2026_11_06_110000_add_configured_ai_mode_to_whatsapp_chats',
            '2026_11_06_160000_add_vender_exigir_eleccion_medio_pago_to_users',
            '2026_11_07_120000_add_traslado_de_chat_to_prueba',
            '2026_11_07_160000_add_modo_soporte_a_importaciones_de_prueba',
            '2026_11_08_103000_create_support_tours_table',
            '2026_11_08_170000_add_canales_de_agentes',
            '2026_11_08_170100_add_reserva_de_entrega_whatsapp',
            '2026_11_08_170200_mark_support_tickets_desde_agentes',
            '2026_11_09_150000_add_import_history_id_to_support_tours',
            '2026_11_09_170000_add_pasos_programados_to_support_tours',
            '2026_11_09_235000_add_alcance_a_importaciones_de_prueba',
            '2026_11_10_110000_add_consultas_de_stock_a_la_tienda',
            '2026_11_10_113000_add_admin_reference_to_support_tickets',
            '2026_11_11_100000_create_dimension_reference_sites_table',
            '2026_11_11_100100_add_sitios_de_referencia_to_dimension_search_runs',
            '2026_11_11_140000_agregar_detalle_de_listas_a_price_changes',
            '2026_11_11_170000_add_columnas_previas_a_importaciones_de_prueba',
            '2026_11_11_190000_create_importaciones_de_soporte',
            '2026_11_11_190100_add_ticket_y_fuente_a_image_assignment_runs',
            '2026_11_11_210000_add_original_name_to_support_message_attachments',
            '2026_11_12_100000_remove_legacy_buyers_phone_unique',
            '2026_11_12_210000_add_agente_alta_bebidas_to_users',
            '2026_11_13_100000_add_avisos_del_dueno_to_users',
            '2026_11_13_160000_create_agente_consolidaciones',
            '2026_11_13_220000_add_firma_datos_fiscales_to_afip_tickets',
            '2026_11_15_120000_add_modo_prueba_a_importaciones_de_soporte',
            '2026_11_16_100000_add_reserva_stock_pedidos_web',
            '2026_11_16_100100_add_reserva_stock_web_liberada',
            '2026_11_16_180000_add_medidas_envio_to_promociones_y_combos',
            '2026_11_16_220000_add_article_index_to_article_article_ubication_table',
            '2026_11_18_120000_add_whatsapp_contacts_to_online_configurations',
            '2026_11_21_000000_add_costo_neto_de_vinculacion',
            '2026_11_21_120000_add_embedding_queued_at_to_articles_table',
        ];

        $casos = [];
        foreach ($archivos as $archivo) {
            $casos[$archivo] = [$archivo];
        }

        return $casos;
    }

    /**
     * Definición (SHOW CREATE TABLE) de cada tabla, sin el contador AUTO_INCREMENT, que cambia con los datos.
     *
     * @return array nombre_de_tabla => definición
     */
    protected function foto_del_esquema()
    {
        $foto = [];
        foreach (DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $fila) {
            $tabla = array_values((array) $fila)[0];
            $definicion = DB::select('SHOW CREATE TABLE `' . $tabla . '`')[0]->{'Create Table'};
            $foto[$tabla] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $definicion);
        }
        ksort($foto);

        return $foto;
    }

    /**
     * Ordena las líneas de cada definición para comparar sin importar el orden físico de las columnas.
     *
     * @param array $foto
     * @return array
     */
    protected function sin_orden(array $foto)
    {
        foreach ($foto as $tabla => $definicion) {
            $lineas = array_map(function ($linea) {
                return rtrim($linea, ',');
            }, explode("\n", $definicion));
            sort($lineas);
            $foto[$tabla] = implode("\n", $lineas);
        }

        return $foto;
    }

    /**
     * Devuelve el esquema a la foto tomada al empezar: recrea las tablas que difieren (la base de testing sólo
     * guarda datos de fixture, que no son de estos casos). Se usa en `finally` para no contaminar otros tests.
     *
     * @param array $antes
     * @return void
     */
    protected function restaurar_esquema(array $antes)
    {
        $ahora = $this->foto_del_esquema();
        foreach ($ahora as $tabla => $definicion) {
            if (!isset($antes[$tabla])) {
                DB::statement('DROP TABLE `' . $tabla . '`');
            }
        }
        foreach ($antes as $tabla => $definicion) {
            if (!isset($ahora[$tabla]) || $ahora[$tabla] !== $definicion) {
                // Filas que había en la tabla antes de recrearla: se guardan para devolverlas después.
                // Sin esto, recrear la tabla borra los datos del seeder (p.ej. la fila de online_configurations)
                // y los tests que corren después en la misma base fallan (gate 4.3.73, 22/11/2026).
                $filas = isset($ahora[$tabla]) ? DB::table($tabla)->get() : collect();
                DB::statement('DROP TABLE IF EXISTS `' . $tabla . '`');
                DB::statement($definicion);
                if ($filas->count()) {
                    // Columnas de la tabla recreada: sólo se reinsertan los valores de esas columnas.
                    $columnas = Schema::getColumnListing($tabla);
                    foreach ($filas->chunk(500) as $tanda) {
                        $insertar = [];
                        foreach ($tanda as $fila) {
                            $insertar[] = array_intersect_key((array) $fila, array_flip($columnas));
                        }
                        DB::table($tabla)->insert($insertar);
                    }
                }
            }
        }
    }

    /**
     * Asegura que la fila de la migración esté en `migrations` (el migrador la deja al terminar bien).
     *
     * @param string $nombre
     * @return void
     */
    protected function asegurar_fila($nombre)
    {
        if (!DB::table('migrations')->where('migration', $nombre)->exists()) {
            DB::table('migrations')->insert(['migration' => $nombre, 'batch' => 1]);
        }
    }

    /**
     * Corre el migrador real sobre un solo archivo, como lo hace el upgrade, y devuelve el error (o null).
     *
     * @param string $nombre
     * @return string|null mensaje de la excepción, o null si terminó bien
     */
    protected function migrar_archivo($nombre)
    {
        try {
            $codigo = Artisan::call('migrate', [
                '--path' => database_path('migrations/' . $nombre . '.php'),
                '--realpath' => true,
                '--force' => true,
            ]);
        } catch (\Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }

        return $codigo === 0 ? null : 'migrate terminó con código ' . $codigo;
    }

    /**
     * Ejecuta un caso: prepara el estado (borra la fila y, si se pide, deja columnas/tablas "fantasma"),
     * migra, compara el esquema y restaura.
     *
     * @param string $nombre
     * @param callable|null $fantasma recibe nada; deja la base en estado parcial
     * @return void
     */
    protected function probar_reintento($nombre, $fantasma = null)
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertTrue(
            DB::table('migrations')->where('migration', $nombre)->exists(),
            'La base de testing debería tener aplicada ' . $nombre
        );

        $antes = $this->foto_del_esquema();
        try {
            DB::table('migrations')->where('migration', $nombre)->delete();
            if ($fantasma) {
                $fantasma();
            }

            $error = $this->migrar_archivo($nombre);

            $this->assertNull($error, 'El reintento de ' . $nombre . ' tendría que terminar bien: ' . $error);
            $this->assertTrue(
                DB::table('migrations')->where('migration', $nombre)->exists(),
                'El migrador tendría que dejar registrada ' . $nombre
            );
            $despues = $this->foto_del_esquema();
            if ($fantasma) {
                // Con restos parciales las columnas que faltaban se agregan al final: el orden físico no puede
                // coincidir. Se compara igual cada columna, tipo, default e índice (líneas del CREATE sin orden).
                $antes_sin_orden = $this->sin_orden($antes);
                $despues_sin_orden = $this->sin_orden($despues);
            } else {
                $antes_sin_orden = $antes;
                $despues_sin_orden = $despues;
            }
            $this->assertEquals($antes_sin_orden, $despues_sin_orden, 'El esquema tendría que quedar idéntico tras reintentar ' . $nombre);
        } finally {
            $this->asegurar_fila($nombre);
            $this->restaurar_esquema($antes);
        }
    }

    /**
     * CA1: reintentar cualquier migración del camino sobre una base que ya la tiene aplicada.
     *
     * @test
     * @dataProvider migraciones_del_camino
     */
    public function reintentar_una_migracion_ya_aplicada_no_falla_ni_cambia_el_esquema($nombre)
    {
        $this->probar_reintento($nombre);
    }

    /**
     * CA2: Golden-Breeze. Quedó `prueba_config.imagenes_tope` de un intento anterior, sin el resto ni la fila.
     *
     * @test
     */
    public function golden_breeze_con_imagenes_tope_ya_creada_termina_la_migracion()
    {
        $this->probar_reintento('2026_11_05_110000_add_cupo_imagenes_to_prueba_config_and_image_assignment_items', function () {
            Schema::table('prueba_config', function ($table) {
                $table->dropColumn('imagenes_usadas');
            });
            Schema::table('image_assignment_items', function ($table) {
                $table->dropColumn('consumio_cupo_de_prueba');
            });
            $this->assertTrue(Schema::hasColumn('prueba_config', 'imagenes_tope'));
        });
    }

    /**
     * CA2: estado fantasma en una migración que agrega columnas a una tabla y crea otra:
     * quedó la tabla nueva y la mitad de las columnas.
     *
     * @test
     */
    public function reserva_de_stock_con_la_tabla_nueva_ya_creada_termina_la_migracion()
    {
        $this->probar_reintento('2026_11_16_100000_add_reserva_stock_pedidos_web', function () {
            Schema::table('orders', function ($table) {
                $table->dropColumn('reserva_stock_web_egreso');
            });
            Schema::table('online_configurations', function ($table) {
                $table->dropColumn('reserva_stock_tienda_url');
            });
            $this->assertTrue(Schema::hasTable('order_stock_reservations'));
            $this->assertTrue(Schema::hasColumn('orders', 'reserva_stock_web'));
        });
    }

    /**
     * CA2: estado fantasma en una migración que crea cuatro tablas: quedaron tres.
     *
     * @test
     */
    public function consolidaciones_con_tablas_ya_creadas_termina_la_migracion()
    {
        $this->probar_reintento('2026_11_13_160000_create_agente_consolidaciones', function () {
            Schema::drop('agente_consolidacion_hallazgos');
            $this->assertTrue(Schema::hasTable('agente_consolidacion_estados'));
        });
    }

    /**
     * CA2: estado fantasma con un índice único ya creado pero la columna a medias (traslado de chat).
     *
     * @test
     */
    public function traslado_de_chat_con_columna_e_indice_ya_creados_termina_la_migracion()
    {
        $this->probar_reintento('2026_11_07_120000_add_traslado_de_chat_to_prueba', function () {
            Schema::table('prueba_config', function ($table) {
                $table->dropColumn('chat_trasladado_id');
            });
            $this->assertTrue(Schema::hasColumn('ai_messages', 'prueba_mensaje_id'));
        });
    }
}
