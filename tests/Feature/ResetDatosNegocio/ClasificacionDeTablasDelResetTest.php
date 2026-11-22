<?php

namespace Tests\Feature\ResetDatosNegocio;

use App\Http\Controllers\Helpers\BorradoTotalDeBaseHelper;
use App\Http\Controllers\Helpers\ResetDatosNegocio\CatalogoDeTablasDelResetHelper;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CA1 — Clasificación completa de las tablas para el reset de datos de negocio (misión reset-datos-cliente).
 *
 * El reset vacía una lista CERRADA de tablas y conserva el resto. Esa lista es la fuente única
 * (`CatalogoDeTablasDelResetHelper`) y tiene tres partes: TABLAS_A_VACIAR (negocio), TABLAS_DE_CONFIGURACION y
 * TABLAS_DE_SISTEMA (las dos se conservan). La trampa que este test cierra: alguien agrega una migración con una
 * tabla nueva, nadie la clasifica, y el reset la conserva (o la vacía) por omisión sin que nadie lo decida. Con
 * este test una tabla nueva sin clasificar rompe la suite, y en producción el reset se niega
 * (`tablas_sin_clasificar`).
 *
 * Sólo LEE `information_schema` de la base de testing del slot (migrada a HEAD): no escribe nada, por eso usa
 * `Tests\TestCase` a secas, sin transacciones ni base descartable.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class ClasificacionDeTablasDelResetTest extends TestCase
{
    /**
     * Tablas reales (sin vistas) de la base de testing del slot.
     *
     * @return array Nombres de tabla.
     */
    protected function tablas_reales_de_la_base()
    {
        return DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->pluck('TABLE_NAME')
            ->all();
    }

    /**
     * Las tres listas juntas, con el nombre de la lista a la que pertenece cada aparición.
     *
     * @return array nombre_de_lista => array de tablas.
     */
    protected function las_tres_listas()
    {
        return [
            'TABLAS_A_VACIAR' => CatalogoDeTablasDelResetHelper::TABLAS_A_VACIAR,
            'TABLAS_DE_CONFIGURACION' => CatalogoDeTablasDelResetHelper::TABLAS_DE_CONFIGURACION,
            'TABLAS_DE_SISTEMA' => CatalogoDeTablasDelResetHelper::TABLAS_DE_SISTEMA,
        ];
    }

    /** @test */
    public function test_toda_tabla_de_la_base_esta_en_exactamente_una_lista()
    {
        $apariciones = [];
        foreach ($this->las_tres_listas() as $nombre_de_lista => $tablas) {
            foreach ($tablas as $tabla) {
                $apariciones[$tabla][] = $nombre_de_lista;
            }
        }

        $sin_clasificar = [];
        $en_mas_de_una = [];
        foreach ($this->tablas_reales_de_la_base() as $tabla) {
            if (!isset($apariciones[$tabla])) {
                $sin_clasificar[] = $tabla;
            } elseif (count($apariciones[$tabla]) > 1) {
                $en_mas_de_una[] = $tabla . ' (' . implode(', ', $apariciones[$tabla]) . ')';
            }
        }

        $this->assertSame([], $sin_clasificar, 'Tablas de la base que no están en ninguna lista del catálogo del reset: ' . implode(', ', $sin_clasificar));
        $this->assertSame([], $en_mas_de_una, 'Tablas clasificadas en más de una lista: ' . implode('; ', $en_mas_de_una));

        // Los dos helpers de diagnóstico del catálogo dicen lo mismo que este test.
        $this->assertSame([], array_values(CatalogoDeTablasDelResetHelper::tablas_sin_clasificar()), 'tablas_sin_clasificar() tiene que devolver vacío.');
        $this->assertSame([], array_values(CatalogoDeTablasDelResetHelper::tablas_duplicadas()), 'tablas_duplicadas() tiene que devolver vacío.');
    }

    /** @test */
    public function test_ninguna_lista_nombra_una_tabla_inexistente()
    {
        $reales = $this->tablas_reales_de_la_base();

        foreach ($this->las_tres_listas() as $nombre_de_lista => $tablas) {
            $this->assertNotEmpty($tablas, $nombre_de_lista . ' no puede estar vacía.');

            // Una tabla repetida dentro de la misma lista es un error de tipeo que hay que ver.
            $this->assertSame(count($tablas), count(array_unique($tablas)), $nombre_de_lista . ' tiene nombres repetidos.');

            $inexistentes = array_values(array_diff($tablas, $reales));
            $this->assertSame([], $inexistentes, $nombre_de_lista . ' nombra tablas que no existen en la base: ' . implode(', ', $inexistentes));
        }
    }

    /** @test */
    public function test_las_tablas_de_negocio_de_la_guarda_del_setup_se_vacian()
    {
        $a_vaciar = CatalogoDeTablasDelResetHelper::TABLAS_A_VACIAR;
        $configuracion = CatalogoDeTablasDelResetHelper::TABLAS_DE_CONFIGURACION;

        // Las 13 tablas que la guarda de `migrate:fresh` considera "hay datos de negocio" (BorradoTotalDeBaseHelper).
        $this->assertCount(13, BorradoTotalDeBaseHelper::TABLAS_DE_NEGOCIO);

        foreach (BorradoTotalDeBaseHelper::TABLAS_DE_NEGOCIO as $tabla) {
            if ($tabla === 'users') {
                // Los usuarios son configuración: el reset conserva al dueño y a los empleados.
                $this->assertContains('users', $configuracion, 'users tiene que ser de CONFIGURACIÓN.');
                $this->assertNotContains('users', $a_vaciar, 'users no se vacía.');
                continue;
            }

            $this->assertContains($tabla, $a_vaciar, 'La tabla ' . $tabla . ' es de negocio para la guarda del setup y tiene que vaciarse.');
        }
    }

    /** @test */
    public function test_la_tabla_de_registro_del_reset_es_de_sistema_y_no_se_vacia_nunca()
    {
        $this->assertContains('reset_datos_negocio_registros', CatalogoDeTablasDelResetHelper::TABLAS_DE_SISTEMA);
        $this->assertNotContains('reset_datos_negocio_registros', CatalogoDeTablasDelResetHelper::TABLAS_A_VACIAR);
        $this->assertNotContains('reset_datos_negocio_registros', CatalogoDeTablasDelResetHelper::TABLAS_DE_CONFIGURACION);

        // Y la tabla existe de verdad en la base migrada (la migración del reset está corrida).
        $this->assertContains('reset_datos_negocio_registros', $this->tablas_reales_de_la_base());
    }

    /** @test */
    public function test_las_tablas_que_el_user_setup_deja_armadas_no_se_vacian()
    {
        // Lo que el user setup crea (§1.2 del plan) es lo que "el sistema igual que después del setup" conserva.
        $a_vaciar = CatalogoDeTablasDelResetHelper::TABLAS_A_VACIAR;
        $conservadas = [
            'users', 'addresses', 'price_types', 'extencion_empresas', 'extencion_empresa_user', 'permission_empresas',
            'permission_empresa_user', 'online_configurations', 'afip_information', 'cajas', 'ivas', 'monedas',
            'unidad_medidas', 'sale_channels', 'current_acount_payment_methods', 'order_statuses', 'colors',
            'concepto_stock_movements', 'pdf_column_profiles', 'vender_layouts', 'article_ticket_designs',
        ];

        foreach ($conservadas as $tabla) {
            $this->assertNotContains($tabla, $a_vaciar, 'La tabla ' . $tabla . ' la arma el user setup o el dueño al configurar: no se vacía.');
        }
    }

    /** @test */
    public function test_las_tablas_de_los_datos_que_pide_borrar_lucas_se_vacian()
    {
        // El pedido literal: artículos, ventas con sus líneas, saldos de clientes y proveedores, etc.
        $a_vaciar = CatalogoDeTablasDelResetHelper::TABLAS_A_VACIAR;
        $negocio = [
            'articles', 'images', 'article_sale', 'sales', 'clients', 'providers', 'current_acounts', 'stock_movements',
            'apertura_cajas', 'movimiento_cajas', 'categories', 'sub_categories', 'brands', 'buyers', 'orders',
            'provider_orders', 'budgets', 'cheques', 'afip_tickets', 'jobs', 'job_batches', 'failed_jobs',
        ];

        foreach ($negocio as $tabla) {
            $this->assertContains($tabla, $a_vaciar, 'La tabla ' . $tabla . ' es dato de negocio y tiene que vaciarse.');
        }
    }
}
