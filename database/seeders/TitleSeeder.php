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
        $user = User::where('company_name', 'Autopartes Boxes')->first();
        $models = [
            [
                'num'       => 1,
                'user_id'   => $user->id,
                // 'header'    => 'Nebulastore',
                // 'lead'      => 'Tienda de ropa americana',
                'color'     => '#333',
                'image_url' => 'http://empresa.local:8000/storage/banner.jpg',
                'crop_image_url' => 'http://empresa.local:8000/storage/banner_mobile.jpg',
            ],
            // [
            //     'num'       => 2,
            //     'user_id'   => $user->id,
            //     'header'    => 'Consultanos a travez de',
            //     'lead'      => 'Instagram',
            //     'color'     => '#f9b234',
            //     'text_color'=> null,
            //     'image_url' => 'http://empresa.local:8000/storage/campera2.webp',
            //     'crop_image_url' => 'http://empresa.local:8000/storage/campera2_recortada.jpg',
            // ],
        ];
        foreach ($models as $title) {
            Title::create($title);
        }
    }
}
