<?php

namespace Database\Seeders;

use App\Models\ClientHome;
use Illuminate\Database\Seeder;

class ClientHomeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'company_name'  => 'Innovate',
                'ciudad'    => '9 de Julio',
                'provincia'    => 'Buenos Aires',
                'image_url'     => 'innovate.jpeg',
                'instragram'    => 'https://www.instagram.com/innovate.materiales9dj/',
            ],
            [
                'company_name'  => 'Masquito',
                'ciudad'    => 'Cerrito',
                'provincia'    => 'Entre Rios',
                'image_url'     => 'masquito.jpeg',
                'instragram'    => 'https://www.instagram.com/masquitoreparaciones/',
            ],
            [
                'company_name'  => 'Fenix',
                'ciudad'    => 'Rosario',
                'provincia'    => 'Santa Fe',
                'image_url'     => 'fenix.jpeg',
                'online'        => 'https://fenix-mayorista.com.ar/inicio/ultimos-ingresados',
            ],
            [
                'company_name'  => 'San Blas',
                'ciudad'    => 'Formosa',
                'provincia'    => 'Formosa',
                'image_url'     => 'sanblas.jpeg'
            ],
            [
                'company_name'  => 'Ferreteria Trama',
                'ciudad'    => 'Montecarlo',
                'provincia'    => 'Corrientes',
                'image_url'     => 'trama.jpg',
                'instragram'    => 'https://www.instagram.com/tramaferreteria/',
                'online'        => 'https://www.tramaferreteria.com/',
            ],
            [
                'company_name'  => 'Golonorte',
                'ciudad'    => 'Esperanza',
                'provincia'    => 'Misiones',
                'image_url'     => 'golonorte.jpeg',
                'instragram'    => 'https://www.instagram.com/golonorte.iguazu/',
                'online'        => 'https://golonorte.com.ar/inicio/ultimos-ingresados',
            ],
            [
                'company_name'  => '3D Tisk',
                'ciudad'    => 'Capital',
                'provincia'    => 'Cordoba',
                'image_url'     => '3dtisk.jpg',
                'instragram'    => 'https://www.instagram.com/3dtisk.cba/',
            ],
            [
                'company_name'  => 'Truvari',
                'ciudad'    => 'Capital',
                'provincia'    => 'Cordoba',
                'image_url'     => 'truvari.jpg',
                'instragram'    => 'https://www.instagram.com/truvari_bebidas/',
                'online'        => 'https://truvaribebidas.com.ar/inicio/ultimos-ingresados',
            ],
            [
                'company_name'  => 'Racing Parts',
                'ciudad'    => 'Adrogue',
                'provincia'    => 'Buenos Aires',
                'image_url'     => '2r.jpg',
                'instragram'    => 'https://www.instagram.com/2r_racingparts/',
            ],
            [
                'company_name'  => 'Sr Imperio',
                'ciudad'    => 'Gonzalez Catan',
                'provincia'    => 'Buenos Aires',
                'image_url'     => 'sr-imperio.jpeg',
                'instragram'    => 'https://www.instagram.com/sr.imperio2025/',
            ],
            [
                'company_name'  => 'Pack Descartables',
                'ciudad'    => 'Rivadavia',
                'provincia'    => 'San Juan',
                'image_url'     => 'pack.png',
                'instragram'    => 'https://www.instagram.com/packdescartables_/?hl=es',
            ],
            [
                'company_name'  => 'Ferreteria Rober',
                'ciudad'    => 'Vicente López',
                'provincia'    => 'Buenos Aires',
                'image_url'     => 'rober.jpeg'
            ],
            [
                'company_name'  => 'HiperMax',
                'ciudad'    => 'Gualeguay',
                'provincia'    => 'Entre Rios',
                'instragram'      => 'https://www.instagram.com/hipermaxgualeguay/',
                'image_url'     => 'hipermax.jpg'
            ],
            [
                'company_name'  => 'Galvan',
                'ciudad'    => 'Rosario',
                'provincia'    => 'Santa Fe',
                'instragram'      => 'https://www.instagram.com/galvan_mayorista/',
                'online'        => 'https://galvanmayorista.com.ar/inicio/ultimos-ingresados',
                'image_url'     => 'galvan.webp'
            ],
            [
                'company_name'  => 'Ferretotal',
                'ciudad'    => 'Matheu',
                'provincia' => 'Buenos Aires',
                'image_url'     => 'ferretotal.jpg',
                'online'        => 'https://ferretotalmatheu.com.ar/inicio/ultimos-ingresados',
                'instragram'    => 'https://www.instagram.com/ferretotaloficial/?hl=es',
            ],

            [
                'company_name'  => 'Feito A Mao',
                'ciudad'    => 'Termas de Río Hondo',
                'provincia'    => 'Santiago del Estero',
                'image_url'     => 'feito.png',
                'instragram'    => 'https://www.instagram.com/feito.argentina/',
            ],
            [
                'company_name'  => 'Golden Breeze',
                'ciudad'    => 'Fatima',
                'provincia'    => 'Buenos Aires',
                'instragram'      => 'https://www.instagram.com/goldenbreezecat/?hl=es',
                'image_url'     => 'bolden-breeze.jpg'
            ],
            [
                'company_name'  => 'Distribuidora Vantres',
                'ciudad'    => 'Provincia de Buenos Aires',
                'image_url'     => 'vantres.png',
                'online'        => 'https://distribuidoravantres.com.ar/inicio/ultimos-ingresados',
            ],
            [
                'company_name'  => 'Golden Bike',
                'ciudad'    => 'Tandil',
                'provincia'    => 'Buenos Aires',
                'image_url'     => 'golden-bike.jpg',
                'instragram'    => 'https://www.instagram.com/goldenbike_arg/',
            ],
            [
                'company_name'  => 'San Cayetano',
                'ciudad'    => 'Cafayate',
                'provincia'    => 'Salta',
                'image_url'     => 'san-cayetano.png',
            ],
        ];

        foreach ($models as $model) {

            if (env('APP_ENV') == 'local') {

                $image_url = 'http://empresa.local:8000/storage/clients-home/'.$model['image_url'];
            } else {

                $image_url = 'https://api.comerciocity.com/public/storage/clients-home/'.$model['image_url'];
            }

            $model['image_url'] = $image_url;
            
            ClientHome::create($model);
        }
    }
}
