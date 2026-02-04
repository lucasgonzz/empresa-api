<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Mail\SimpleMail;
use App\Models\Error;
use App\Models\User;
use Illuminate\Http\Request;

class ErrorController extends Controller
{
    function store(Request $request) {
        if (isset($request->message)) {
            $model = Error::create([
                'message'   => $request->message,
                'file'      => isset($request->file) ? $request->file : null,
                'line'      => isset($request->line) ? $request->line : null,
                'user_id'   => $this->userId(),
                'api_url'   => env('API_URL'),
            ]);

            $mensajes = [];

            $mensajes[] = 'Archivo: '.$model->file;
            $mensajes[] = 'Linea: '.$model->line;
            $mensajes[] = 'Mensaje: '.$model->message;
            $mensajes[] = 'LINK: '.$model->api_url;


            $owner = User::find(config('app.USER_ID'));

            Mail::to('lucasgonzalez5500@gmail.com')->send(new SimpleMail([
                'asunto'    => 'Error API | '.$owner->company_name . ' | user_id: '.$owner->id,
                'mensajes'  => $mensajes,
            ]));      
        }
    }
}
