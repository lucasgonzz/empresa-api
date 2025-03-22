<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryPriceTypeRange;
use App\Models\PriceType;
use Illuminate\Database\Seeder;

class CategoryPriceTypeRangeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $categories = Category::where('user_id', env('USER_ID'))
                                ->get();

        $price_types = PriceType::where('user_id', env('USER_ID'))
                                ->orderBy('position', 'DESC')
                                ->get();

        foreach ($categories as $category) {
            
            $min = 1;
            $max = 6;

            $index = 1;

            foreach ($price_types as $price_type) {
                
                $index++;
                
                CategoryPriceTypeRange::create([
                    'category_id'   => $category->id,
                    'price_type_id' => $price_type->id,
                    'min'           => $min,
                    'max'           => $max,
                    'user_id'       => env('USER_ID'),
                ]);



                foreach ($category->sub_categories as $sub_category) {
                    
                    $min += 2;

                    if ($index == count($price_types)) {
                        
                        $max = null;

                    } else {

                        $max += 2;
                    }

                    CategoryPriceTypeRange::create([
                        'category_id'       => $category->id,
                        'sub_category_id'   => $sub_category->id,
                        'price_type_id'     => $price_type->id,
                        'min'               => $min,
                        'max'               => $max,
                        'user_id'           => env('USER_ID'),
                    ]);
                }



                $min += 6;

                if ($index == count($price_types)) {
                    
                    $max = null;

                } else {

                    $max += 6;
                }

            }
        }
    }
}
