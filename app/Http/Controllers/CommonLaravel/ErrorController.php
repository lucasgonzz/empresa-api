<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ApiUrlHelper;
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
                'api_url'   => ApiUrlHelper::base(),
            ]);

            $mensajes = [];

            $mensajes[] = 'Archivo: '.$model->file;
            $mensajes[] = 'Linea: '.$model->line;
            $mensajes[] = 'Mensaje: '.$model->message;
            $mensajes[] = 'LINK: '.$model->api_url;


            $owner = User::find(config('app.USER_ID'));

            // 🔴 DECIDIDO: este envío NO mira los rechazos (RechazosDeCorreoHelper). Es un aviso interno a Lucas que no afirma nada: el error ya quedó guardado en la base
            // arriba, no hay marca de "enviado" ni una respuesta que el rechazo desmienta (el SPA que lo reporta no lee nada de acá). Si el servidor SMTP lo rechaza
            // (un 550 en el RCPT TO, que SwiftMailer no convierte en excepción), solo se pierde el aviso, y el listener AnotarMailRechazadoPorElServidor lo deja en el log.
            // Convertir ese rechazo en una excepción acá sumaría un camino más por el que un fallo del correo rompe el reporte de errores del SPA (hoy ya lo rompe
            // una excepción del transporte, por ejemplo sin conexión al servidor SMTP, pero no el rechazo de una casilla): justo lo que no tiene que pasar.
            // La decisión está escrita en TodoEnvioDeMailMiraLosRechazosTest::ENVIOS_SIN_CHEQUEO; si este punto empieza a afirmar algo, sacalo de esa lista.
            Mail::to('lucasgonzalez5500@gmail.com')->send(new SimpleMail([
                'asunto'    => 'Error API | '.$owner->company_name . ' | user_id: '.$owner->id,
                'mensajes'  => $mensajes,
            ]));      
        }
    }
}
