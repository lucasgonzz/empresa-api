<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Models\PdfColumnProfile;
use App\Models\User;
use Database\Seeders\PdfColumnProfileDocumentosSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;

/**
 * Los cuatro diseños de PDF que TODO dueño tiene que tener sembrados (misión
 * pdf-presupuestos-y-pedidos-personalizables, 29/9/2026): "Pedido online" (`order`) y
 * "Presupuesto", "Presupuesto sin precios" y "Presupuesto con imágenes" (`budget`).
 *
 * Los tests que más valen son los de IDEMPOTENCIA. `assign_profile_options()` hace un `sync()` de
 * los pivots que PISA las columnas del diseño, así que un seeder que reaplicara columnas en cada
 * corrida le borraría al cliente lo que armó (ya pasó con el seeder de artículos, ver
 * `informes/20260828-seeder-pdf-y-aviso-imagenes.md`). Este seeder se corre en producción sobre
 * ~40 dueños, y se va a correr más de una vez.
 *
 * @group pdf-documentos
 */
class Disenos_por_defecto_de_presupuesto_y_pedido_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;

    /** Diseños esperados: modelo => nombres. */
    const DISENOS = [
        'order'  => ['Pedido online'],
        'budget' => ['Presupuesto', 'Presupuesto sin precios', 'Presupuesto con imágenes'],
    ];

    /**
     * Columnas visibles esperadas de cada diseño, EN ORDEN: [etiqueta => ancho en mm]. Es la tabla
     * de la sección 4.4 del plan, escrita a mano: si alguien cambia un ancho o el orden, el test lo
     * dice.
     */
    const COLUMNAS = [
        'Pedido online' => [
            '#' => 8, 'Cod. barras' => 30, 'Nombre' => 62, 'Cant' => 15, 'Precio' => 27, 'Notas' => 28, 'Sub total' => 30,
        ],
        'Presupuesto' => [
            '#' => 8, 'Cod. barras' => 30, 'Nombre' => 78, 'Cant' => 15, 'Precio' => 28, 'Bonif' => 14, 'Sub total' => 27,
        ],
        'Presupuesto sin precios' => [
            '#' => 8, 'Cod. barras' => 30, 'Nombre' => 132, 'Cant' => 30,
        ],
        'Presupuesto con imágenes' => [
            'Imagen' => 40, 'Nombre' => 70, 'Precio' => 30, 'Cant' => 15, 'Bonif' => 15, 'Sub total' => 30,
        ],
    ];

    /**
     * Owners de prueba creados por el test.
     *
     * @var \App\Models\User
     */
    protected $otro_dueno;

    /**
     * Empleado del dueño (owner_id != null): no tiene que recibir ningún diseño.
     *
     * @var \App\Models\User
     */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        /** Dueño SIN diseños: los tiene que sembrar el seeder, que es lo que se mide. */
        $this->preparar_dueno_para_pdf(false);
        $this->otro_dueno = $this->crear_dueno('Otro dueno PDF '.uniqid());

        $this->empleado = User::create([
            'name'     => 'Empleado PDF',
            'email'    => 'pdf-empleado-'.uniqid().'@test.local',
            'password' => 'x',
            'owner_id' => $this->dueno->id,
        ]);
    }

    /**
     * @return void
     */
    protected function correr_el_seeder()
    {
        (new PdfColumnProfileDocumentosSeeder())->run();
    }

    /**
     * Diseños de un usuario por modelo.
     *
     * @param int         $user_id
     * @param string|null $model_name
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function disenos_de($user_id, $model_name = null)
    {
        $query = PdfColumnProfile::where('user_id', $user_id);

        if (! is_null($model_name)) {
            $query->where('model_name', $model_name);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Etiqueta => ancho de las columnas VISIBLES de un diseño, en orden.
     *
     * @param \App\Models\PdfColumnProfile $perfil
     * @return array<string, int>
     */
    protected function columnas_visibles($perfil)
    {
        $perfil->load('pdf_column_options');

        $columnas = [];
        foreach ($perfil->pdf_column_options->sortBy('pivot.order') as $opcion) {
            if ($opcion->pivot->visible) {
                $columnas[$opcion->label] = (int) $opcion->pivot->width;
            }
        }

        return $columnas;
    }

    /**
     * @test
     */
    public function el_seeder_crea_los_cuatro_disenos_por_dueno_y_ninguno_por_empleado()
    {
        $this->correr_el_seeder();

        foreach ([$this->dueno, $this->otro_dueno] as $dueno) {
            foreach (self::DISENOS as $model_name => $nombres) {
                $esperados = $nombres;
                sort($esperados);

                $reales = $this->disenos_de($dueno->id, $model_name)->pluck('name')->all();
                sort($reales);

                $this->assertSame(
                    $esperados,
                    $reales,
                    'El dueno '.$dueno->id.' no quedo con los disenos de "'.$model_name.'".'
                );
            }
        }

        $this->assertSame(
            0,
            $this->disenos_de($this->empleado->id)->count(),
            'Un empleado no tiene disenos propios: el seeder itera solo duenos.'
        );
    }

    /**
     * @test
     */
    public function las_columnas_de_cada_diseno_son_las_del_plan_y_suman_200_mm()
    {
        $this->correr_el_seeder();

        foreach (self::DISENOS as $model_name => $nombres) {
            foreach ($nombres as $nombre) {
                $perfil = $this->disenos_de($this->dueno->id, $model_name)->firstWhere('name', $nombre);

                $this->assertNotNull($perfil, 'Falta el diseno "'.$nombre.'".');

                $columnas = $this->columnas_visibles($perfil);

                $this->assertSame(
                    self::COLUMNAS[$nombre],
                    $columnas,
                    'Las columnas visibles de "'.$nombre.'" no son las del plan (o no estan en ese orden).'
                );

                $this->assertSame(
                    200,
                    array_sum($columnas),
                    'Las columnas de "'.$nombre.'" tienen que sumar 200 mm (210 de hoja menos 5 de margen por lado).'
                );

                /** Hoja A4 con los márgenes de siempre y sin nada del remito que no aplique. */
                $this->assertSame(210, (int) $perfil->paper_width_mm);
                $this->assertSame(210, (int) $perfil->printable_width_mm);
                $this->assertSame(5, (int) $perfil->margin_mm);
                $this->assertFalse((bool) $perfil->is_afip_ticket);
                $this->assertFalse((bool) $perfil->show_totals_on_each_page);
            }
        }
    }

    /**
     * @test
     */
    public function hay_exactamente_un_default_por_modelo()
    {
        $this->correr_el_seeder();

        $por_defecto_presupuesto = $this->disenos_de($this->dueno->id, 'budget')->where('is_default', true);
        $por_defecto_pedido = $this->disenos_de($this->dueno->id, 'order')->where('is_default', true);

        $this->assertSame(['Presupuesto'], $por_defecto_presupuesto->pluck('name')->values()->all());
        $this->assertSame(['Pedido online'], $por_defecto_pedido->pluck('name')->values()->all());
    }

    /**
     * El diseño "sin precios" no tiene ninguna columna de plata y NO imprime la caja de totales
     * (ni el Total ni los descuentos): un descuento suelto sin importes no dice nada.
     *
     * @test
     */
    public function el_diseno_sin_precios_no_tiene_columnas_de_precio_ni_total()
    {
        $this->correr_el_seeder();

        $perfil = $this->disenos_de($this->dueno->id, 'budget')->firstWhere('name', 'Presupuesto sin precios');
        $perfil->load('pdf_column_options');

        $resolvers = $perfil->pdf_column_options
            ->filter(function ($opcion) {
                return $opcion->pivot->visible;
            })
            ->pluck('value_resolver')
            ->all();

        foreach (['document_item_price', 'document_item_bonus', 'document_item_subtotal'] as $resolver_de_plata) {
            $this->assertNotContains($resolver_de_plata, $resolvers, 'El diseno sin precios no puede tener la columna '.$resolver_de_plata);
        }

        $this->assertFalse((bool) $perfil->show_total_in_footer, 'El diseno sin precios no imprime el total.');

        /** El control: los otros tres SÍ imprimen el total. */
        foreach (['Presupuesto', 'Presupuesto con imágenes'] as $nombre) {
            $con_precios = $this->disenos_de($this->dueno->id, 'budget')->firstWhere('name', $nombre);
            $this->assertTrue((bool) $con_precios->show_total_in_footer, '"'.$nombre.'" imprime el total.');
        }
    }

    /**
     * @test
     */
    public function el_diseno_con_imagenes_tiene_la_columna_de_imagen_y_los_otros_no()
    {
        $this->correr_el_seeder();

        foreach ($this->disenos_de($this->dueno->id, 'budget') as $perfil) {
            $perfil->load('pdf_column_options');
            $visibles = $perfil->pdf_column_options
                ->filter(function ($opcion) {
                    return $opcion->pivot->visible;
                })
                ->pluck('value_resolver')
                ->all();

            if ($perfil->name === 'Presupuesto con imágenes') {
                $this->assertContains('document_item_image', $visibles);
            } else {
                $this->assertNotContains('document_item_image', $visibles, '"'.$perfil->name.'" no lleva imagen.');
            }
        }
    }

    /**
     * El encabezado del cliente de los presupuestos lleva el empleado que lo cargó (el `BudgetPdf`
     * de antes lo imprimía y sacarlo sería una regresión silenciosa); el del pedido no, porque
     * un pedido de la tienda no lo carga ningún empleado.
     *
     * @test
     */
    public function el_encabezado_del_presupuesto_lleva_el_empleado_y_el_del_pedido_no()
    {
        $this->correr_el_seeder();

        foreach ($this->disenos_de($this->dueno->id, 'budget') as $perfil) {
            $izquierda = $perfil->header_layout['receptor']['izquierda'];
            $this->assertSame('empleado', end($izquierda), '"'.$perfil->name.'" tiene que cerrar el bloque del cliente con el empleado.');
        }

        $pedido = $this->disenos_de($this->dueno->id, 'order')->first();
        $this->assertNotContains('empleado', $pedido->header_layout['receptor']['izquierda']);
    }

    /**
     * 🔴 El test de la idempotencia. Se corre el seeder, se le personaliza un diseño como lo haría
     * un dueño (columna oculta, texto de pie, flag) y se corre de nuevo: no puede aparecer nada
     * nuevo ni borrarse la personalización.
     *
     * @test
     */
    public function una_segunda_corrida_no_agrega_ni_pisa_nada()
    {
        $this->correr_el_seeder();

        $antes = $this->disenos_de($this->dueno->id);
        $ids_antes = $antes->pluck('id')->all();
        $pivots_antes = DB::table('pdf_column_option_profile')->whereIn('pdf_column_profile_id', $ids_antes)->count();

        /** El dueño personaliza "Presupuesto": oculta la bonificación, agrega pie y apaga el subtotal. */
        $perfil = $antes->firstWhere('name', 'Presupuesto');
        $perfil->footer_text = 'Presupuesto valido por 7 dias';
        $perfil->show_subtotal_in_footer = false;
        $perfil->save();

        $bonificacion = $perfil->pdf_column_options()->where('value_resolver', 'document_item_bonus')->first();
        $perfil->pdf_column_options()->updateExistingPivot($bonificacion->id, ['visible' => false, 'width' => 0]);

        $columnas_personalizadas = $this->columnas_visibles($perfil->fresh());

        $this->correr_el_seeder();
        $this->correr_el_seeder();

        $despues = $this->disenos_de($this->dueno->id);

        $this->assertSame($ids_antes, $despues->pluck('id')->all(), 'Correr el seeder de nuevo no puede crear ni borrar disenos.');
        $this->assertSame(
            $pivots_antes,
            DB::table('pdf_column_option_profile')->whereIn('pdf_column_profile_id', $ids_antes)->count(),
            'Correr el seeder de nuevo no puede tocar las columnas.'
        );

        $perfil = $despues->firstWhere('name', 'Presupuesto');

        $this->assertSame('Presupuesto valido por 7 dias', $perfil->footer_text, 'Se piso el texto de pie del dueno.');
        $this->assertFalse((bool) $perfil->show_subtotal_in_footer, 'Se piso un flag del dueno.');
        $this->assertSame(
            $columnas_personalizadas,
            $this->columnas_visibles($perfil),
            'Se pisaron las columnas que el dueno personalizo (assign_profile_options hace sync() y pisa).'
        );
        $this->assertArrayNotHasKey('Bonif', $this->columnas_visibles($perfil), 'La bonificacion oculta por el dueno volvio a aparecer.');
    }

    /**
     * Un dueño que ya armó SU diseño de presupuesto y lo marcó por defecto no puede quedar con dos
     * defaults: `get_profile_for_print()` elegiría uno cualquiera.
     *
     * @test
     */
    public function un_dueno_con_default_propio_no_recibe_un_segundo_default()
    {
        $propio = PdfColumnProfile::create([
            'user_id'                  => $this->otro_dueno->id,
            'model_name'               => 'budget',
            'name'                     => 'Mi presupuesto',
            'columns'                  => [],
            'is_default'               => true,
            'paper_width_mm'           => 210,
            'printable_width_mm'       => 210,
            'margin_mm'                => 5,
            'is_afip_ticket'           => false,
            'show_totals_on_each_page' => false,
        ]);

        $this->correr_el_seeder();

        $presupuestos = $this->disenos_de($this->otro_dueno->id, 'budget');

        $this->assertSame(4, $presupuestos->count(), 'El suyo mas los tres sembrados.');
        $this->assertSame(
            [$propio->id],
            $presupuestos->where('is_default', true)->pluck('id')->values()->all(),
            'El unico default de presupuesto tiene que seguir siendo el del dueno.'
        );
    }

    /**
     * La regresión del "perfil de otro modelo se cuela": sembrar no puede tocar los diseños de
     * venta ni de artículos del dueño.
     *
     * @test
     */
    public function no_toca_los_disenos_de_venta_ni_de_articulos()
    {
        $remito = PdfColumnProfile::create([
            'user_id'                  => $this->dueno->id,
            'model_name'               => 'sale',
            'name'                     => 'Remito propio',
            'columns'                  => [],
            'is_default'               => true,
            'paper_width_mm'           => 210,
            'printable_width_mm'       => 210,
            'margin_mm'                => 5,
            'is_afip_ticket'           => false,
            'show_totals_on_each_page' => false,
        ]);

        $this->correr_el_seeder();

        $remito = $remito->fresh();

        $this->assertTrue((bool) $remito->is_default, 'El default de venta del dueno se piso.');
        $this->assertSame(
            ['Remito propio'],
            $this->disenos_de($this->dueno->id, 'sale')->pluck('name')->all(),
            'El seeder no puede crear ni borrar disenos de venta.'
        );
    }

    /**
     * @test
     */
    public function el_dry_run_no_crea_nada_pero_informa_que_crearia()
    {
        $resultado = PdfDocumentSetupHelper::apply_for_owner($this->dueno->id, true);

        $this->assertSame(0, $this->disenos_de($this->dueno->id)->count());
        $this->assertCount(4, $resultado['created']);
        $this->assertSame([], $resultado['skipped']);
    }

    /**
     * Un empleado no es un dueño: el helper lo rechaza aunque alguien lo llame a mano.
     *
     * @test
     */
    public function un_empleado_no_recibe_disenos_ni_llamando_al_helper_a_mano()
    {
        $resultado = PdfDocumentSetupHelper::apply_for_owner($this->empleado->id);

        $this->assertSame('no_es_owner', $resultado['skipped_reason']);
        $this->assertSame(0, $this->disenos_de($this->empleado->id)->count());
    }
}
