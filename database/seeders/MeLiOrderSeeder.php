<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\MeLiBuyer;
use App\Models\MeLiOrder;
use App\Models\MeLiPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MeLiOrderSeeder extends Seeder
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
                'me_li_order_id'    => '2000003508419013',
                'status'            => 'Pagado', 
                'status_detail'     => null, 
                'date_created'      => Carbon::now()->subDays(1), 
                'date_closed'       => Carbon::now(), 
                'total'             => 15000, 
                'me_li_buyer_id'    => 1, 
                'payments'          => [
                    [
                        'me_li_payment_id'      => '596707837',
                        'transaction_amount'    => 15000,
                        'status'                => 'approved',
                        'date_created'          => Carbon::now(),
                        'date_last_modified'    => null
                    ],
                ],
                'articles'          => [
                    [
                        'article_id'    => 1,
                        'amount'        => 3,
                    ],
                    [
                        'article_id'    => 2,
                        'amount'        => 1,
                    ],
                    [
                        'article_id'    => 3,
                        'amount'        => 3,
                    ],
                ],
                'me_li_buyer'       => [
                    'me_li_buyer_id'    => '943423334',
                    'name'              => 'Jorge',
                    'last_name'         => 'Lozano',
                ],
            ],
            [
                'me_li_order_id'    => '2345003508419013',
                'status'            => 'En proceso', 
                'status_detail'     => null, 
                'date_created'      => Carbon::now()->subDays(1), 
                'date_closed'       => Carbon::now(), 
                'total'             => 15000, 
                'me_li_buyer_id'    => 1, 
                'payments'          => [
                    [
                        'me_li_payment_id'      => '596707837',
                        'transaction_amount'    => 15000,
                        'status'                => 'approved',
                        'date_created'          => Carbon::now(),
                        'date_last_modified'    => null
                    ],
                ],
                'articles'          => [
                    [
                        'article_id'    => 1,
                        'amount'        => 3,
                    ],
                    [
                        'article_id'    => 2,
                        'amount'        => 1,
                    ],
                    [
                        'article_id'    => 3,
                        'amount'        => 3,
                    ],
                    [
                        'article_id'    => 4,
                        'amount'        => 1,
                    ],
                    [
                        'article_id'    => 5,
                        'amount'        => 1,
                    ],
                ],
                'me_li_buyer'       => [
                    'me_li_buyer_id'    => '943423334',
                    'name'              => 'Jorge',
                    'last_name'         => 'Lozano',
                ],
            ],
            [
                'me_li_order_id'    => '6544003508419013',
                'status'            => 'Pagado', 
                'status_detail'     => null, 
                'date_created'      => Carbon::now()->subDays(1), 
                'date_closed'       => Carbon::now(), 
                'total'             => 15000, 
                'me_li_buyer_id'    => 1, 
                'payments'          => [
                    [
                        'me_li_payment_id'      => '596707837',
                        'transaction_amount'    => 15000,
                        'status'                => 'approved',
                        'date_created'          => Carbon::now(),
                        'date_last_modified'    => null
                    ],
                ],
                'articles'          => [
                    [
                        'article_id'    => 1,
                        'amount'        => 3,
                    ],
                    [
                        'article_id'    => 2,
                        'amount'        => 1,
                    ],
                    [
                        'article_id'    => 3,
                        'amount'        => 3,
                    ],
                    [
                        'article_id'    => 4,
                        'amount'        => 1,
                    ],
                    [
                        'article_id'    => 5,
                        'amount'        => 1,
                    ],
                ],
                'me_li_buyer'       => [
                    'me_li_buyer_id'    => '943423334',
                    'name'              => 'Jorge',
                    'last_name'         => 'Lozano',
                ],
            ],
            [
                'me_li_order_id'    => '8754003508419013',
                'status'            => 'Pagado', 
                'status_detail'     => null, 
                'date_created'      => Carbon::now()->subDays(1), 
                'date_closed'       => Carbon::now(), 
                'total'             => 15000, 
                'me_li_buyer_id'    => 1, 
                'payments'          => [
                    [
                        'me_li_payment_id'      => '596707837',
                        'transaction_amount'    => 15000,
                        'status'                => 'approved',
                        'date_created'          => Carbon::now(),
                        'date_last_modified'    => null
                    ],
                ],
                'articles'          => [
                    [
                        'article_id'    => 1,
                        'amount'        => 3,
                    ],
                    [
                        'article_id'    => 2,
                        'amount'        => 1,
                    ],
                    [
                        'article_id'    => 3,
                        'amount'        => 3,
                    ],
                    [
                        'article_id'    => 4,
                        'amount'        => 1,
                    ],
                    [
                        'article_id'    => 5,
                        'amount'        => 1,
                    ],
                ],
                'me_li_buyer'       => [
                    'me_li_buyer_id'    => '943423334',
                    'name'              => 'Jorge',
                    'last_name'         => 'Lozano',
                ],
            ],
            [
                'me_li_order_id'    => '2000003508419013',
                'status'            => 'Pagado', 
                'status_detail'     => null, 
                'date_created'      => Carbon::now()->subDays(1), 
                'date_closed'       => Carbon::now(), 
                'total'             => 15000, 
                'me_li_buyer_id'    => 1, 
                'payments'          => [
                    [
                        'me_li_payment_id'      => '596707837',
                        'transaction_amount'    => 15000,
                        'status'                => 'approved',
                        'date_created'          => Carbon::now(),
                        'date_last_modified'    => null
                    ],
                ],
                'articles'          => [
                    [
                        'article_id'    => 1,
                        'amount'        => 3,
                    ],
                    [
                        'article_id'    => 2,
                        'amount'        => 1,
                    ],
                    [
                        'article_id'    => 3,
                        'amount'        => 3,
                    ],
                ],
                'me_li_buyer'       => [
                    'me_li_buyer_id'    => '943423334',
                    'name'              => 'Jorge',
                    'last_name'         => 'Lozano',
                ],
            ],
        ];


        for ($dias=0; $dias < 5 ; $dias++) { 
            for ($i=0; $i < 2; $i++) { 
                foreach ($models as $model) {
                    $me_li_buyer = MeLiBuyer::create([
                        'me_li_buyer_id'    => $model['me_li_buyer']['me_li_buyer_id'],
                        'name'              => $model['me_li_buyer']['name'],
                        'last_name'         => $model['me_li_buyer']['last_name'],
                    ]);

                    $me_li_order = MeLiOrder::create([
                        'me_li_order_id'    => $model['me_li_order_id'],
                        'status'            => $model['status'], 
                        'status_detail'     => $model['status_detail'], 
                        'date_created'      => $model['date_created'],
                        'date_closed'       => $model['date_closed'],
                        'total'             => $model['total'], 
                        'me_li_buyer_id'    => $me_li_buyer->id, 
                        'user_id'           => env('USER_ID'),
                        'created_at'        => Carbon::now()->subDays($dias),
                    ]); 

                    foreach ($model['payments'] as $payment) {
                        MeLiPayment::create([
                            'me_li_payment_id'      => $payment['me_li_payment_id'],
                            'transaction_amount'    => $payment['transaction_amount'],
                            'status'                => $payment['status'],
                            'date_created'          => $payment['date_created'],
                            'date_last_modified'    => $payment['date_last_modified'],
                            'me_li_order_id'        => $me_li_order->id,
                        ]);
                    }

                    foreach ($model['articles'] as $article) {
                        $stored_article = Article::find($article['article_id']); 
                        $me_li_order->articles()->attach($article['article_id'], [
                                                    'amount'    => $article['amount'],
                                                    'price'     => $stored_article->final_price,
                                                ]);
                    }
                }
            }
            $dias++;
        }
    }
}
