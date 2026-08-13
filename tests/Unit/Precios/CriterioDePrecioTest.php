<?php

namespace Tests\Unit\Precios;

use App\Http\Controllers\Helpers\CriterioDePrecioHelper;
use Tests\TestCase;

/**
 * Unitarias de CriterioDePrecioHelper (mision 44): la unica cuenta aislada de la mision.
 *
 * Decide si un articulo se maneja por margen propio, por margen del proveedor o por precio
 * manual. Esa decision estaba escrita cuatro veces en el front y una en el back, con tres
 * criterios distintos, y las diferencias dejaban articulos con los DOS inputs de la ficha
 * bloqueados y sin ninguna forma de cambiarles el precio.
 *
 * Los dos casos que originaron la mision, y que estan cubiertos abajo:
 *
 *   1. percentage_gain = 0 con price cargado. El back no limpiaba el precio (0 no es > 0) y
 *      el front bloqueaba los dos campos ("0.00" != '' es verdadero en JS).
 *   2. Proveedor con margen y apply_provider_percentage_gain, pero el articulo SIN costo. El
 *      front bloqueaba el precio manual y el margen del proveedor no podia producir ningun
 *      precio, porque no hay costo al que aplicarle el porcentaje.
 *
 * No toca la base: son valores sueltos.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class CriterioDePrecioTest extends TestCase
{
    /**
     * La tabla de las cuatro situaciones de la mision, mas los bordes que la motivaron.
     *
     * @dataProvider casos_del_criterio
     *
     * @param  mixed  $percentage_gain
     * @param  mixed  $price
     * @param  mixed  $cost
     * @param  mixed  $apply_provider
     * @param  mixed  $provider_percentage_gain
     * @param  string $esperado
     * @param  string $descripcion
     * @return void
     */
    public function test_modo_resuelto($percentage_gain, $price, $cost, $apply_provider, $provider_percentage_gain, $esperado, $descripcion)
    {
        $this->assertSame(
            $esperado,
            CriterioDePrecioHelper::resolver($percentage_gain, $price, $cost, $apply_provider, $provider_percentage_gain),
            $descripcion
        );
    }

    /**
     * @return array
     */
    public function casos_del_criterio()
    {
        return [
            /* percentage_gain, price, cost, apply_provider, provider_gain, esperado, descripcion */

            'margen propio' => [
                25, null, 100, 0, null,
                CriterioDePrecioHelper::MARGEN_PROPIO,
                'Margen propio > 0 manda',
            ],

            'margen propio gana al precio manual' => [
                25, 500, 100, 0, null,
                CriterioDePrecioHelper::MARGEN_PROPIO,
                'Con los dos cargados gana el margen, igual que setFinalPrice()',
            ],

            'precio manual' => [
                null, 500, 100, 0, null,
                CriterioDePrecioHelper::PRECIO_MANUAL,
                'Precio manual > 0 sin ningun margen',
            ],

            'margen en cero NO es margen' => [
                0, 500, 100, 0, null,
                CriterioDePrecioHelper::PRECIO_MANUAL,
                'EL CASO DE LA MISION: margen 0 con precio cargado se maneja por precio manual',
            ],

            'margen en cero como texto tampoco' => [
                '0.00', 500, 100, 0, null,
                CriterioDePrecioHelper::PRECIO_MANUAL,
                '"0.00" es como viene de MySQL: tampoco cuenta como margen',
            ],

            'precio en cero NO es precio manual' => [
                null, 0, 100, 0, null,
                CriterioDePrecioHelper::NINGUNO,
                'Un precio en 0 no bloquea el margen',
            ],

            'precio en cero como texto tampoco' => [
                null, '0.00', 100, 0, null,
                CriterioDePrecioHelper::NINGUNO,
                '"0.00" tampoco cuenta como precio manual',
            ],

            'margen del proveedor con costo' => [
                null, null, 100, 1, 15,
                CriterioDePrecioHelper::MARGEN_DEL_PROVEEDOR,
                'Proveedor con margen, flag prendido y costo cargado',
            ],

            'margen del proveedor sin costo NO aplica' => [
                null, 500, null, 1, 15,
                CriterioDePrecioHelper::PRECIO_MANUAL,
                'EL OTRO CASO DE LA MISION: sin costo el margen del proveedor no puede producir ningun precio',
            ],

            'margen del proveedor sin costo y sin precio' => [
                null, null, null, 1, 15,
                CriterioDePrecioHelper::NINGUNO,
                'Sin costo, sin margen propio y sin precio no hay criterio',
            ],

            'margen del proveedor con el flag apagado' => [
                null, 500, 100, 0, 15,
                CriterioDePrecioHelper::PRECIO_MANUAL,
                'Sin apply_provider_percentage_gain el margen del proveedor no se usa',
            ],

            'margen del proveedor con el proveedor sin margen' => [
                null, 500, 100, 1, null,
                CriterioDePrecioHelper::PRECIO_MANUAL,
                'Si el proveedor no tiene margen cargado, no hay margen del proveedor',
            ],

            'margen del proveedor en cero SI aplica' => [
                null, null, 100, 1, 0,
                CriterioDePrecioHelper::MARGEN_DEL_PROVEEDOR,
                'El margen del proveedor se mide por "cargado", igual que en setFinalPrice()',
            ],

            'margen propio gana al del proveedor' => [
                25, null, 100, 1, 15,
                CriterioDePrecioHelper::MARGEN_PROPIO,
                'La prioridad es margen propio > margen del proveedor',
            ],

            'ninguno de los dos' => [
                null, null, 100, 0, null,
                CriterioDePrecioHelper::NINGUNO,
                'Articulo que solo tiene costo',
            ],

            'cadenas vacias no son valores' => [
                '', '', '', 0, null,
                CriterioDePrecioHelper::NINGUNO,
                'Una cadena vacia no carga ningun criterio',
            ],
        ];
    }

    /**
     * es_positivo() es la puerta de entrada de todo el criterio: si acepta un cero, vuelve
     * el bug entero.
     *
     * @return void
     */
    public function test_es_positivo()
    {
        $this->assertTrue(CriterioDePrecioHelper::es_positivo(1));
        $this->assertTrue(CriterioDePrecioHelper::es_positivo('25.50'));
        $this->assertTrue(CriterioDePrecioHelper::es_positivo(0.01));

        $this->assertFalse(CriterioDePrecioHelper::es_positivo(0));
        $this->assertFalse(CriterioDePrecioHelper::es_positivo('0'));
        $this->assertFalse(CriterioDePrecioHelper::es_positivo('0.00'));
        $this->assertFalse(CriterioDePrecioHelper::es_positivo(null));
        $this->assertFalse(CriterioDePrecioHelper::es_positivo(''));
        $this->assertFalse(CriterioDePrecioHelper::es_positivo('   '));
        $this->assertFalse(CriterioDePrecioHelper::es_positivo('consultar'));
        $this->assertFalse(CriterioDePrecioHelper::es_positivo(-5));
    }

    /**
     * normalizar() es lo que guardan store() y update() de ArticleController.
     *
     * @return void
     */
    public function test_normalizar()
    {
        $this->assertNull(CriterioDePrecioHelper::normalizar(null));
        $this->assertNull(CriterioDePrecioHelper::normalizar(''));
        $this->assertNull(CriterioDePrecioHelper::normalizar('   '));
        $this->assertNull(CriterioDePrecioHelper::normalizar(0));
        $this->assertNull(CriterioDePrecioHelper::normalizar('0'));
        $this->assertNull(CriterioDePrecioHelper::normalizar('0.00'));

        $this->assertSame(25, CriterioDePrecioHelper::normalizar(25));
        $this->assertSame('25.50', CriterioDePrecioHelper::normalizar('25.50'));

        /*
         * Un negativo NO se anula: no es el estado que traba la ficha y descartarlo seria
         * tirar en silencio un dato que el usuario cargo.
         */
        $this->assertSame(-5, CriterioDePrecioHelper::normalizar(-5));
    }

    /**
     * es_margen() es lo que usa la importacion para decidir si saltea el precio del Excel.
     *
     * @return void
     */
    public function test_es_margen()
    {
        $this->assertTrue(CriterioDePrecioHelper::es_margen(CriterioDePrecioHelper::MARGEN_PROPIO));
        $this->assertTrue(CriterioDePrecioHelper::es_margen(CriterioDePrecioHelper::MARGEN_DEL_PROVEEDOR));

        $this->assertFalse(CriterioDePrecioHelper::es_margen(CriterioDePrecioHelper::PRECIO_MANUAL));
        $this->assertFalse(CriterioDePrecioHelper::es_margen(CriterioDePrecioHelper::NINGUNO));
    }
}
