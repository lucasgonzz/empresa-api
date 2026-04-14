<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleArticleAttachment extends Model
{
    protected $table = 'sale_article_attachments';

    protected $fillable = [
        'sale_id',
        'article_id',
        'file_path',
        'original_name',
        'observation',
    ];

    public function sale()
    {
        return $this->belongsTo('App\Models\Sale');
    }

    public function article()
    {
        return $this->belongsTo('App\Models\Article');
    }
}
