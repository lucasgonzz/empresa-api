<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\User;
use Illuminate\Database\Seeder;

class BuyerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $lucas = Buyer::create([
            // Los tres num de este bloque estaban repetidos en 1 (bug viejo). Se corrigen de
            // paso a 1/2/3 porque los 12 perfiles nuevos siguen la numeracion correlativa desde
            // aca (4 en adelante) y dejarlos en 1 hubiera repetido num tres veces mas.
            'num'                       => 1,
            'name'                      => 'Lucas gonzalez',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'lucasgonzalez5500@gmail.com',
            'password'                  => bcrypt('1234'),
            'comercio_city_client_id'   => 1,
            'user_id'                   => config('app.USER_ID'),
        ]);
        $marcos = Buyer::create([
            'num'                       => 2,
            'name'                      => 'Marcos gonzalez',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'lucasgonzalez210200@gmail.com',
            'password'                  => bcrypt('1234'),
            'comercio_city_client_id'   => 2,
            'user_id'                   => config('app.USER_ID'),
        ]);

        $marcos = Buyer::create([
            'num'                       => 3,
            'name'                      => 'Vendedor',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'vendedor@gmail.com',
            'password'                  => bcrypt('1234'),
            'seller_id'                 => 1,
            'user_id'                   => config('app.USER_ID'),
        ]);

        /*
            Perfiles de tienda 4 a 18, agregados para la semilla de datos de prueba completa
            (unidad U2). comercio_city_client_id apunta a los clientes 3 a 17 (ClientSeeder):
            sin ese vinculo un Buyer no genera ninguna sugerencia del motor de ofertas, porque
            LOS CUATRO CRITERIOS (CriteriosDeOfertaService: afinidad, carrito_abandonado,
            reactivacion e interes_ecommerce) exigen buyer vinculado -- no solo los dos que lo
            necesitaban para el join con buyer_tracking_events. afinidad() lo agregó por su cuenta
            (whereIn contra buyers.comercio_city_client_id) y reactivacion() lo hereda de ahí sin
            duplicarlo, porque arma sus candidatos a partir de lo que devuelve afinidad(). El motivo
            de fondo es el mismo para los cuatro: la query que corre la tienda (textual en
            database/migrations/2026_08_17_100200_create_client_offers_table.php:16-26) filtra por
            `co.client_id = buyers.comercio_city_client_id del buyer logueado`, así que una oferta
            de un cliente sin buyer no la ve nadie, nunca.

            Medido sobre esta misma semilla (25 clientes, 17 con buyer): con los 25 la corrida local
            daba 69 líneas activables, pero 18 de esas 69 eran de clientes sin buyer -- invisibles
            para la tienda. Con los 17 que sí tienen buyer, la corrida da 51 líneas y el 100% son
            visibles. El número que hay que mover si algún día hacen falta más compradores sigue
            siendo el de abajo (17); ver CriteriosDeOfertaService::afinidad() para el detalle del
            filtro.

            🔴 POR QUE 17 CLIENTES CON PERFIL DE TIENDA Y NO LOS 25 QUE SIEMBRA ClientSeeder.

            Dar de alta un Buyer no es gratis del lado del ERP: ActividadTiendaHelper::sembrar()
            arranca por cancelar_saldos(), que le cobra la cuenta corriente COMPLETA a todo cliente
            que tenga Buyer con comercio_city_client_id. Tiene que hacerlo -- si no,
            CriteriosDeOfertaService::excluir_malos_pagadores() los descarta a todos por deuda
            vencida y ofertas:generar devuelve cero lineas --, pero el efecto de segundo orden es
            que ese cliente queda con saldo 0 y desaparece de los reportes de cuenta corriente.
            Con perfil para los 25, la demo no tendria un solo deudor que mostrar.

            Se cubren los primeros 17 y quedan afuera los clientes 18 a 25, que son los que
            sostienen la cuenta corriente viva. Esos 8 no son un recorte al azar: ClientSeeder
            rota address_id 1..4, iva_condition_id 1/2 y price_type_id 2/3/null, y 8 es dos vueltas
            enteras de la rotacion de sucursales, asi que la deuda que sobrevive queda repartida en
            2 clientes por cada una de las 4 sucursales, mitad y mitad entre las dos condiciones de
            IVA, y con las tres variantes de lista de precios representadas. O sea que los reportes
            de deudores y la posicion fiscal tienen con que en CUALQUIER filtro, no solo en el
            total. El lado de la tienda igual se queda con 17 compradores, mas que suficiente para
            que los tres perfiles de ActividadTiendaHelper::PERFILES se noten distintos entre si.

            Si algun dia se quieren mas compradores, el numero que hay que mover es este y la
            cuenta a rehacer es la de arriba: cada cliente que suma perfil es un deudor menos.
        */
        $perfiles_tienda = [
            ['num' => 4,  'name' => 'Sofia',     'surname' => 'Martinez',  'email' => 'sofia.martinez@gmail.com',   'client_id' => 3],
            ['num' => 5,  'name' => 'Nicolas',    'surname' => 'Fernandez', 'email' => 'nicolas.fernandez@gmail.com', 'client_id' => 4],
            ['num' => 6,  'name' => 'Valentina',  'surname' => 'Rodriguez', 'email' => 'valentina.rodriguez@gmail.com', 'client_id' => 5],
            ['num' => 7,  'name' => 'Tomas',      'surname' => 'Diaz',      'email' => 'tomas.diaz@gmail.com',        'client_id' => 6],
            ['num' => 8,  'name' => 'Camila',     'surname' => 'Gomez',     'email' => 'camila.gomez@gmail.com',      'client_id' => 7],
            ['num' => 9,  'name' => 'Mateo',      'surname' => 'Alvarez',   'email' => 'mateo.alvarez@gmail.com',     'client_id' => 8],
            ['num' => 10, 'name' => 'Julieta',    'surname' => 'Romero',    'email' => 'julieta.romero@gmail.com',    'client_id' => 9],
            ['num' => 11, 'name' => 'Franco',     'surname' => 'Sosa',      'email' => 'franco.sosa@gmail.com',       'client_id' => 10],
            ['num' => 12, 'name' => 'Agustina',   'surname' => 'Torres',    'email' => 'agustina.torres@gmail.com',   'client_id' => 11],
            ['num' => 13, 'name' => 'Bruno',      'surname' => 'Ibanez',    'email' => 'bruno.ibanez@gmail.com',      'client_id' => 12],
            ['num' => 14, 'name' => 'Martina',    'surname' => 'Acosta',    'email' => 'martina.acosta@gmail.com',    'client_id' => 13],
            ['num' => 15, 'name' => 'Lautaro',    'surname' => 'Molina',    'email' => 'lautaro.molina@gmail.com',    'client_id' => 14],
            ['num' => 16, 'name' => 'Emilia',     'surname' => 'Benitez',   'email' => 'emilia.benitez@gmail.com',    'client_id' => 15],
            ['num' => 17, 'name' => 'Thiago',     'surname' => 'Ledesma',   'email' => 'thiago.ledesma@gmail.com',    'client_id' => 16],
            ['num' => 18, 'name' => 'Renata',     'surname' => 'Cabrera',   'email' => 'renata.cabrera@gmail.com',    'client_id' => 17],
        ];

        foreach ($perfiles_tienda as $perfil) {
            Buyer::create([
                'num'                       => $perfil['num'],
                'name'                      => $perfil['name'],
                'surname'                   => $perfil['surname'],
                'city'                      => 'Gualeguay',
                'phone'                     => '+5493444622139',
                'email'                     => $perfil['email'],
                'password'                  => bcrypt('1234'),
                'comercio_city_client_id'   => $perfil['client_id'],
                'user_id'                   => config('app.USER_ID'),
            ]);
        }
    }

    function matias() {
        $user = User::where('company_name', 'Ferretodo')
                        ->first();
        $marcos = Buyer::create([
            'num'                       => 1,
            'name'                      => 'Lucas',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'lucasgonzalez5500@gmail.com',
            'password'                  => bcrypt('1234'),
            'comercio_city_client_id'   => 5,
            'user_id'                   => config('app.USER_ID'),
        ]);
    }
}
