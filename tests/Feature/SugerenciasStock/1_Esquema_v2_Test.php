<?php

namespace Tests\Feature\SugerenciasStock;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión sugerencias-inteligentes-stock — P1: el esquema de la v2.
 *
 * Protege que las migraciones nuevas dejen exactamente el esquema que el resto
 * de la misión asume: designación de depósitos en addresses, columnas de
 * resumen IA y origen de generación en stock_suggestions, columnas de
 * priorización + índices en stock_suggestion_articles, y la configuración de
 * periodicidad en users con defaults que NO activan nada solo por migrar.
 *
 * También asevera explícitamente que address_article NO cambió: la designación
 * de depósito vive en addresses, no en el pivot con datos de stock.
 */
class Esquema_v2_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Default de una columna según information_schema (null si no hay default).
     *
     * @param string $tabla
     * @param string $columna
     * @return string|null
     */
    protected function default_de_columna($tabla, $columna)
    {
        $fila = DB::selectOne(
            "SELECT COLUMN_DEFAULT as valor
             FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?",
            [$tabla, $columna]
        );

        return $fila ? $fila->valor : null;
    }

    /**
     * true si el índice existe en la tabla.
     *
     * @param string $tabla
     * @param string $nombre_indice
     * @return bool
     */
    protected function indice_existe($tabla, $nombre_indice)
    {
        $filas = DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = '{$nombre_indice}'");

        return ! empty($filas);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function addresses_gana_es_deposito_origen_con_default_apagado()
    {
        $this->assertTrue(
            Schema::hasColumn('addresses', 'es_deposito_origen'),
            'Falta la columna addresses.es_deposito_origen.'
        );

        $this->assertEquals(
            '0',
            (string) $this->default_de_columna('addresses', 'es_deposito_origen'),
            'es_deposito_origen tiene que nacer en 0: ninguna sucursal existente queda designada sola.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function stock_suggestions_gana_las_columnas_v2_con_origen_manual_por_default()
    {
        foreach (['origen_generacion', 'resumen_ia', 'resumen_ia_estado', 'resumen_ia_error', 'error_mensaje'] as $columna) {
            $this->assertTrue(
                Schema::hasColumn('stock_suggestions', $columna),
                "Falta la columna stock_suggestions.{$columna}."
            );
        }

        $this->assertEquals(
            'manual',
            $this->default_de_columna('stock_suggestions', 'origen_generacion'),
            'origen_generacion tiene que defaultear a manual: las sugerencias viejas son todas manuales.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function stock_suggestion_articles_gana_las_columnas_de_prioridad_y_sus_indices()
    {
        foreach (['velocidad_diaria', 'cobertura_dias', 'stock_destino', 'prioridad'] as $columna) {
            $this->assertTrue(
                Schema::hasColumn('stock_suggestion_articles', $columna),
                "Falta la columna stock_suggestion_articles.{$columna}."
            );
        }

        $indices = [
            'stock_suggestion_articles_suggestion_prioridad_index',
            'stock_suggestion_articles_suggestion_from_index',
            'stock_suggestion_articles_suggestion_to_index',
        ];

        foreach ($indices as $indice) {
            $this->assertTrue(
                $this->indice_existe('stock_suggestion_articles', $indice),
                "Falta el índice {$indice}: sin él, el endpoint paginado vuelve al full scan."
            );
        }
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function users_gana_la_configuracion_de_sugerencias_sin_activar_nada_solo()
    {
        $columnas = [
            'sugerencias_periodicidad',
            'sugerencias_modo',
            'sugerencias_origen',
            'sugerencias_limite_origen',
            'sugerencias_ultima_generacion_at',
        ];

        foreach ($columnas as $columna) {
            $this->assertTrue(
                Schema::hasColumn('users', $columna),
                "Falta la columna users.{$columna}."
            );
        }

        // La aserción que da sentido al test: migrar NO puede prender la
        // generación automática en ninguna cuenta existente.
        $this->assertEquals(
            'nunca',
            $this->default_de_columna('users', 'sugerencias_periodicidad'),
            'sugerencias_periodicidad tiene que defaultear a nunca.'
        );
    }

    /**
     * El pivot con datos de stock queda intacto: la designación de depósito
     * vive en addresses, y ninguna migración de esta misión puede tocar
     * address_article (tabla con datos reales de stock por sucursal).
     *
     * @group sugerencias-stock
     * @test
     */
    public function address_article_conserva_exactamente_sus_columnas()
    {
        $esperadas = [
            'id',
            'article_id',
            'address_id',
            'amount',
            'created_at',
            'updated_at',
            'stock_min',
            'stock_max',
        ];

        $reales = Schema::getColumnListing('address_article');

        sort($esperadas);
        sort($reales);

        $this->assertEquals(
            $esperadas,
            $reales,
            'address_article cambió de columnas: ninguna migración de esta misión tenía permitido tocarla.'
        );
    }
}
