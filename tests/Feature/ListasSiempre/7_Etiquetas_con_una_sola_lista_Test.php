<?php

namespace Tests\Feature\ListasSiempre;

use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;
use App\Models\ArticleTicketDesign;

/**
 * D6 de la misión prueba-listas-siempre: los diseños de etiquetas de góndola por lista existen desde
 * la SEGUNDA lista. Con una sola (la «Precio final») el dueño tiene la etiqueta genérica de siempre;
 * cuando aparece la segunda, el observer completa el diseño de TODAS las listas que no lo tengan.
 */
class Etiquetas_con_una_sola_lista_Test extends ListasSiempreTestCase
{
    protected function disenos_de($dueno)
    {
        return ArticleTicketDesign::where('user_id', $dueno->id)->orderBy('position')->orderBy('id')->get();
    }

    /** @test */
    public function con_una_sola_lista_el_seeder_deja_el_generico_y_la_segunda_lista_trae_los_de_todas()
    {
        $dueno = $this->dueno_nuevo('etiquetas');
        list($dueno, $precio_final) = $this->con_precio_final($dueno);

        $this->assertCount(0, $this->disenos_de($dueno), 'Crear la primera lista no crea ningún diseño.');

        $this->assertSame(1, ArticleTicketDesignHelper::crear_disenos_del_sistema($dueno->id));

        $disenos = $this->disenos_de($dueno);
        $this->assertCount(1, $disenos);
        $this->assertSame(ArticleTicketDesignHelper::NOMBRE_GENERICO, $disenos[0]->name);
        $this->assertNull($disenos[0]->price_type_id);
        $this->assertSame('precio_final', $disenos[0]->diseno['elementos'][0]['tipo']);

        $this->assertSame(0, ArticleTicketDesignHelper::crear_disenos_del_sistema($dueno->id), 'Idempotente.');

        $mayorista = $this->otra_lista($dueno, 'zz Mayorista etiquetas', 100, 1);

        $disenos = $this->disenos_de($dueno);
        $por_lista = $disenos->whereNotNull('price_type_id')->pluck('price_type_id')->map(function ($id) { return (int) $id; })->sort()->values()->all();

        $this->assertSame(array_values(array_filter(array((int) $precio_final->id, (int) $mayorista->id))), $por_lista, 'Con la segunda lista aparecen los diseños de las dos.');
        $this->assertCount(1, $disenos->whereNull('price_type_id'), 'El genérico sigue.');

        $this->assertSame(0, ArticleTicketDesignHelper::crear_disenos_del_sistema($dueno->id), 'Nada que agregar: cada lista ya tiene el suyo.');
    }
}
