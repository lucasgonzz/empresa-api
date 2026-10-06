<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use PHPUnit\Framework\TestCase;

/**
 * La clave de la actualización masiva "visible en la tienda, lista X" (misión
 * catalogo-por-lista-tienda, 5/10/2026): `visible_en_tienda_lista_{price_type_id}`.
 *
 * Es el contrato con empresa-spa (`opciones-filtrados-seleccion/Update.vue` arma una tarjeta por cada
 * lista restringida con esta clave) y no se cambia sin cambiar los dos lados, por eso los tests
 * escriben el texto a mano y no con la constante: si alguien toca el prefijo, tienen que ponerse
 * rojos. La regex se arma desde PREFIJO_CLAVE_DE_MASIVA (B5 de la revisión independiente); lo que
 * se fija acá es que sigue aceptando exactamente lo mismo que antes.
 *
 * Parseo puro, sin base y sin Laravel levantado.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group catalogo-por-lista
 */
class La_clave_de_la_masiva_Test extends TestCase
{
    /**
     * Claves válidas: [clave, id de la lista].
     *
     * @return array
     */
    public function claves_validas()
    {
        return [
            'una lista'          => ['visible_en_tienda_lista_5', 5],
            'id de dos cifras'   => ['visible_en_tienda_lista_12', 12],
            'id grande'          => ['visible_en_tienda_lista_987654', 987654],
            'id con cero adelante' => ['visible_en_tienda_lista_007', 7],
        ];
    }

    /**
     * @dataProvider claves_validas
     */
    public function test_reconoce_la_clave_y_saca_el_id_de_la_lista($clave, $id)
    {
        $this->assertTrue(CatalogoPorListaHelper::es_clave_de_masiva($clave));
        $this->assertSame($id, CatalogoPorListaHelper::lista_de_la_clave_de_masiva($clave));
    }

    /**
     * Lo que NO es la clave: [valor].
     *
     * @return array
     */
    public function claves_invalidas()
    {
        return [
            'sin el id'              => ['visible_en_tienda_lista_'],
            'id con letras'          => ['visible_en_tienda_lista_12x'],
            'id negativo'            => ['visible_en_tienda_lista_-1'],
            'id decimal'             => ['visible_en_tienda_lista_1.5'],
            'con algo adelante'      => ['xvisible_en_tienda_lista_1'],
            'con algo atrás'         => ['visible_en_tienda_lista_1_'],
            'la columna de la ficha' => ['visible_en_tienda'],
            'la propiedad de la IA'  => ['price_type_5_visible_en_tienda'],
            'otra clave de la masiva' => ['online'],
            'vacía'                  => [''],
            'null'                   => [null],
            'un número'              => [5],
            'un arreglo'             => [['visible_en_tienda_lista_1']],
        ];
    }

    /**
     * @dataProvider claves_invalidas
     */
    public function test_no_reconoce_lo_que_no_es_la_clave($valor)
    {
        $this->assertFalse(CatalogoPorListaHelper::es_clave_de_masiva($valor));
        $this->assertNull(CatalogoPorListaHelper::lista_de_la_clave_de_masiva($valor));
    }
}
