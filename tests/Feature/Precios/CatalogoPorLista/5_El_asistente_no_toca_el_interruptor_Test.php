<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * El asistente de IA no puede prender (ni apagar) el interruptor "Catálogo restringido en la
 * tienda" de una lista (misión catalogo-por-lista-tienda, 5/10/2026).
 *
 * Prenderlo le saca la tienda entera a todos los compradores de esa lista hasta que se habiliten
 * los artículos uno por uno: es una decisión del comerciante desde el ABM, con el contador a la
 * vista. Como PriceTypeController sí LEE la clave del request (escritura condicional), sin la veda
 * el catálogo de escritura genérica la ofrecería sola como un tilde más de la lista.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group catalogo-por-lista
 */
class El_asistente_no_toca_el_interruptor_Test extends EmpresaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Catalogo::olvidar();
    }

    /**
     * @return void
     */
    public function test_el_interruptor_es_solo_lectura_para_el_asistente()
    {
        // La columna existe (si no, el test no probaría nada).
        $this->assertTrue(Schema::hasColumn('price_types', 'catalogo_restringido_en_tienda'));

        $declaracion = Catalogo::declaracion('price_type');

        $this->assertNotNull($declaracion);
        $this->assertContains('catalogo_restringido_en_tienda', $declaracion['solo_lectura']);
        $this->assertArrayNotHasKey('catalogo_restringido_en_tienda', $declaracion['campos'], 'El asistente no puede ofrecer el interruptor como un campo de la lista.');

        // Y los demás tildes de la lista se siguen ofreciendo como siempre.
        $this->assertArrayHasKey('ocultar_al_publico', $declaracion['campos']);
    }
}
