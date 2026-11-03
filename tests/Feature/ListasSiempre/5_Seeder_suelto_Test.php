<?php

namespace Tests\Feature\ListasSiempre;

use Database\Seeders\ListasSiemprePrecioFinalSeeder;
use Illuminate\Support\Facades\DB;

/**
 * D8 de la misión prueba-listas-siempre: el seeder suelto prende el flag SOLO donde prenderlo no
 * cambia ningún precio (lista «Precio final» ya creada, o listas con la extensión vieja de listas), y
 * deja en paz a los demás: sin listas (los migra el comando), con listas sin la extensión (cambiaría
 * el precio de Vender), o con otro modo de listas.
 */
class Seeder_suelto_Test extends ListasSiempreTestCase
{
    protected function flag_de($dueno)
    {
        return (int) DB::table('users')->where('id', $dueno->id)->value('listas_de_precio');
    }

    protected function correr_seeder()
    {
        (new ListasSiemprePrecioFinalSeeder())->run();
    }

    /** @test */
    public function prende_el_flag_a_quien_tiene_precio_final_o_la_extension_vieja_y_a_nadie_mas()
    {
        $con_precio_final = $this->dueno_nuevo('seeder-precio-final');
        $this->con_precio_final($con_precio_final);
        DB::table('users')->where('id', $con_precio_final->id)->update(array('listas_de_precio' => 0));

        $con_extension_vieja = $this->dueno_nuevo('seeder-extension-vieja');
        $this->otra_lista($con_extension_vieja);
        $this->con_extension($con_extension_vieja, 'articulo_margen_de_ganancia_segun_lista_de_precios');

        $sin_listas = $this->dueno_nuevo('seeder-sin-listas');

        $listas_sin_extension = $this->dueno_nuevo('seeder-listas-sin-extension');
        $this->otra_lista($listas_sin_extension);

        $por_categoria = $this->dueno_nuevo('seeder-por-categoria');
        $this->otra_lista($por_categoria);
        $this->con_extension($por_categoria, 'articulo_margen_de_ganancia_segun_lista_de_precios');
        $this->con_extension($por_categoria, 'lista_de_precios_por_categoria');

        $ya_prendido = $this->dueno_nuevo('seeder-ya-prendido', array('listas_de_precio' => 1));
        $this->otra_lista($ya_prendido);

        $this->correr_seeder();

        $this->assertSame(1, $this->flag_de($con_precio_final), 'Con la lista «Precio final» el flag vuelve a 1.');
        $this->assertSame(1, $this->flag_de($con_extension_vieja), 'Listas + la extensión vieja: era un usuario de listas.');
        $this->assertSame(0, $this->flag_de($sin_listas), 'Sin listas no se prende: lo migra el comando.');
        $this->assertSame(0, $this->flag_de($listas_sin_extension), 'Listas sin la extensión vieja: prenderlo cambiaría el precio de Vender.');
        $this->assertSame(0, $this->flag_de($por_categoria), 'Otro modo de listas: no se toca.');
        $this->assertSame(1, $this->flag_de($ya_prendido));

        // Idempotente.
        $this->correr_seeder();

        $this->assertSame(1, $this->flag_de($con_precio_final));
        $this->assertSame(0, $this->flag_de($sin_listas));
        $this->assertSame(0, $this->flag_de($listas_sin_extension));
    }
}
