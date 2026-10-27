<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Misión mercado-libre-integracion (27/10/2026): asegura los catálogos chicos de Mercado Libre
 * (tipos de publicación, modos de compra y condiciones del ítem) en TODA instalación.
 *
 * Por qué una migración y no solo los seeders: los upgrades de los clientes corren migraciones,
 * no seeders (`cliente-sin-version-el-upgrade-no-corre-seeders-del-camino`). Una instalación vieja
 * podía quedar con estas tablas vacías, y entonces el formulario del artículo no tenía qué ofrecer
 * en "Tipo de publicación" ni "Condición" y la publicación salía con los defaults del código.
 *
 * Solo INSERTA lo que falta, buscando por `meli_id`: no renombra ni borra filas existentes (un
 * artículo puede estar apuntando a cualquiera de ellas por id).
 */
class AsegurarCatalogosDeMercadoLibre extends Migration
{
    /**
     * Filas que tiene que haber, por tabla.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    protected function catalogos()
    {
        return [
            'meli_listing_types' => [
                ['meli_id' => 'gold_special', 'name' => 'Clásica (sin cuotas)'],
                ['meli_id' => 'gold_pro', 'name' => 'Premium (con cuotas)'],
                ['meli_id' => 'free', 'name' => 'Gratuita'],
            ],
            'meli_buying_modes' => [
                ['meli_id' => 'buy_it_now', 'name' => 'Compra inmediata'],
            ],
            'meli_item_conditions' => [
                ['meli_id' => 'new', 'name' => 'Nuevo'],
                ['meli_id' => 'used', 'name' => 'Usado'],
            ],
        ];
    }

    /**
     * Inserta las filas que falten.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->catalogos() as $tabla => $filas) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($filas as $fila) {
                $existe = DB::table($tabla)->where('meli_id', $fila['meli_id'])->exists();

                if ($existe) {
                    continue;
                }

                DB::table($tabla)->insert([
                    'meli_id'    => $fila['meli_id'],
                    'name'       => $fila['name'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * No se borra nada: las filas pueden estar referenciadas por artículos.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
