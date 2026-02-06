<?php

namespace Database\Seeders;

use App\Models\Title;
use App\Models\User;
use Illuminate\Database\Seeder;

class TitleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $this->truvari();

        $this->default();
    }

    function default() {


        if (config('app.FOR_USER') == 'truvari') {
            return;
        }

        $models = [
            [
                'num'       => 1,
                'color'     => '#333',
                'image_url' => env('APP_ENV') == 'local' ? 'http://empresa.local:8000/storage/banner.jpg' : 'https://api-prueba.comerciocity.com/public/storage/171398787529312.webp',
                'crop_image_url' => 'http://empresa.local:8000/storage/banner_mobile.webp',
            ],
        ];
        
        foreach ($models as $title) {
            $title['user_id'] = config('app.USER_ID');
            Title::create($title);
        }
    }

    function truvari() {

        if (config('app.FOR_USER') != 'truvari') {
            return;
        }

        $models = [
            [
                'num'       => 1,
                'image_url' => 'http://empresa.local:8000/storage/vinoteca/banner-1-escritorio.webp',
                'crop_image_url' => 'http://empresa.local:8000/storage/vinoteca/banner-1-mobil.webp',
            ],
            [
                'num'       => 2,
                'image_url' => 'http://empresa.local:8000/storage/vinoteca/banner-2-escritorio.webp',
                'crop_image_url' => 'http://empresa.local:8000/storage/vinoteca/banner-2-mobil.webp',
            ],
        ];
        
        foreach ($models as $title) {
            $title['user_id'] = config('app.USER_ID');
            Title::create($title);
        }
    }
}
