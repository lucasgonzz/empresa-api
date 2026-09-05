<?php

namespace Tests\Feature\Import;

use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\Provider;
use App\Models\ProviderDiscount;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Tests\EmpresaTestCase;

/**
 * Mision `descuentos-proveedor-en-import` (5/9/2026).
 *
 * La importacion de Excel de articulos pasa a respetar la preferencia del comercio
 * `users.aplicar_descuentos_proveedor_al_asignar`, igual que ya lo hacen la ficha del articulo
 * (mision `descuentos-proveedor-al-asignar`, 4/9/2026) y la actualizacion masiva.
 *
 * Las reglas que fija esta suite, y que son las que decidio Lucas:
 *
 *   1. Si la columna `descuentos` esta mapeada, manda el Excel. El estandar del proveedor se
 *      aplica solo cuando esa columna NO esta mapeada.
 *   2. Reimportar la misma lista con el mismo proveedor no rehace los descuentos: el disparador es
 *      asignar o cambiar el proveedor, no cada corrida.
 *   3. Excepcion a la 2: si el articulo tiene proveedor P y no tiene NINGUN descuento tagueado a
 *      P, el import se los materializa aunque P no haya cambiado. Es el catalogo anterior a la
 *      preferencia poniendose al dia solo.
 *
 * 🔴 LO QUE MAS IMPORTA DE ESTE ARCHIVO SON LOS DOS TESTS DE LA PREFERENCIA APAGADA: apagada es el
 * default de los ~40 comercios, y con ella apagada el import tiene que costear exactamente igual
 * que ayer. Si esos dos se ponen rojos, el cambio le movio los costos a comercios que no pidieron
 * nada.
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class DescuentosProveedorEnImportTest extends EmpresaTestCase
{
    /** Tolerancia de plata: nunca comparacion exacta sobre floats. */
    const DELTA = 0.01;

    /** Proveedor con dos bonificaciones estandar (10% y 5%). */
    const PROVEEDOR_A = 'zz Prov Import Descuentos A';

    /** Proveedor con una sola bonificacion estandar (25%), para el cambio A -> B. */
    const PROVEEDOR_B = 'zz Prov Import Descuentos B';

    /** Tercer proveedor: sus descuentos tagueados no son de esta operacion y no se tocan nunca. */
    const PROVEEDOR_C = 'zz Prov Import Descuentos C';

    /**
     * Cabecera del Excel que genera esta suite. El orden es fijo, ver columnas().
     *
     * `descuentos_montos` esta en la cabecera SIEMPRE, pero se mapea solo en los tests que lo
     * piden: lo que decide la rama del importador no es que la columna exista en el archivo sino
     * que este MAPEADA (ImportHelper::isIgnoredColumn()). Las filas que no la usan simplemente
     * traen menos celdas.
     */
    const CABECERA = ['codigo_de_barras', 'nombre', 'costo', 'descuentos', 'descuentos_montos'];

    /** @var \App\Models\Provider */
    protected $proveedor_a;

    /** @var \App\Models\Provider */
    protected $proveedor_b;

    /** @var \App\Models\Provider */
    protected $proveedor_c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proveedor_a = $this->proveedor_con_descuentos(self::PROVEEDOR_A, [10, 5]);
        $this->proveedor_b = $this->proveedor_con_descuentos(self::PROVEEDOR_B, [25]);
        $this->proveedor_c = $this->proveedor_con_descuentos(self::PROVEEDOR_C, []);
    }

    /**
     * Owner del fixture: es de quien se lee la preferencia y de quien son los articulos.
     *
     * @return \App\Models\User
     */
    private function owner()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Deja la preferencia del comercio en el estado que pide el test.
     *
     * @param  int $valor 0 apagada, 1 prendida.
     * @return void
     */
    private function set_preferencia($valor)
    {
        $owner = $this->owner();
        $owner->aplicar_descuentos_proveedor_al_asignar = $valor;
        $owner->save();
    }

    /**
     * Proveedor con sus bonificaciones estandar (`provider_discounts`) cargadas.
     *
     * Se rearma la lista en cada llamada para que el test no dependa de lo que haya quedado de una
     * corrida anterior: DatabaseTransactions revierte, pero el firstOrCreate puede tomar una fila
     * sembrada por otra suite con el mismo nombre.
     *
     * @param  string $nombre
     * @param  array  $porcentajes
     * @return \App\Models\Provider
     */
    private function proveedor_con_descuentos($nombre, $porcentajes)
    {
        $provider = Provider::firstOrCreate([
            'name'    => $nombre,
            'user_id' => $this->owner()->id,
        ]);

        ProviderDiscount::where('provider_id', $provider->id)->delete();

        foreach ($porcentajes as $porcentaje) {
            ProviderDiscount::create([
                'provider_id' => $provider->id,
                'percentage'  => $porcentaje,
            ]);
        }

        return $provider->fresh();
    }

    /**
     * Articulo del comercio, creado directo (no por el endpoint) para poder fijar el estado
     * exacto del que parte cada test.
     *
     * @param  string   $nombre
     * @param  string   $bar_code
     * @param  float    $cost
     * @param  int|null $provider_id
     * @return \App\Models\Article
     */
    private function crear_articulo($nombre, $bar_code, $cost, $provider_id)
    {
        $article = new Article();

        $article->user_id     = $this->owner()->id;
        $article->name        = $nombre;
        $article->bar_code    = $bar_code;
        $article->cost        = $cost;
        $article->provider_id = $provider_id;
        $article->iva_id      = 2;
        $article->status      = 'active';

        $article->save();

        return $article->fresh();
    }

    /**
     * Descuento tagueado a un proveedor, ya materializado sobre un articulo.
     *
     * @param  \App\Models\Article $article
     * @param  int                 $provider_id
     * @param  float               $percentage
     * @param  string              $origen  ArticleDiscount::ORIGEN_*
     * @return \App\Models\ArticleDiscount
     */
    private function tagear_descuento($article, $provider_id, $percentage, $origen)
    {
        return ArticleDiscount::create([
            'article_id'  => $article->id,
            'provider_id' => $provider_id,
            'percentage'  => $percentage,
            'tipo'        => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR,
            'origen'      => $origen,
        ]);
    }

    /**
     * Mapeo de columnas del Excel que genera esta suite. Posiciones 1-based, igual que las manda
     * el front: GeneralHelper::getImportColumns() las pasa a indices 0-based.
     *
     * @param  bool $con_descuentos Si la columna `descuentos` esta MAPEADA en esta importacion.
     * @param  bool $con_montos     Si la columna `descuentos_montos` esta MAPEADA.
     * @return array
     */
    private function columnas($con_descuentos, $con_montos = false)
    {
        $columnas = [
            'prop_codigo_de_barras' => 1,
            'prop_nombre'           => 2,
            'prop_costo'            => 3,
        ];

        if ($con_descuentos) {
            $columnas['prop_descuentos'] = 4;
        }

        if ($con_montos) {
            $columnas['prop_descuentos_montos'] = 5;
        }

        return $columnas;
    }

    /**
     * Escribe un xlsx temporal con la cabecera de la suite y las filas indicadas.
     *
     * @param  array $filas
     * @return string Ruta del archivo generado.
     */
    private function escribir_excel(array $filas)
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('import_descuentos_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);

        $writer->addRow(WriterEntityFactory::createRowFromArray(self::CABECERA));

        foreach ($filas as $fila) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($fila));
        }

        $writer->close();

        return $ruta;
    }

    /**
     * Dispara la importacion contra el endpoint real.
     *
     * Con QUEUE_CONNECTION=sync (phpunit.xml) la cadena de jobs corre inline dentro del POST, asi
     * que al volver de aca la importacion ya termino.
     *
     * @param  array $filas          Filas de datos del Excel.
     * @param  bool  $con_descuentos Si se mapea la columna `descuentos`.
     * @param  array $config         Overrides de configuracion (provider_id, banderas).
     * @param  bool  $con_montos     Si se mapea la columna `descuentos_montos`.
     * @return void
     */
    private function importar(array $filas, $con_descuentos, array $config = [], $con_montos = false)
    {
        $ruta = $this->escribir_excel($filas);

        $data = array_merge(
            [
                'models' => new UploadedFile(
                    $ruta,
                    basename($ruta),
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
                'start_row'   => 2,
                /* InitExcelImport::ajustar_finish_row_segun_excel_real() lo baja al real. */
                'finish_row'  => 99999,
                'provider_id' => null,

                'create_and_edit'                                    => true,
                'permitir_provider_code_repetido'                    => false,
                'permitir_provider_code_repetido_en_multi_providers' => true,
                'actualizar_articulos_de_otro_proveedor'             => false,
                'actualizar_por_provider_code'                       => true,
                'actualizar_proveedor'                               => true,
                'registrar_art_cre'                                  => true,
                'registrar_art_act'                                  => true,
            ],
            $this->columnas($con_descuentos, $con_montos),
            $config
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);
    }

    /**
     * Descuentos tagueados a un proveedor de un articulo, ordenados por porcentaje.
     *
     * @param  \App\Models\Article $article
     * @param  int                 $provider_id
     * @return \Illuminate\Support\Collection
     */
    private function tagueados($article, $provider_id)
    {
        return ArticleDiscount::where('article_id', $article->id)
                                ->where('provider_id', $provider_id)
                                ->orderBy('percentage')
                                ->get();
    }

    /**
     * Porcentajes tagueados a un proveedor, como floats ordenados.
     *
     * @param  \App\Models\Article $article
     * @param  int                 $provider_id
     * @return array
     */
    private function porcentajes_tagueados($article, $provider_id)
    {
        return $this->tagueados($article, $provider_id)
                    ->filter(function ($descuento) {
                        return !is_null($descuento->percentage);
                    })
                    ->map(function ($descuento) {
                        return (float) $descuento->percentage;
                    })
                    ->values()
                    ->all();
    }

    /**
     * Montos tagueados a un proveedor, como floats. Los descuentos por monto (`descuentos_montos`)
     * conviven con los porcentuales en la misma tabla, distinguidos por cual de las dos columnas
     * esta cargada.
     *
     * @param  \App\Models\Article $article
     * @param  int                 $provider_id
     * @return array
     */
    private function montos_tagueados($article, $provider_id)
    {
        return $this->tagueados($article, $provider_id)
                    ->filter(function ($descuento) {
                        return !is_null($descuento->amount);
                    })
                    ->map(function ($descuento) {
                        return (float) $descuento->amount;
                    })
                    ->values()
                    ->all();
    }

    /* ------------------------------------------------------------------ *
     * Preferencia PRENDIDA
     * ------------------------------------------------------------------ */

    /**
     * Criterio 1: el import CREA un articulo con un proveedor que tiene bonificaciones y la
     * columna `descuentos` no esta mapeada -> el articulo nace con los descuentos del proveedor,
     * con origen `ficha_proveedor`, y el costo real sale ya descontado.
     *
     * El costo real es la mitad del criterio: materializar los descuentos y no recalcular el costo
     * deja la fila de la base bien y el precio mal, que es exactamente la clase de error que no se
     * ve hasta que el comercio vende.
     *
     * @return void
     */
    public function test_crear_un_articulo_con_proveedor_le_materializa_sus_descuentos()
    {
        $this->set_preferencia(1);

        $this->importar(
            [['7799801', 'zz Art import descuentos nuevo', 1000]],
            false,
            ['provider_id' => $this->proveedor_a->id]
        );

        $article = Article::where('bar_code', '7799801')->first();

        $this->assertNotNull($article, 'La importacion tenia que crear el articulo.');

        $this->assertSame(
            (int) $this->proveedor_a->id,
            (int) $article->provider_id,
            'El articulo creado tiene que quedar con el proveedor de la importacion.'
        );

        $this->assertSame(
            [5.0, 10.0],
            $this->porcentajes_tagueados($article, $this->proveedor_a->id),
            'El articulo nuevo tiene que quedar con las dos bonificaciones estandar del proveedor.'
        );

        foreach ($this->tagueados($article, $this->proveedor_a->id) as $descuento) {
            $this->assertSame(
                ArticleDiscount::ORIGEN_FICHA_PROVEEDOR,
                $descuento->origen,
                'Los descuentos que salen del estandar del proveedor llevan origen ficha_proveedor, '.
                'no import: es lo que despues decide si una propagacion puede rehacerlos.'
            );
        }

        /* Cascada: 1000 x 0,90 x 0,95 = 855. NO es 1000 - 15% = 850. */
        $this->assertEqualsWithDelta(
            855,
            (float) $article->costo_real,
            self::DELTA,
            'El costo real tiene que salir con los dos descuentos recien insertados aplicados en '.
            'cascada. Si da 1000, el precio se calculo con la relacion vieja en memoria.'
        );
    }

    /**
     * Criterio 2: el import le CAMBIA el proveedor a un articulo (A -> B).
     *
     * 🔴 El barrido es ACOTADO: se van los tagueados de A y quedan los de C. Los descuentos
     * tagueados de un tercer proveedor pueden ser la bonificacion negociada de una compra real, y
     * no son de esta operacion para borrarlos.
     *
     * @return void
     */
    public function test_cambiar_el_proveedor_barre_solo_los_del_anterior()
    {
        $this->set_preferencia(1);

        $article = $this->crear_articulo(
            'zz Art import cambio de proveedor',
            '7799802',
            1000,
            $this->proveedor_a->id
        );

        $this->tagear_descuento($article, $this->proveedor_a->id, 10, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);
        $descuento_de_c = $this->tagear_descuento($article, $this->proveedor_c->id, 7, ArticleDiscount::ORIGEN_COMPRA);

        $this->importar(
            [['7799802', 'zz Art import cambio de proveedor', 1000]],
            false,
            [
                'provider_id'                            => $this->proveedor_b->id,
                'actualizar_articulos_de_otro_proveedor' => true,
            ]
        );

        $article = $article->fresh();

        $this->assertSame(
            (int) $this->proveedor_b->id,
            (int) $article->provider_id,
            'La importacion tenia que cambiarle el proveedor al articulo.'
        );

        $this->assertCount(
            0,
            $this->tagueados($article, $this->proveedor_a->id),
            'Los descuentos tagueados del proveedor ANTERIOR tienen que haberse ido.'
        );

        $this->assertSame(
            [25.0],
            $this->porcentajes_tagueados($article, $this->proveedor_b->id),
            'El articulo tiene que quedar con la bonificacion del proveedor NUEVO.'
        );

        $this->assertNotNull(
            ArticleDiscount::find($descuento_de_c->id),
            '🔴 El descuento tagueado de un TERCER proveedor no es de esta operacion: no se toca.'
        );
    }

    /**
     * Criterio 3 (regla 3 + fallback al proveedor del articulo): articulo con proveedor P que no
     * tiene NINGUN descuento tagueado a P, importado con un Excel que no trae columna de proveedor
     * ni proveedor fijo -> se le materializan los de P aunque P no haya cambiado.
     *
     * Es el formato de Excel mas comun (actualizacion de costos a secas) y el que hace que un
     * catalogo anterior a la preferencia se ponga al dia solo. No pisa nada porque no hay nada que
     * pisar.
     *
     * @return void
     */
    public function test_articulo_con_proveedor_pero_sin_tagueados_se_pone_al_dia()
    {
        $this->set_preferencia(1);

        $article = $this->crear_articulo(
            'zz Art import sin tagueados',
            '7799803',
            1000,
            $this->proveedor_a->id
        );

        /* Sin `provider_id` en la configuracion: el proveedor sale del articulo persistido. */
        $this->importar(
            [['7799803', 'zz Art import sin tagueados', 2000]],
            false
        );

        $article = $article->fresh();

        $this->assertSame(
            (int) $this->proveedor_a->id,
            (int) $article->provider_id,
            'Un Excel sin columna de proveedor no tiene que cambiarle el proveedor al articulo.'
        );

        $this->assertSame(
            [5.0, 10.0],
            $this->porcentajes_tagueados($article, $this->proveedor_a->id),
            'Al articulo sin ningun tagueado de su propio proveedor hay que materializarle los suyos.'
        );

        /* Cascada sobre el costo NUEVO del Excel: 2000 x 0,90 x 0,95 = 1710. */
        $this->assertEqualsWithDelta(
            1710,
            (float) $article->costo_real,
            self::DELTA,
            'El costo real tiene que salir del costo importado con los descuentos recien insertados.'
        );
    }

    /**
     * Criterio 4: reimportar la misma lista con el mismo proveedor sobre un articulo que YA tiene
     * sus tagueados no toca ningun descuento.
     *
     * Se asertan los IDS, no los porcentajes: un delete + insert que deje los mismos numeros
     * pasaria una asercion por valor, y sin embargo habria borrado las ediciones que el comercio
     * le hizo a esos descuentos (el tilde de "mostrar en la tienda", la marca de editado a mano).
     *
     * @return void
     */
    public function test_reimportar_la_misma_lista_no_toca_los_descuentos()
    {
        $this->set_preferencia(1);

        $article = $this->crear_articulo(
            'zz Art import reimportado',
            '7799804',
            1000,
            $this->proveedor_a->id
        );

        $diez  = $this->tagear_descuento($article, $this->proveedor_a->id, 10, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);
        $cinco = $this->tagear_descuento($article, $this->proveedor_a->id, 5, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);

        $this->importar(
            [['7799804', 'zz Art import reimportado', 1200]],
            false,
            ['provider_id' => $this->proveedor_a->id]
        );

        $article = $article->fresh();

        $ids = $this->tagueados($article, $this->proveedor_a->id)
                    ->pluck('id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->sort()
                    ->values()
                    ->all();

        $esperados = [(int) $cinco->id, (int) $diez->id];
        sort($esperados);

        $this->assertSame(
            $esperados,
            $ids,
            'Reimportar no puede borrar ni duplicar los descuentos que el articulo ya tenia: '.
            'el disparador es asignar o cambiar el proveedor, no cada corrida.'
        );
    }

    /**
     * Criterio 5: articulo con la bonificacion de una COMPRA real del proveedor P, importado con
     * ese mismo P y sin columna de descuentos -> la bonificacion de la compra sigue ahi.
     *
     * Es el caso que mas duele si sale mal: la ficha del proveedor no tiene de donde reponer una
     * bonificacion negociada en una compra, asi que pisarla la pierde para siempre y le sube el
     * costo al articulo sin aviso.
     *
     * @return void
     */
    public function test_la_bonificacion_de_una_compra_sobrevive_al_import()
    {
        $this->set_preferencia(1);

        $article = $this->crear_articulo(
            'zz Art import bonificacion de compra',
            '7799805',
            1000,
            $this->proveedor_a->id
        );

        $de_la_compra = $this->tagear_descuento($article, $this->proveedor_a->id, 12, ArticleDiscount::ORIGEN_COMPRA);

        $this->importar(
            [['7799805', 'zz Art import bonificacion de compra', 1000]],
            false,
            ['provider_id' => $this->proveedor_a->id]
        );

        $article = $article->fresh();

        $vigentes = $this->tagueados($article, $this->proveedor_a->id);

        $this->assertCount(
            1,
            $vigentes,
            'El import no tenia que agregarle nada: el articulo ya tenia un tagueado de su proveedor.'
        );

        $this->assertSame(
            (int) $de_la_compra->id,
            (int) $vigentes->first()->id,
            '🔴 La bonificacion de la compra tiene que seguir siendo la MISMA fila.'
        );

        $this->assertSame(
            ArticleDiscount::ORIGEN_COMPRA,
            $vigentes->first()->origen,
            'El origen de la bonificacion de la compra no se pisa con el del import.'
        );
    }

    /**
     * Criterio 6 (regla 1): con la columna `descuentos` MAPEADA manda el Excel, aunque el
     * proveedor tenga bonificaciones estandar y la preferencia este prendida. El estandar no
     * aparece y el origen es `import`.
     *
     * @return void
     */
    public function test_la_columna_mapeada_le_gana_al_estandar_del_proveedor()
    {
        $this->set_preferencia(1);

        $this->importar(
            [['7799806', 'zz Art import columna mapeada', 1000, '20']],
            true,
            ['provider_id' => $this->proveedor_a->id]
        );

        $article = Article::where('bar_code', '7799806')->first();

        $this->assertNotNull($article, 'La importacion tenia que crear el articulo.');

        $this->assertSame(
            [20.0],
            $this->porcentajes_tagueados($article, $this->proveedor_a->id),
            'Con la columna mapeada manda el Excel: el 10 y el 5 del proveedor no tienen que aparecer.'
        );

        $this->assertSame(
            ArticleDiscount::ORIGEN_IMPORT,
            $this->tagueados($article, $this->proveedor_a->id)->first()->origen,
            'Lo que trajo la planilla lleva origen import.'
        );

        $this->assertEqualsWithDelta(
            800,
            (float) $article->costo_real,
            self::DELTA,
            'El costo real tiene que salir con el 20% de la planilla.'
        );
    }

    /* ------------------------------------------------------------------ *
     * Preferencia APAGADA (el default de los ~40 comercios)
     * ------------------------------------------------------------------ */

    /**
     * 🔴 Criterio 7: mismo escenario que el criterio 1 pero con la preferencia APAGADA -> no se
     * materializa ningun descuento y el costo real queda en el costo bruto.
     *
     * Este es el test que protege a los comercios que no pidieron nada.
     *
     * @return void
     */
    public function test_con_la_preferencia_apagada_el_import_no_materializa_el_estandar()
    {
        $this->set_preferencia(0);

        $this->importar(
            [['7799807', 'zz Art import preferencia apagada', 1000]],
            false,
            ['provider_id' => $this->proveedor_a->id]
        );

        $article = Article::where('bar_code', '7799807')->first();

        $this->assertNotNull($article, 'La importacion tenia que crear el articulo igual.');

        $this->assertCount(
            0,
            ArticleDiscount::where('article_id', $article->id)->get(),
            'Con la preferencia apagada el import no puede crearle ningun descuento al articulo.'
        );

        $this->assertEqualsWithDelta(
            1000,
            (float) $article->costo_real,
            self::DELTA,
            'Con la preferencia apagada el costo real queda en el costo bruto, sin descuento.'
        );
    }

    /**
     * Criterio 8: la preferencia NO apaga la columna mapeada. Con la columna `descuentos` en el
     * Excel, la planilla sigue mandando igual que siempre.
     *
     * La preferencia gobierna el estandar del proveedor —que es lo que el comercio no pidio— y
     * nada mas. Apagarla no puede hacer que el import deje de importar lo que el usuario escribio
     * en su archivo.
     *
     * @return void
     */
    public function test_con_la_preferencia_apagada_la_columna_mapeada_sigue_mandando()
    {
        $this->set_preferencia(0);

        $this->importar(
            [['7799808', 'zz Art import apagada con columna', 1000, '20']],
            true,
            ['provider_id' => $this->proveedor_a->id]
        );

        $article = Article::where('bar_code', '7799808')->first();

        $this->assertNotNull($article, 'La importacion tenia que crear el articulo.');

        $this->assertSame(
            [20.0],
            $this->porcentajes_tagueados($article, $this->proveedor_a->id),
            'La preferencia no gobierna lo que el usuario escribio en su planilla.'
        );

        $this->assertSame(
            ArticleDiscount::ORIGEN_IMPORT,
            $this->tagueados($article, $this->proveedor_a->id)->first()->origen,
            'Lo que trajo la planilla lleva origen import.'
        );

        $this->assertEqualsWithDelta(
            800,
            (float) $article->costo_real,
            self::DELTA,
            'El costo real tiene que salir con el 20% de la planilla.'
        );
    }

    /* ------------------------------------------------------------------ *
     * Lo que encontraron los chequeos independientes (5/9/2026)
     * ------------------------------------------------------------------ */

    /**
     * 🔴 Con el tilde "actualizar proveedor" APAGADO, el proveedor del articulo NO cambia — asi que
     * tampoco pueden cambiar sus descuentos.
     *
     * Es la combinacion que usa el comercio para decir "actualizame los costos aunque el articulo
     * sea de otro proveedor, pero no me toques el proveedor": `actualizar_proveedor = false` con
     * `actualizar_articulos_de_otro_proveedor = true`. get_modified_fields() saca `provider_id` de
     * los cambios, asi que el articulo se queda con el suyo.
     *
     * El defecto que cierra este test: tomando el proveedor de la FILA como "proveedor nuevo", el
     * articulo del proveedor A perdia los descuentos de A y ganaba los estandar de B, quedando con
     * `article_discounts.provider_id = B` colgando de un `articles.provider_id = A` y el costo_real
     * calculado con los descuentos de un proveedor que no es el suyo. Y como old !== new seguia
     * siendo cierto, se repetia en cada corrida.
     *
     * @return void
     */
    public function test_con_actualizar_proveedor_apagado_los_descuentos_no_se_mueven()
    {
        $this->set_preferencia(1);

        $article = $this->crear_articulo(
            'zz Art import sin actualizar proveedor',
            '7799809',
            1000,
            $this->proveedor_a->id
        );

        $diez  = $this->tagear_descuento($article, $this->proveedor_a->id, 10, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);
        $cinco = $this->tagear_descuento($article, $this->proveedor_a->id, 5, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);

        $this->importar(
            /* Costo distinto del que tiene el articulo a proposito: asi la fila entra a la cola de
               actualizacion y set_precios_finales() recalcula, que es lo que hace verificable el
               costo_real de abajo. */
            [['7799809', 'zz Art import sin actualizar proveedor', 2000]],
            false,
            [
                'provider_id'                            => $this->proveedor_b->id,
                'actualizar_articulos_de_otro_proveedor' => true,
                'actualizar_proveedor'                   => false,
            ]
        );

        $article = $article->fresh();

        $this->assertSame(
            (int) $this->proveedor_a->id,
            (int) $article->provider_id,
            'Con el tilde apagado el proveedor del articulo no se toca: sigue siendo A.'
        );

        $ids = $this->tagueados($article, $this->proveedor_a->id)
                    ->pluck('id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->sort()
                    ->values()
                    ->all();

        $esperados = [(int) $cinco->id, (int) $diez->id];
        sort($esperados);

        $this->assertSame(
            $esperados,
            $ids,
            'El articulo tiene que conservar EXACTAMENTE los descuentos de su proveedor A, las '.
            'mismas filas: no cambio de proveedor, no hay nada que rehacer.'
        );

        $this->assertCount(
            0,
            $this->tagueados($article, $this->proveedor_b->id),
            '🔴 No puede quedar ningun descuento tagueado a un proveedor que el articulo NO tiene.'
        );

        /* Cascada con los descuentos de A, los unicos que corresponden: 2000 x 0,90 x 0,95 = 1710. */
        $this->assertEqualsWithDelta(
            1710,
            (float) $article->costo_real,
            self::DELTA,
            'El costo real se calcula con los descuentos del proveedor que el articulo realmente '.
            'tiene. Si da 1500, se costeo con el 25% del proveedor de la planilla.'
        );
    }

    /**
     * 🔴 Cambiar a un proveedor que YA tenia su estandar de ficha materializado en ese articulo no
     * lo duplica.
     *
     * Caso real: articulo del proveedor A que ya tenia tagueado el estandar de ficha de B (25%, de
     * una asignacion anterior). El import le cambia el proveedor a B, cuyo estandar es ese mismo
     * 25%. Si el barrido acotado borrara solo los del proveedor ANTERIOR, el articulo quedaria con
     * 25% + 25% en cascada: factor 0,5625 en vez de 0,75, y sobre ese costo se calculan todos los
     * precios de venta.
     *
     * @return void
     */
    public function test_cambiar_de_proveedor_no_duplica_el_estandar_de_ficha_del_nuevo()
    {
        $this->set_preferencia(1);

        $article = $this->crear_articulo(
            'zz Art import cambio con estandar previo',
            '7799810',
            1000,
            $this->proveedor_a->id
        );

        $this->tagear_descuento($article, $this->proveedor_a->id, 10, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);
        $this->tagear_descuento($article, $this->proveedor_b->id, 25, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);

        $this->importar(
            [['7799810', 'zz Art import cambio con estandar previo', 1000]],
            false,
            [
                'provider_id'                            => $this->proveedor_b->id,
                'actualizar_articulos_de_otro_proveedor' => true,
            ]
        );

        $article = $article->fresh();

        $this->assertSame(
            (int) $this->proveedor_b->id,
            (int) $article->provider_id,
            'La importacion tenia que cambiarle el proveedor al articulo.'
        );

        $this->assertCount(
            0,
            $this->tagueados($article, $this->proveedor_a->id),
            'Los descuentos del proveedor anterior tienen que haberse ido, sin mirar el origen: el '.
            'articulo dejo de ser de ese proveedor.'
        );

        $this->assertSame(
            [25.0],
            $this->porcentajes_tagueados($article, $this->proveedor_b->id),
            '🔴 El estandar del proveedor nuevo tiene que quedar UNA sola vez. Si aparece [25, 25], '.
            'el barrido acotado dejo el estandar viejo de B y le encimo el nuevo.'
        );

        /* 1000 x 0,75 = 750. Con la duplicacion daba 1000 x 0,75 x 0,75 = 562,50. */
        $this->assertEqualsWithDelta(
            750,
            (float) $article->costo_real,
            self::DELTA,
            'El costo real tiene que salir con el 25% de B una sola vez.'
        );
    }

    /**
     * 🔴 La otra mitad de la asimetria del barrido acotado: la bonificacion de una COMPRA real del
     * proveedor NUEVO sobrevive al cambio de proveedor.
     *
     * Caso real: articulo del proveedor A que pasa a B, donde B ya le habia dado a ese articulo una
     * bonificacion negociada en una compra (12%, origen `compra`), ademas de su estandar de ficha
     * (25%). Del proveedor nuevo se borra SOLO lo de origen `ficha_proveedor` —que es lo unico que
     * esta operacion va a volver a crear, o sea lo unico que puede duplicarse—; el 12% de la compra
     * se queda, porque es una condicion que el comercio negocio de verdad con el proveedor al que el
     * articulo justamente esta pasando. Borrarlo seria destruir informacion que ningun import puede
     * reponer.
     *
     * Es la diferencia con el proveedor ANTERIOR, del que se borra TODO: ese ya no es el proveedor
     * del articulo y sus condiciones no tienen por que seguir descontandole el costo.
     *
     * @return void
     */
    public function test_cambiar_de_proveedor_respeta_la_bonificacion_de_compra_del_nuevo()
    {
        $this->set_preferencia(1);

        $article = $this->crear_articulo(
            'zz Art import cambio con compra del nuevo',
            '7799814',
            1000,
            $this->proveedor_a->id
        );

        $this->tagear_descuento($article, $this->proveedor_a->id, 10, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);
        $this->tagear_descuento($article, $this->proveedor_b->id, 25, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);
        $compra_de_b = $this->tagear_descuento($article, $this->proveedor_b->id, 12, ArticleDiscount::ORIGEN_COMPRA);

        $this->importar(
            [['7799814', 'zz Art import cambio con compra del nuevo', 1000]],
            false,
            [
                'provider_id'                            => $this->proveedor_b->id,
                'actualizar_articulos_de_otro_proveedor' => true,
            ]
        );

        $article = $article->fresh();

        $this->assertNotNull(
            ArticleDiscount::find($compra_de_b->id),
            '🔴 La bonificacion negociada en una compra con el proveedor NUEVO no es del import '.
            'para borrarla: el articulo esta pasando justamente a ese proveedor.'
        );

        $this->assertSame(
            [12.0, 25.0],
            $this->porcentajes_tagueados($article, $this->proveedor_b->id),
            'Tienen que quedar los dos: el 12% de la compra que ya estaba y el 25% del estandar, '.
            'una sola vez cada uno.'
        );

        $this->assertCount(
            0,
            $this->tagueados($article, $this->proveedor_a->id),
            'Del proveedor ANTERIOR se va todo, sin mirar el origen.'
        );

        /* Cascada: 1000 x 0,88 x 0,75 = 660. */
        $this->assertEqualsWithDelta(
            660,
            (float) $article->costo_real,
            self::DELTA,
            'Si da 750, el import se comio la bonificacion de compra de B y le subio el costo al '.
            'comercio sin avisar.'
        );
    }

    /**
     * 🔴 Caso MIXTO (columna `descuentos` NO mapeada, `descuentos_montos` SI): la preferencia NO
     * gobierna esta rama. Apagada, el import costea EXACTAMENTE como `origin/develop`.
     *
     * Es la contracara del test de mas abajo (el mismo escenario con la preferencia prendida): los
     * dos tienen que dar el MISMO resultado, y eso es justamente lo que fija este test.
     *
     * 🔴 Por que el gate de la preferencia no esta en el CASO B, y por que este test dice hoy lo
     * contrario de lo que decia el 5/9/2026 a la mañana:
     *
     * Con alguna columna de descuentos mapeada la fila cae en el barrido TOTAL de
     * sync_provider_discounts(), que borra los tagueados de CUALQUIER proveedor. Si ademas se
     * gateara el estandar por la preferencia, no se repondria ningun porcentaje y el articulo
     * perderia los que tenia —incluida la bonificacion de una compra real— con el costo subiendo en
     * silencio. Se intento tapar ese agujero preservando lo existente, y salio PEOR: se preservaba
     * mirando los tagueados de cualquier proveedor y se re-creaban tagueados al proveedor de la
     * fila con origen `import`, o sea un 12% negociado con A terminaba figurando como negociado con
     * B. Migrar descuentos entre proveedores es destructivo e irreversible; dejar el CASO B igual a
     * develop no rompe nada. Se eligio lo segundo (Lucas, 5/9/2026).
     *
     * Lo que la preferencia SI gobierna es el CASO A —ninguna columna de descuentos mapeada—, que
     * es donde el estandar del proveedor se materializa sin que la planilla lo pida. Ver
     * test_con_la_preferencia_apagada_el_import_no_materializa_el_estandar().
     *
     * @return void
     */
    public function test_caso_mixto_con_la_preferencia_apagada_se_comporta_como_develop()
    {
        $this->set_preferencia(0);

        $article = $this->crear_articulo(
            'zz Art import mixto apagada',
            '7799811',
            1000,
            $this->proveedor_a->id
        );

        $this->tagear_descuento($article, $this->proveedor_a->id, 30, ArticleDiscount::ORIGEN_COMPRA);

        /* Columna `descuentos` NO mapeada, `descuentos_montos` SI: la celda de la posicion 4 va
           vacia justamente para que no se mapee nada por accidente. */
        $this->importar(
            [['7799811', 'zz Art import mixto apagada', 1000, '', '50']],
            false,
            ['provider_id' => $this->proveedor_a->id],
            true
        );

        $article = $article->fresh();

        $this->assertSame(
            [5.0, 10.0],
            $this->porcentajes_tagueados($article, $this->proveedor_a->id),
            'La preferencia no toca el CASO B: el estandar del proveedor se vuelca como parte de la '.
            'fila y el 30% que habia queda reemplazado, igual que en develop.'
        );

        foreach ($this->tagueados($article, $this->proveedor_a->id) as $descuento) {
            $this->assertSame(
                ArticleDiscount::ORIGEN_IMPORT,
                $descuento->origen,
                'Una fila que trae alguna columna de descuentos es CASO B: el origen es `import`.'
            );
        }

        $this->assertSame(
            [50.0],
            $this->montos_tagueados($article, $this->proveedor_a->id),
            'El monto de la planilla si tiene que aplicarse: la columna esta mapeada.'
        );

        /* 1000 x 0,90 x 0,95 = 855; 855 - 50 = 805. El mismo numero que con la preferencia
           prendida, que es todo el punto de este test. */
        $this->assertEqualsWithDelta(
            805,
            (float) $article->costo_real,
            self::DELTA,
            'Con la preferencia apagada el CASO B tiene que costear igual que con ella prendida.'
        );
    }

    /**
     * Caso MIXTO con la preferencia PRENDIDA: cae en el CASO B (barrido TOTAL, origen `import`).
     *
     * Es la contracara del test de arriba y fija que el mixto no se cuela por la rama del estandar
     * acotado: con la preferencia prendida, el estandar del proveedor se vuelca como parte de lo
     * que trae la planilla —barrido total, origen `import`— y lo que el articulo tuviera tagueado
     * de antes se reemplaza, que es el comportamiento de siempre para una fila que trae descuentos.
     *
     * @return void
     */
    public function test_caso_mixto_con_la_preferencia_prendida_cae_en_barrido_total()
    {
        $this->set_preferencia(1);

        $article = $this->crear_articulo(
            'zz Art import mixto prendida',
            '7799812',
            1000,
            $this->proveedor_a->id
        );

        $this->tagear_descuento($article, $this->proveedor_a->id, 30, ArticleDiscount::ORIGEN_COMPRA);

        $this->importar(
            [['7799812', 'zz Art import mixto prendida', 1000, '', '50']],
            false,
            ['provider_id' => $this->proveedor_a->id],
            true
        );

        $article = $article->fresh();

        $this->assertSame(
            [5.0, 10.0],
            $this->porcentajes_tagueados($article, $this->proveedor_a->id),
            'Con la preferencia prendida el estandar del proveedor se vuelca como parte de la fila, '.
            'y el 30% que habia queda reemplazado: la fila trae descuentos, el barrido es total.'
        );

        foreach ($this->tagueados($article, $this->proveedor_a->id) as $descuento) {
            $this->assertSame(
                ArticleDiscount::ORIGEN_IMPORT,
                $descuento->origen,
                'Una fila que trae alguna columna de descuentos es CASO B: el origen es `import`, '.
                'no `ficha_proveedor`.'
            );
        }

        $this->assertSame(
            [50.0],
            $this->montos_tagueados($article, $this->proveedor_a->id),
            'El monto de la planilla tiene que estar.'
        );

        /* 1000 x 0,90 x 0,95 = 855; 855 - 50 = 805. */
        $this->assertEqualsWithDelta(
            805,
            (float) $article->costo_real,
            self::DELTA,
            'El costo real sale del estandar del proveedor en cascada mas el monto de la planilla.'
        );
    }

    /**
     * La decision interna del import (origen, barrido, proveedor anterior) NO puede aparecer como
     * lineas de cambio en el "detalle del lote" que ve el comercio.
     *
     * El array de articulos actualizados se serializa ENTERO al pivot `updated_props` del chunk, y
     * esa pantalla imprime una linea por cada clave de primer nivel cuyo valor no sea un objeto.
     * Por eso la decision entera viaja anidada en UNA sola clave, y esa clave lleva el prefijo `__`
     * de los marcadores internos de ProcessRow: `provider_discounts_to_tag_provider_id`, que era un
     * int suelto, imprimia "Provider Discounts To Tag Provider Id: 7" en la pantalla del comercio
     * desde el prompt 307.
     *
     * 🔴 La asercion mira el prefijo `provider_discounts_to_tag` SIN los guiones bajos, a proposito:
     * asi cubre las dos claves viejas y cualquier otra que alguien agregue con ese nombre. Mirando
     * `__provider_discounts_to_tag` —como estaba— el test daba verde con la linea impresa en la
     * pantalla del cliente, que es exactamente lo que venia a impedir.
     *
     * @return void
     */
    public function test_el_detalle_del_lote_no_muestra_la_decision_interna_del_import()
    {
        $this->set_preferencia(1);

        $article = $this->crear_articulo(
            'zz Art import detalle del lote',
            '7799813',
            1000,
            $this->proveedor_a->id
        );

        /* Costo distinto para que la fila entre a la cola de actualizacion y quede registrada en el
           pivot; sin columnas de descuentos, para que sea la rama que agrega la decision interna. */
        $this->importar(
            [['7799813', 'zz Art import detalle del lote', 2000]],
            false
        );

        $props_por_chunk = DB::table('article_actualizados_article_import_result')
                                ->where('article_id', $article->id)
                                ->pluck('updated_props');

        $this->assertGreaterThan(
            0,
            count($props_por_chunk),
            'La importacion tenia que registrar el articulo como actualizado.'
        );

        foreach ($props_por_chunk as $json) {

            $props = json_decode($json, true);

            $this->assertIsArray($props, 'El updated_props del pivot tiene que ser un JSON valido.');

            foreach (array_keys($props) as $clave) {
                $this->assertStringStartsNotWith(
                    'provider_discounts_to_tag',
                    (string) $clave,
                    '🔴 La clave interna "'.$clave.'" llega al detalle del lote y se imprime como '.
                    'una linea de cambio en la pantalla del comercio.'
                );
            }
        }
    }
}
