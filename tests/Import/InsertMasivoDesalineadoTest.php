<?php

namespace Tests\Import;

use App\Models\Article;

/**
 * Regresión del bug: una fila con un valor numérico no interpretable (costo
 * "consultar", número fuera de rango) hacía fallar el INSERT masivo ENTERO de
 * artículos nuevos, no solo esa fila.
 *
 * Causa: ProcessRow::procesar() hace `continue` cuando get_number_for_field()
 * no puede interpretar un valor, y esa clave nunca entra en $data. Para un
 * UPDATE eso es correcto ("no pisar"), pero para un INSERT deja esa tupla con
 * un juego de claves distinto al de sus hermanas. Article::insert() arma la
 * lista de columnas con la PRIMERA tupla y bindea el resto contra ella: una
 * tupla corta produce SQLSTATE 21S01 / 1136 y voltea el lote completo.
 *
 * Fixture usado: 03_numeros_y_costos.xlsx (ya existe, no hace falta uno nuevo).
 * NUM-08 tiene costo "consultar" (no numérico) y NUM-11 tiene un número de 46
 * dígitos que no entra en decimal(50,6): ambos son los casos que hoy voltean
 * el batch.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class InsertMasivoDesalineadoTest extends ImportTestCase
{
    const ARCHIVO = '03_numeros_y_costos.xlsx';

    /**
     * Antes del fix, esta importación devolvía 500 y no se creaba ningún
     * artículo del lote: la tupla corta de NUM-08/NUM-11 volteaba el INSERT
     * entero. Con el fix, la request devuelve 200 y los 12 artículos se crean.
     *
     * @return void
     */
    public function test_una_fila_con_costo_no_numerico_no_voltea_el_lote()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => $this->providers['A']->id]);

        $this->assertCount(12, $this->articulos_creados());
    }

    /**
     * El artículo cuya celda de costo era "consultar" (no interpretable) se
     * crea igual, con costo en null (no en 0, no en basura): rellenar la
     * tupla con null es la respuesta correcta para un artículo nuevo.
     *
     * @return void
     */
    public function test_el_articulo_con_costo_invalido_se_crea_con_costo_null()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => $this->providers['A']->id]);

        $articulo = Article::where('user_id', $this->tenant->id)
                            ->where('provider_code', 'NUM-08')
                            ->first();

        $this->assertNotNull($articulo, 'No se creó el artículo NUM-08');
        $this->assertNull($articulo->cost, 'NUM-08 debería quedar con costo null');
    }

    /**
     * Ídem para el artículo cuyo número no entra en el rango de la columna
     * (46 dígitos, decimal(50,6)): también debe crearse con costo null.
     *
     * @return void
     */
    public function test_el_articulo_con_costo_fuera_de_rango_se_crea_con_costo_null()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => $this->providers['A']->id]);

        $articulo = Article::where('user_id', $this->tenant->id)
                            ->where('provider_code', 'NUM-11')
                            ->first();

        $this->assertNotNull($articulo, 'No se creó el artículo NUM-11');
        $this->assertNull($articulo->cost, 'NUM-11 debería quedar con costo null');
    }

    /**
     * Rellenar con null las tuplas cortas de NUM-08 y NUM-11 no puede haber
     * corrido los valores de las demás tuplas del batch. Esta es la aserción
     * que caza un array_merge invertido ($tupla + $plantilla en vez de
     * $plantilla primero): el conteo de columnas daría igual, pero cada
     * tupla asignaría sus valores a columnas distintas (corrupción
     * silenciosa, no error).
     *
     * @return void
     */
    public function test_los_demas_articulos_del_lote_conservan_su_costo()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => $this->providers['A']->id]);

        $num01 = Article::where('user_id', $this->tenant->id)
                        ->where('provider_code', 'NUM-01')
                        ->first();

        $num07 = Article::where('user_id', $this->tenant->id)
                        ->where('provider_code', 'NUM-07')
                        ->first();

        $this->assertNotNull($num01, 'No se creó el artículo NUM-01');
        $this->assertNotNull($num07, 'No se creó el artículo NUM-07');

        $this->assertDecimal(1234.56, $num01->cost, 'NUM-01 debería conservar su costo 1234.56');
        $this->assertDecimal(8888.25, $num07->cost, 'NUM-07 debería conservar su costo 8888.25');
    }
}
