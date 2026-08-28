<?php

namespace Tests\Import;

use App\Http\Controllers\AiExcelImportController;
use App\Http\Controllers\Helpers\import\article\ArticleImportColumnsNormalizer;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * El checkbox único por importación: "los valores en blanco vacían la propiedad".
 *
 * La importación clásica tenía esto POR COLUMNA (`blank_<key>` -> `blank_flags`). El modal
 * con IA tiene UNO SOLO por importación, `vaciar_valores_en_blanco`, y lo que se prueba acá
 * es el puente entre las dos cosas: prendido equivale a marcar la casilla de todas las
 * columnas mapeadas.
 *
 * Se prueba `AiExcelImportController::resolver_blank_flags()` directamente y no a través de
 * una importación completa a propósito: lo que puede salir mal es la DECISIÓN (qué gana
 * cuando vienen los dos, qué pasa con una columna sin mapear, qué pasa con el string
 * "false"), y eso se mide acá en milisegundos. Que ProcessRow después respete `blank_flags`
 * ya lo prueba la suite del prompt 310.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class ValoresEnBlancoTest extends TestCase
{
    /**
     * Mapeo de columnas típico del modal con IA: propiedad => índice de columna.
     *
     * @var array
     */
    const COLUMNS = [
        'codigo_de_barras' => 0,
        'nombre'           => 1,
        'costo'            => 2,
        'stock_actual'     => 3,
    ];

    /**
     * Corre el resolvedor con el request armado a mano.
     *
     * @param  array $body     Cuerpo del request
     * @param  array $columns  Mapeo de columnas
     * @return array           blank_flags resuelto
     */
    protected function resolver(array $body, array $columns = null)
    {
        if (is_null($columns)) {
            $columns = self::COLUMNS;
        }

        $controller = new AiExcelImportController();

        $metodo = new \ReflectionMethod(AiExcelImportController::class, 'resolver_blank_flags');
        $metodo->setAccessible(true);

        $request = Request::create('/api/ai-excel-import/import', 'POST', $body);

        return $metodo->invoke($controller, $request, $columns);
    }

    /**
     * Sin el campo, todo queda como antes de esta misión: una celda vacía no toca el valor
     * que ya estaba. Es el caso de AdminSync, que no manda nada de esto.
     *
     * @return void
     */
    public function test_sin_el_campo_no_se_vacia_nada()
    {
        $this->assertSame([], $this->resolver([]));
    }

    /**
     * @return void
     */
    public function test_con_el_campo_en_false_no_se_vacia_nada()
    {
        $this->assertSame([], $this->resolver(['vaciar_valores_en_blanco' => false]));
    }

    /**
     * 🔴 El string "false" tiene que leerse como false.
     *
     * `(bool) 'false'` en PHP da TRUE: con un cast crudo, un cliente que mande el booleano
     * como texto —cosa que pasa sola en cuanto el campo viaja por multipart en vez de JSON—
     * vaciaría todas las propiedades de todos los artículos sin haberlo pedido. Es
     * exactamente el mismo motivo por el que `precios_incluyen_iva` usa boolean().
     *
     * @return void
     */
    public function test_el_string_false_no_prende_el_vaciado()
    {
        $this->assertSame([], $this->resolver(['vaciar_valores_en_blanco' => 'false']));
        $this->assertSame([], $this->resolver(['vaciar_valores_en_blanco' => '0']));
    }

    /**
     * Prendido, marca TODAS las columnas mapeadas.
     *
     * @return void
     */
    public function test_prendido_marca_todas_las_columnas_mapeadas()
    {
        $flags = $this->resolver(['vaciar_valores_en_blanco' => true]);

        $this->assertSame(array_keys(self::COLUMNS), array_keys($flags));

        foreach ($flags as $propiedad => $valor) {
            $this->assertTrue($valor, 'La columna ' . $propiedad . ' quedó sin marcar.');
        }
    }

    /**
     * El string "true" y el "1" del multipart también prenden.
     *
     * @return void
     */
    public function test_el_string_true_y_el_uno_prenden_el_vaciado()
    {
        $this->assertCount(4, $this->resolver(['vaciar_valores_en_blanco' => 'true']));
        $this->assertCount(4, $this->resolver(['vaciar_valores_en_blanco' => '1']));
    }

    /**
     * Una propiedad sin posición de columna no se lee del Excel, así que no puede vaciar
     * nada. Marcarla dejaría un flag prendido esperando a que alguien mapee esa columna en
     * otra importación.
     *
     * @return void
     */
    public function test_una_columna_sin_mapear_no_se_marca()
    {
        $columns = [
            'nombre' => 1,
            'costo'  => null,
            'precio' => '',
        ];

        $flags = $this->resolver(['vaciar_valores_en_blanco' => true], $columns);

        $this->assertSame(['nombre'], array_keys($flags));
    }

    /**
     * 🔴 Un `blank_flags` explícito GANA sobre el checkbox.
     *
     * Es lo que mantiene andando a cualquier cliente que ya mandaba los flags por columna
     * (la SPA vieja sin desplegar). El checkbox nuevo es un atajo, no un reemplazo: si los
     * dos vienen, el que eligió columna por columna es más específico y no se pisa.
     *
     * @return void
     */
    public function test_un_blank_flags_explicito_le_gana_al_checkbox()
    {
        $explicito = ['nombre' => true, 'costo' => false];

        $flags = $this->resolver([
            'vaciar_valores_en_blanco' => true,
            'blank_flags'              => $explicito,
        ]);

        $this->assertSame($explicito, $flags);
    }

    /**
     * Y sin el checkbox, el explícito pasa igual: este método es el único punto por donde
     * `blank_flags` llega al importador, así que si acá se perdiera, el cliente viejo
     * dejaría de vaciar valores sin que nada avise.
     *
     * @return void
     */
    public function test_el_blank_flags_explicito_pasa_aunque_no_venga_el_checkbox()
    {
        $explicito = ['nombre' => true];

        $this->assertSame($explicito, $this->resolver(['blank_flags' => $explicito]));
    }

    /**
     * Las claves del mapa de flags tienen que terminar alineadas con las del mapa de
     * columnas DESPUÉS de normalizar.
     *
     * Importa por los alias: la IA manda "codigo_proveedor" y el importador lee
     * "codigo_de_proveedor". Si las dos normalizaciones no coincidieran,
     * ProcessRow::permite_valores_en_blanco() buscaría el flag con una clave que no existe
     * y el checkbox no haría absolutamente nada — en silencio, que es la peor forma.
     *
     * @return void
     */
    public function test_las_claves_de_los_flags_quedan_alineadas_con_las_de_las_columnas()
    {
        $columns = [
            'codigo_proveedor' => 0,
            'codigo_barras'    => 1,
            'nombre'           => 2,
        ];

        $flags = $this->resolver(['vaciar_valores_en_blanco' => true], $columns);

        $columnas_normalizadas = ArticleImportColumnsNormalizer::normalize($columns);
        $flags_normalizados    = ArticleImportColumnsNormalizer::normalize_blank_flags($flags);

        $this->assertSame(
            array_keys($columnas_normalizadas),
            array_keys($flags_normalizados),
            'El flag quedó con una clave distinta de la de su columna: el checkbox no vaciaría nada.'
        );
    }
}
