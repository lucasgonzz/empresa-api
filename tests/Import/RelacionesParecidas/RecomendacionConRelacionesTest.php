<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Models\BackgroundProcess;
use App\Models\ExcelAnalysisRun;
use App\Models\Location;
use App\Models\PriceType;
use App\Models\Provincia;
use App\Models\Seller;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * get-recomendacion de punta a punta (misión importacion-relaciones-parecidas, 8/10/2026): el
 * endpoint real, la corrida en la cola sync y el resultado leído por GET analysis/{uuid}.
 *
 * Fija el contrato con el SPA:
 *   - con `chequear_relaciones: true`, el `resultado` suma `relaciones_parecidas`; en artículos la
 *     recomendación de siempre queda intacta, y en clientes y proveedores las otras cuatro claves
 *     siguen en null/0;
 *   - SIN la bandera (4c del plan), el `resultado` es el de siempre, sin la clave nueva y sin
 *     ningún request de IA de más, en los tres modelos.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RecomendacionConRelacionesTest extends RelacionesParecidasTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /* ------------------------------------------------------------------
     * Artículos
     * ------------------------------------------------------------------ */

    public function test_articulos_con_la_bandera_traen_relaciones_parecidas_y_la_recomendacion_intacta()
    {
        $del_norte = $this->crear_categoria('DEL NORTE');
        $this->crear_marca('Bosch');
        $this->crear_proveedor('Acme');

        $this->falsear_ia($this->elegir_por_nombre(['Bosh' => 'Bosch']));

        $resultado = $this->recomendacion('article', self::ARTICULOS, $this->columnas_articulos(), true);

        $this->assertSame([
            'politica_colision'      => 'actualizar_todos',
            'politica_intra_archivo' => 'ultima_gana',
            'explicacion'            => 'Recomendación de prueba.',
        ], $resultado['recomendacion_configuracion']);
        $this->assertArrayHasKey('formatos_numericos', $resultado);
        $this->assertArrayHasKey('provider_codes_existentes_mismo_proveedor', $resultado);
        $this->assertArrayHasKey('provider_codes_existentes_otros_proveedores', $resultado);

        $this->assertSame(1, $this->llamadas_recomendacion);
        $this->assertCount(1, $this->llamadas_relaciones);

        $relaciones = $resultado['relaciones_parecidas'];

        $this->assertTrue($relaciones['ia_consultada']);

        $categoria = $this->item($relaciones, 'categoria|Del norte');
        $this->assertNotNull($categoria);
        $this->assertSame('misma_escritura', $categoria['motivo']);
        $this->assertSame((int) $del_norte->id, $categoria['opciones'][0]['id']);

        $this->assertSame('parecido', $this->item($relaciones, 'marca|Bosh')['motivo']);
        $this->assertSame('misma_escritura', $this->item($relaciones, 'proveedor|ACME')['motivo']);
        $this->assertSame('entre_nuevos', $this->item($relaciones, 'categoria|Pintureria')['motivo']);

        $this->assertAviso('Está lista la recomendación de importación para el archivo');
    }

    public function test_articulos_sin_la_bandera_el_resultado_es_el_de_siempre()
    {
        $this->crear_categoria('DEL NORTE');
        $this->crear_marca('Bosch');

        $this->falsear_ia($this->elegir_por_nombre(['Bosh' => 'Bosch']));

        $resultado = $this->recomendacion('article', self::ARTICULOS, $this->columnas_articulos(), null);

        $this->assertSame([
            'recomendacion_configuracion',
            'provider_codes_existentes_mismo_proveedor',
            'provider_codes_existentes_otros_proveedores',
            'formatos_numericos',
        ], array_keys($resultado));

        $this->assertSame(1, $this->llamadas_recomendacion);
        $this->assertCount(0, $this->llamadas_relaciones, 'Sin la bandera no se paga la llamada de relaciones.');
        Http::assertSentCount(1);
    }

    public function test_la_bandera_en_false_es_lo_mismo_que_no_mandarla()
    {
        $this->falsear_ia(function () {
            return null;
        });

        $resultado = $this->recomendacion('article', self::ARTICULOS, $this->columnas_articulos(), false);

        $this->assertArrayNotHasKey('relaciones_parecidas', $resultado);
        $this->assertCount(0, $this->llamadas_relaciones);
    }

    /* ------------------------------------------------------------------
     * Clientes y proveedores
     * ------------------------------------------------------------------ */

    public function test_clientes_con_la_bandera_traen_solo_el_chequeo()
    {
        $provincia = Provincia::create(['name' => 'BUENOS AIRES', 'user_id' => $this->tenant->id]);
        Location::create(['name' => 'LA PLATA', 'provincia_id' => $provincia->id, 'user_id' => $this->tenant->id]);
        $seller = Seller::create(['num' => 1, 'name' => 'JUAN PEREZ', 'user_id' => $this->tenant->id]);
        PriceType::create(['name' => 'MAYORISTA', 'user_id' => $this->tenant->id]);

        $this->falsear_ia(function () {
            return null;
        });

        $resultado = $this->recomendacion('client', self::CLIENTES, $this->columnas_clientes(), true);

        $this->assertNull($resultado['recomendacion_configuracion']);
        $this->assertSame(0, $resultado['provider_codes_existentes_mismo_proveedor']);
        $this->assertSame(0, $resultado['provider_codes_existentes_otros_proveedores']);
        $this->assertNull($resultado['formatos_numericos']);
        $this->assertSame(0, $this->llamadas_recomendacion);

        $relaciones = $resultado['relaciones_parecidas'];

        $this->assertSame([
            'localidad|Buenos Aires|La Plata|id:' . $provincia->id,
            'provincia|Buenos Aires',
            'tipo_de_precio|Mayorista',
            'vendedor|Juan Perez',
            'vendedor|Juan Pérez',
        ], $this->claves($relaciones));

        $localidad = $this->item($relaciones, 'localidad|Buenos Aires|La Plata|id:' . $provincia->id);
        $this->assertSame('Buenos Aires', $localidad['padre']);
        $this->assertSame((int) $provincia->id, $localidad['padre_id'], 'La localidad se compara dentro de la provincia supuesta.');
        $this->assertSame('misma_escritura', $localidad['motivo']);

        $this->assertSame((int) $seller->id, $this->item($relaciones, 'vendedor|Juan Pérez')['opciones'][0]['id']);

        $this->assertAviso('Revisamos los nombres parecidos del archivo');
    }

    /**
     * El aviso de fin de la corrida (GlobalNotification al dueño): para clientes y proveedores no
     * habla de "recomendación", que es de artículos.
     *
     * @param  string $texto
     * @return void
     */
    protected function assertAviso($texto)
    {
        Notification::assertSentTo($this->tenant, GlobalNotification::class, function ($notificacion) use ($texto) {
            return $notificacion->message_text === $texto;
        });
    }

    public function test_proveedores_con_la_bandera_traen_la_localidad()
    {
        $rosario = Location::create(['name' => 'Rosario', 'user_id' => $this->tenant->id]);

        $this->falsear_ia(function () {
            return null;
        });

        $resultado = $this->recomendacion('provider', self::PROVEEDORES, $this->columnas_proveedores(), true);

        $this->assertNull($resultado['recomendacion_configuracion']);

        $relaciones = $resultado['relaciones_parecidas'];

        $this->assertSame(['localidad|ROSARIO'], $this->claves($relaciones));
        $this->assertSame((int) $rosario->id, $this->item($relaciones, 'localidad|ROSARIO')['opciones'][0]['id']);
        $this->assertNull($this->item($relaciones, 'localidad|ROSARIO')['padre']);
        $this->assertCount(0, $this->llamadas_relaciones, '"Funes" no se parece a nada: sin candidatos no hay llamada.');

        $this->assertAviso('Revisamos los nombres parecidos del archivo');
    }

    public function test_clientes_y_proveedores_sin_la_bandera_no_leen_ni_llaman()
    {
        $this->falsear_ia(function () {
            return null;
        });

        foreach ([['client', self::CLIENTES, $this->columnas_clientes()], ['provider', self::PROVEEDORES, $this->columnas_proveedores()]] as $caso) {
            $resultado = $this->recomendacion($caso[0], $caso[1], $caso[2], null);

            $this->assertSame([
                'recomendacion_configuracion'                 => null,
                'provider_codes_existentes_mismo_proveedor'   => 0,
                'provider_codes_existentes_otros_proveedores' => 0,
                'formatos_numericos'                          => null,
            ], $resultado, $caso[0]);

            $this->assertProcesoCompletado($caso[0]);
        }

        Http::assertNothingSent();

        /* Sin la bandera no se revisó nada: el aviso queda el de antes. */
        $this->assertAviso('Está lista la recomendación de importación para el archivo');
        Notification::assertNotSentTo($this->tenant, GlobalNotification::class, function ($notificacion) {
            return strpos($notificacion->message_text, 'nombres parecidos') !== false;
        });
    }

    /**
     * La rama de clientes y proveedores cierra el proceso visible (la píldora): si quedara "en
     * curso", a las 3 horas cerrar_colgados() lo marcaría como fallo.
     */
    public function test_clientes_con_la_bandera_cierran_el_proceso_en_segundo_plano()
    {
        $this->falsear_ia(function () {
            return null;
        });

        $this->recomendacion('client', self::CLIENTES, $this->columnas_clientes(), true);

        $this->assertProcesoCompletado('client');
    }

    /**
     * @param  string $mensaje
     * @return void
     */
    protected function assertProcesoCompletado($mensaje)
    {
        $run = ExcelAnalysisRun::where('uuid', $this->ultimo_uuid)->first();
        $proceso = BackgroundProcessHelper::por_referencia($run, false);

        $this->assertNotNull($proceso, $mensaje . ': la corrida no abrió proceso visible.');
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status, $mensaje . ': el proceso quedó "' . $proceso->status . '".');
    }
}
