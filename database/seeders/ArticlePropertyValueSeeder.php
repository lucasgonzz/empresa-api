<?php

namespace Database\Seeders;

use App\Models\ArticlePropertyType;
use App\Models\ArticlePropertyValue;
use Illuminate\Database\Seeder;

class ArticlePropertyValueSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $property_type_color = ArticlePropertyType::where('name', 'Color')->first();
        $property_type_talle = ArticlePropertyType::where('name', 'Talle')->first();
        $models = [
            [
                'name'                      =>    'Amarillo',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [
                'name'                      =>    'Azul',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [
                'name'                      =>    'Beige',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Blanco',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Bordó',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Celeste',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Fucsia',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Gris',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Marrón',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Naranja',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Negro',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Plata',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Rojo',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Rosa',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Verde',
                'article_property_type_id'  => $property_type_color->id,
            ],
            [

                'name'                      =>    'Violeta',
                'article_property_type_id'  => $property_type_color->id,
            ],

            [
                'name'                      => 'XL',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'                      => 'L',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'                      => 'M',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'                      => 'S',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => 'XS',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => 'S',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => 'M',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => 'L',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => 'XL',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => 'XXL',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '2',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '4',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '6',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '8',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '10',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '12',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '14',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '34',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '35',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '36',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '37',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '38',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '39',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '40',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '41',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '42',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '43',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '44',
                'article_property_type_id'  => $property_type_talle->id,
            ],
            [
                'name'  => '45',
                'article_property_type_id'  => $property_type_talle->id,
            ],
        ];
        $num = 1;
        foreach ($models as $model) {
            $model['num']   = $num;
            ArticlePropertyValue::create($model);
            $num++;
        }
    }
}
