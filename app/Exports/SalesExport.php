<?php

namespace App\Exports;

use App\Models\Sale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Carbon\Carbon;

class SalesExport implements FromCollection
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        set_time_limit(99999999999999999);
        $this->articles = [];
        $sales = Sale::where('user_id', 121)
                        ->whereDate('created_at', '>=', Carbon::today()->subYears(1))
                        ->whereDate('created_at', '<=', Carbon::today()->subYears(1)->addMonths(1))
                        ->orderBy('created_at', 'ASC')
                        ->chunk(100, function($sales) {
                            foreach ($sales as $sale) {
                                foreach ($sale->articles as $article) {
                                    $this->add_to_excel($article);
                                }
                            }
                        });
        return $this->articles;
    }

    function add_to_excel($article) {
        $index = array_search($article->id, array_column($this->articles, 'id'));
        if (!$index) {
            $this->articles[] = [
                'id'        => $article->id,
                'name'      => $article->name,
                'amount'    => (float)$article->pivot->amount,
            ];
        } else {
            $this->articles[$index]['amount'] += (float)$article->pivot->amount;
        }
    }
}
