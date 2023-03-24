<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\TwilioHelper;
use App\Models\Buyer;
use App\Models\Message;
use App\Notifications\MessageSend;
use Illuminate\Http\Request;

class MessageController extends Controller
{

    function fromBuyer($buyer_id) {
        $models = Message::where('buyer_id', $buyer_id)
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    function setRead($buyer_id) {
        $models = Message::where('buyer_id', $buyer_id)
                            ->where('read', 0)
                            ->where('from_buyer', 1)
                            ->get();
        foreach ($models as $model) {
            $model->read = 1;
            $model->save();
        }
        return response(null, 200);
    }

    function store(Request $request) {
        $model = Message::create([
            'user_id' => $this->userId(),
            'buyer_id' => $request->buyer_id,
            'text' => StringHelper::onlyFirstWordUpperCase($request->text),
            'article_id' => $request->article_id,
        ]);
        $model = $this->fullModel('Message', $model->id);
        $buyer = Buyer::find($request->buyer_id);
        $buyer->notify(new MessageSend($model));
        return response()->json(['model' => $model], 201);
    }
}
