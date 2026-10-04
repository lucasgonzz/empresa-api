<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Models\Article;
use App\Models\ImportConflict;
use App\Models\ImportHistory;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Notification;

/**
 * El mensaje del resultado de la importación dice solo lo que siempre es verdad de cada fila.
 *
 * Misión importacion-mensaje-de-problemas (4/10/2026). Importando la lista de la demo con
 * problemas, el aviso decía "La importacion termino con 3 filas que no se pudieron procesar por
 * codigos duplicados o incompletos en el Excel" y las tres filas se habían importado. De todos los
 * tipos de import_conflicts, el ÚNICO que seguro deja la fila afuera es 'ambiguo'
 * (ProcessRow::procesar() corta antes de crear o actualizar). Con el resto el dato se descarta y
 * la fila sigue, pero que después se cree o se actualice algo depende del resto de la importación
 * ("Solo actualizar" sin match, otro proveedor, repetida por nombre o id) y no queda registrado:
 * por eso el mensaje dice que esas filas "tienen datos para revisar", nunca que "se importaron".
 * Cuenta FILAS y separa las dos situaciones (ArticleImportHelper::contar_filas_con_problemas() y
 * mensaje_de_resultado()); conflicts_count no cambia de significado: son problemas para revisar.
 *
 * Archivo 31_problemas_de_la_lista_de_la_demo.xlsx (ver generar_problemas_de_la_demo.php):
 *   F2  PD-01, código de barras nuevo, costo 1000      -> se crea limpio
 *   F3  PD-03, costo y precio "consultar"              -> 2 numero_invalido, se crea sin costo ni precio
 *   F4  sin ningún código                              -> sin_identificador, se crea sin códigos
 *   F5  código de barras "S/N", PD-05                  -> placeholder_descartado, se crea con PD-05
 *   F6  repite PD-01 y su código de barras             -> fila_sobrescrita F2 -> F6 (no cuenta)
 *   F7  código de barras 7790007 (A7 y A8), "consultar" -> ambiguo + numero_invalido, NO se importa
 *
 * (Lo de "se crea" vale con "Crear y actualizar". Con "Solo actualizar" ninguna de esas filas
 * crea nada y el mensaje tiene que ser el mismo: ver
 * test_solo_actualizar_no_afirma_que_las_filas_con_datos_para_revisar_se_importaron().)
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class MensajeDeResultadoTest extends ImportTestCase
{
    const ARCHIVO = '31_problemas_de_la_lista_de_la_demo.xlsx';

    const BASE = 'Importación de Excel finalizada correctamente';

    /** El mensaje del archivo entero: 1 fila no importada (F7) y 3 con datos para revisar (F3, F4, F5). */
    const MENSAJE_DEL_ARCHIVO_ENTERO = 'Importación de Excel finalizada correctamente. 1 fila no se importó '
        . 'porque no se pudo saber a qué artículo corresponde. 3 filas tienen datos para revisar. Revisá el '
        . 'detalle en Historial de importaciones.';

    /**
     * Importa el fixture con la notificación falseada y devuelve el historial y la ÚNICA
     * GlobalNotification de resultado (`notification_modal = 'article_import_result'`) que
     * recibió el dueño.
     *
     * @param  array $config  overrides de la importación (start_row, finish_row, ...)
     * @return array  [ImportHistory, GlobalNotification]
     */
    protected function importar_y_capturar_el_aviso(array $config = [])
    {
        Notification::fake();

        $import = $this->importar(self::ARCHIVO, array_merge([
            'provider_id' => $this->providers['A']->id,
        ], $config));

        $capturado = null;

        Notification::assertSentTo(
            $this->tenant,
            GlobalNotification::class,
            function ($notification) use (&$capturado) {
                if ($notification->notification_modal !== 'article_import_result') {
                    return false;
                }

                $capturado = $notification;

                return true;
            }
        );

        $avisos_de_resultado = Notification::sent(
            $this->tenant,
            GlobalNotification::class,
            function ($notification) {
                return $notification->notification_modal === 'article_import_result';
            }
        );

        $this->assertCount(1, $avisos_de_resultado, 'La importación tiene que mandar UN solo aviso de resultado.');

        return [$import->fresh(), $capturado];
    }

    /**
     * El archivo entero, con "Crear y actualizar": una fila no se importó (F7) y tres tienen
     * datos para revisar (F3, F4, F5).
     *
     * @return void
     */
    public function test_el_archivo_entero_separa_la_fila_no_importada_de_las_que_tienen_datos_para_revisar()
    {
        list($import, $aviso) = $this->importar_y_capturar_el_aviso();

        /* El fixture deja lo que dice que deja. */
        $this->assertSame([3, 3, 7], $this->filas_de_conflictos($import, 'numero_invalido'));
        $this->assertSame([4], $this->filas_de_conflictos($import, 'sin_identificador'));
        $this->assertSame([5], $this->filas_de_conflictos($import, 'placeholder_descartado'));
        $this->assertSame([7], $this->filas_de_conflictos($import, 'ambiguo'));
        $this->assertSame([2 => 6], $this->sobrescrituras($import));

        /* conflicts_count no cambia de significado: problemas para revisar, sin los informativos. */
        $this->assertSame(6, (int) $import->conflicts_count);

        $this->assertSame(
            ['no_importadas' => 1, 'para_revisar' => 3],
            ArticleImportHelper::contar_filas_con_problemas($import->id)
        );

        $this->assertSame(self::MENSAJE_DEL_ARCHIVO_ENTERO, $aviso->message_text);

        $this->assertStringNotContainsString('no se pudieron procesar', $aviso->message_text);

        /* import_stats no cambia de forma: ni claves nuevas ni claves de menos. */
        $this->assertSame(
            [
                'filas_procesadas',
                'articulos_creados',
                'articulos_macheados',
                'articulos_actualizados',
                'articulos_creados_con_codigo_repetido',
                'import_history_id',
                'conflicts_count',
            ],
            array_keys($aviso->import_stats)
        );
        $this->assertSame(6, $aviso->import_stats['conflicts_count']);
        $this->assertSame((int) $import->id, $aviso->import_stats['import_history_id']);

        /*
         * Con "Crear y actualizar", F3, F4 y F5 crearon su artículo sin el dato que no servía.
         * El mensaje igual no lo afirma: con "Solo actualizar" las mismas filas no crean nada
         * (ver el test de abajo) y el aviso no puede saber cuál de las dos cosas pasó.
         */
        $f3 = Article::where('user_id', $this->tenant->id)->where('provider_code', 'PD-03')->get();
        $this->assertCount(1, $f3, 'F3 (PD-03) se tiene que haber importado.');
        $this->assertSame('PROBLEMAS DEMO NIVEL SIN COSTO', $f3->first()->name);
        $this->assertNull($f3->first()->cost, 'F3 se importa sin el costo "consultar".');

        $f4 = Article::where('user_id', $this->tenant->id)->where('name', 'PROBLEMAS DEMO MECHA SIN CODIGOS')->get();
        $this->assertCount(1, $f4, 'F4 (sin códigos) se tiene que haber importado.');
        $this->assertEmpty($f4->first()->bar_code);
        $this->assertEmpty($f4->first()->provider_code);
        $this->assertDecimal(1500, $f4->first()->cost);

        $f5 = Article::where('user_id', $this->tenant->id)->where('provider_code', 'PD-05')->get();
        $this->assertCount(1, $f5, 'F5 (PD-05) se tiene que haber importado.');
        $this->assertEmpty($f5->first()->bar_code, 'F5 se importa sin el código de barras "S/N".');
        $this->assertDecimal(2000, $f5->first()->cost);

        /* "No se importó" también es verdad: F7 no tocó A7 ni A8 ni creó nada. */
        $a7 = $this->recargar('A7');
        $a8 = $this->recargar('A8');
        $this->assertSame('Art bar code repetido 1', $a7->name);
        $this->assertSame('Art bar code repetido 2', $a8->name);
        $this->assertDecimal(700, $a7->cost);
        $this->assertDecimal(800, $a8->cost);
        $this->assertSame(
            0,
            Article::where('user_id', $this->tenant->id)->where('name', 'PROBLEMAS DEMO CODIGO REPETIDO EN BASE')->count(),
            'F7 no tiene que crear ningún artículo.'
        );
    }

    /**
     * El mismo archivo con "Solo actualizar" sobre una base que no tiene esos artículos: las
     * filas 2 a 6 no matchean y NO crean nada (sin_match_no_creado no deja conflicto), y la F7
     * sigue siendo ambigua. El mensaje tiene que ser el mismo que con "Crear y actualizar":
     * hasta la segunda vuelta de la misión decía "3 filas se importaron con datos para revisar"
     * con 0 artículos creados y 0 actualizados, y era falso.
     *
     * @return void
     */
    public function test_solo_actualizar_no_afirma_que_las_filas_con_datos_para_revisar_se_importaron()
    {
        list($import, $aviso) = $this->importar_y_capturar_el_aviso([
            'create_and_edit' => false,
        ]);

        /* Los mismos problemas que con "Crear y actualizar". */
        $this->assertSame([3, 3, 7], $this->filas_de_conflictos($import, 'numero_invalido'));
        $this->assertSame([4], $this->filas_de_conflictos($import, 'sin_identificador'));
        $this->assertSame([5], $this->filas_de_conflictos($import, 'placeholder_descartado'));
        $this->assertSame([7], $this->filas_de_conflictos($import, 'ambiguo'));
        $this->assertSame(6, (int) $import->conflicts_count);

        $this->assertSame(
            ['no_importadas' => 1, 'para_revisar' => 3],
            ArticleImportHelper::contar_filas_con_problemas($import->id)
        );

        /* Y de verdad no se importó ninguna: ni creados ni actualizados. */
        $this->assertCount(0, $this->articulos_creados(), 'Con "Solo actualizar" ninguna fila del archivo puede crear un artículo.');
        $this->assertSame(0, (int) $import->created_models);
        $this->assertSame(0, (int) $import->updated_models);

        $a7 = $this->recargar('A7');
        $a8 = $this->recargar('A8');
        $this->assertSame('Art bar code repetido 1', $a7->name);
        $this->assertSame('Art bar code repetido 2', $a8->name);
        $this->assertDecimal(700, $a7->cost);
        $this->assertDecimal(800, $a8->cost);

        /*
         * El mensaje no afirma que se importaron. Un assertStringNotContainsString('se importó')
         * a secas no sirve: la frase de la F7 ("1 fila no se importó...") lo contiene y es
         * verdad. Lo que no puede aparecer es un "se import..." que NO venga después de "no ".
         */
        $this->assertSame(self::MENSAJE_DEL_ARCHIVO_ENTERO, $aviso->message_text);
        $this->assertStringNotContainsString('se importaron', $aviso->message_text);
        $this->assertDoesNotMatchRegularExpression('/(?<!no )se import/u', $aviso->message_text);
    }

    /**
     * Solo la F5: una sola fila con un dato para revisar, en singular y sin ningún "se
     * importó" (ni afirmativo ni negativo).
     *
     * @return void
     */
    public function test_una_sola_fila_para_revisar_va_en_singular()
    {
        list($import, $aviso) = $this->importar_y_capturar_el_aviso([
            'start_row'  => 5,
            'finish_row' => 5,
        ]);

        $this->assertSame(1, (int) $import->conflicts_count);

        $this->assertSame(
            ['no_importadas' => 0, 'para_revisar' => 1],
            ArticleImportHelper::contar_filas_con_problemas($import->id)
        );

        $this->assertSame(
            'Importación de Excel finalizada correctamente. 1 fila tiene datos para revisar. '
            . 'Revisá el detalle en Historial de importaciones.',
            $aviso->message_text
        );

        $this->assertStringNotContainsString('se import', $aviso->message_text);
    }

    /**
     * Solo la F2, sin ningún problema: el mensaje queda en la base, sin punto final.
     *
     * @return void
     */
    public function test_sin_problemas_el_mensaje_queda_en_la_base()
    {
        list($import, $aviso) = $this->importar_y_capturar_el_aviso([
            'start_row'  => 2,
            'finish_row' => 2,
        ]);

        $this->assertSame(0, (int) $import->conflicts_count);

        $this->assertSame(
            ['no_importadas' => 0, 'para_revisar' => 0],
            ArticleImportHelper::contar_filas_con_problemas($import->id)
        );

        $this->assertSame(self::BASE, $aviso->message_text);
    }

    /**
     * El conteo por fila sobre conflictos armados a mano: una fila ambigua con otro problema
     * cuenta una sola vez y como NO importada, los informativos no cuentan, un tipo desconocido
     * (una versión más nueva) cuenta como para revisar, y la fila null no se cuenta.
     *
     * @return void
     */
    public function test_contar_filas_con_problemas_cuenta_filas_y_no_problemas()
    {
        $historial = ImportHistory::create([
            'user_id'        => $this->tenant->id,
            'employee_id'    => $this->tenant->id,
            'model_name'     => 'article',
            'articles_match' => 0,
            'created_models' => 0,
            'updated_models' => 0,
        ]);

        $conflictos = [
            /* Fila 10: ambigua con un número inválido antes del match -> 1 no importada. */
            [10, 'ambiguo'],
            [10, 'numero_invalido'],
            /* Fila 11: dos números inválidos -> 1 para revisar. */
            [11, 'numero_invalido'],
            [11, 'numero_invalido'],
            /* Fila 12: código descartado y sobrescrita -> 1 para revisar (la sobrescritura no suma). */
            [12, 'placeholder_descartado'],
            [12, 'fila_sobrescrita'],
            /* Fila 13: solo informativos -> no cuenta. */
            [13, 'fila_sobrescrita'],
            [13, 'columna_de_precio_ignorada'],
            /* Fila 14: un tipo que esta versión no conoce -> para revisar, como en conflicts_count. */
            [14, 'tipo_de_una_version_mas_nueva'],
            /* Fila 15: otra ambigua, sola -> 1 no importada. */
            [15, 'ambiguo'],
            /* Fila null: no se cuenta. */
            [null, 'numero_invalido'],
            [null, 'ambiguo'],
        ];

        foreach ($conflictos as $conflicto) {
            ImportConflict::create([
                'import_history_id' => $historial->id,
                'fila'              => $conflicto[0],
                'tipo'              => $conflicto[1],
            ]);
        }

        $this->assertSame(
            ['no_importadas' => 2, 'para_revisar' => 3],
            ArticleImportHelper::contar_filas_con_problemas($historial->id)
        );

        /* Un historial sin conflictos da cero, no null. */
        $this->assertSame(
            ['no_importadas' => 0, 'para_revisar' => 0],
            ArticleImportHelper::contar_filas_con_problemas($historial->id + 1000000)
        );
    }

    /**
     * mensaje_de_resultado(): el singular de "no se importó".
     *
     * @return void
     */
    public function test_mensaje_una_fila_no_importada_va_en_singular()
    {
        $this->assertSame(
            'Importación de Excel finalizada correctamente. 1 fila no se importó porque no se pudo saber '
            . 'a qué artículo corresponde. Revisá el detalle en Historial de importaciones.',
            ArticleImportHelper::mensaje_de_resultado(1, 1, 0)
        );
    }

    /**
     * mensaje_de_resultado(): el plural de las dos situaciones, con los miles a la argentina.
     *
     * @return void
     */
    public function test_mensaje_plural_de_las_dos_situaciones_con_miles()
    {
        $this->assertSame(
            'Importación de Excel finalizada correctamente. 1.234 filas no se importaron porque no se pudo '
            . 'saber a qué artículo corresponden. 2 filas tienen datos para revisar. Revisá el detalle en '
            . 'Historial de importaciones.',
            ArticleImportHelper::mensaje_de_resultado(1500, 1234, 2)
        );
    }

    /**
     * mensaje_de_resultado(): la red de seguridad. Si el conteo por fila no encontró nada pero
     * conflicts_count dice que hubo problemas (conflictos sin fila, o el conteo falló), el
     * mensaje no se calla: cuenta problemas, no filas.
     *
     * @return void
     */
    public function test_mensaje_red_de_seguridad_cuenta_problemas()
    {
        $this->assertSame(
            'Importación de Excel finalizada correctamente. Quedó 1 problema para revisar. Revisá el '
            . 'detalle en Historial de importaciones.',
            ArticleImportHelper::mensaje_de_resultado(1, 0, 0)
        );

        $this->assertSame(
            'Importación de Excel finalizada correctamente. Quedaron 2.500 problemas para revisar. Revisá '
            . 'el detalle en Historial de importaciones.',
            ArticleImportHelper::mensaje_de_resultado(2500, 0, 0)
        );

        $this->assertSame(self::BASE, ArticleImportHelper::mensaje_de_resultado(0, 0, 0));
    }
}
