<?php

namespace Database\Seeders;

use App\Models\CAPaymentMethodType;
use App\Models\CurrentAcountPaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Seeder SUELTO del medio de pago "Retención" (misión compras-factura-manual-alicuotas, 17/9/2026).
 *
 * 🔴 PARA QUÉ EXISTE, SI LOS SEEDERS DE LA CADENA YA LO SIEMBRAN. `CAPaymentMethodTypeSeeder` y
 * `CurrentAcountPaymentMethodSeeder` corren cuando se arma una base NUEVA (DatabaseSeeder,
 * UserSetupHelper, DemoSetupHelper). Las bases de los ~40 clientes que ya están andando no vuelven
 * a pasar por ahí nunca: en un despliegue corren las migraciones, no los seeders. Sin este seeder
 * suelto, ningún cliente actual vería el medio de pago nuevo y toda la parte C quedaría construida
 * y sin usar.
 *
 * Se corre una vez por base:
 *
 *     php artisan db:seed --force --class="Database\Seeders\MetodoPagoRetencionSeeder"
 *
 * Es IDEMPOTENTE: si el tipo o el medio de pago ya existen, no los toca ni los duplica. Correrlo de
 * más no rompe nada.
 *
 * ⚠️ No renombra ni reordena nada del catálogo existente: el medio de pago nuevo queda con el id
 * que le toque al final. Los ids viejos (1 = Cheque, 3 = Efectivo) están hardcodeados en varios
 * lugares del sistema y no se pueden mover.
 */
class MetodoPagoRetencionSeeder extends Seeder
{
    /** Slug del tipo de medio de pago. Es el que miran el back y la SPA. */
    const SLUG = 'retencion';

    /** Nombre del medio de pago tal como lo ve el usuario en el modal de cobro. */
    const NOMBRE_METODO = 'Retención';

    /**
     * @return void
     */
    public function run()
    {
        $type = CAPaymentMethodType::where('slug', self::SLUG)->first();

        if (is_null($type)) {

            $type = CAPaymentMethodType::create([
                'name' => 'Retencion',
                'slug' => self::SLUG,
            ]);
        }

        /*
         * Se busca por TIPO y no por nombre: lo que hace que el medio de pago funcione como
         * retención es el tipo, no cómo se llame. Si un comercio ya tenía un método llamado
         * "Retencion" a mano (sin tipo, o sea sin ninguno de los comportamientos), este seeder no
         * lo pisa y crea el que corresponde — el viejo sigue impactando en caja, que es lo que
         * venía haciendo, y el nuevo es el que cancela deuda sin entrar plata.
         */
        $existe = CurrentAcountPaymentMethod::where('c_a_payment_method_type_id', $type->id)->exists();

        if ($existe) {

            return;
        }

        CurrentAcountPaymentMethod::create([
            'name'                       => self::NOMBRE_METODO,
            'c_a_payment_method_type_id' => $type->id,
        ]);
    }
}
