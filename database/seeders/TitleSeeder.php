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
        $user = User::where('company_name', 'lucas')->first();
        $models = [
            [
                'num'       => 1,
                'user_id'   => $user->id,
                'header'    => 'Nebulastore',
                'lead'      => 'Tienda de ropa americana',
                'color'     => '#f9b234',
                'text_color'=> '#45D83B',
                'image_url' => 'http://empresa.local:8000/storage/campera.webp',
                'crop_image_url' => 'http://empresa.local:8000/storage/campera_recortada.jpg',
            ],
            [
                'num'       => 2,
                'user_id'   => $user->id,
                'header'    => 'Consultanos a travez de',
                'lead'      => 'Instagram',
                'color'     => '#f9b234',
                'text_color'=> null,
                'image_url' => 'http://empresa.local:8000/storage/campera2.webp',
                'crop_image_url' => 'http://empresa.local:8000/storage/campera2_recortada.jpg',
            ],
        ];
        foreach ($models as $title) {
            Title::create([
                'num'               => $title['num'],
                'user_id'           => $title['user_id'],
                'header'            => $title['header'],
                'lead'              => $title['lead'],
                'color'             => $title['color'],
                'text_color'        => $title['text_color'],
                'image_url'         => $title['image_url'],
                'crop_image_url'         => $title['crop_image_url'],
            ]);
        }
    }
}
