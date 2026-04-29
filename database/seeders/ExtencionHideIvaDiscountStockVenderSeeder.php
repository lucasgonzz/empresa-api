<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder para registrar la extensión que permite ocultar en VENDER los toggles
 * de precios con IVA y descontar stock (la UI en empresa-spa debe consultar el slug).
 */
class ExtencionHideIvaDiscountStockVenderSeeder extends Seeder
{
    /**
     * Inserta la extensión si aún no existe (idempotente para entornos ya sembrados).
     *
     * @return void
     */
    public function run()
    {
        // Slug estable compartido con ExtencionSeeder y con hasExtencion() en el front.
        $slug = 'hide_iva_and_discount_stock_in_vender';

        // Nombre mostrado al asignar la extensión al comercio.
        $name = 'Ocultar opciones de aplicar IVA y descontar stock en VENDER';

        ExtencionEmpresa::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }
}
