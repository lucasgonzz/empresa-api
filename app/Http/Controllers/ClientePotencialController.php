<?php

namespace App\Http\Controllers;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Mail\ClientePotencial;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ClientePotencialController extends Controller
{
    function clientePotencial($nombre_negocio, $email) {
        Mail::to($email)->send(new ClientePotencial($nombre_negocio));

        // 🔴 Que send() no haya tirado NO quiere decir que el mail haya salido: si el servidor SMTP rechaza la casilla (un 550 en el RCPT TO), SwiftMailer no
        // tira nada y esta ruta —que se abre a mano desde el navegador— escribía "Correo enviado" sobre un mail que nunca salió.
        try {
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
        } catch (MailRechazadoPorElServidorException $e) {
            // Sin la casilla en el texto: ya la conoce quien armó la URL. El éxito de abajo NO cambia (sigue siendo un echo y una respuesta vacía).
            return response('No se pudo enviar el correo: '.$e->getMessage().'.', 422);
        }

        echo 'Correo enviado';
    }
}
