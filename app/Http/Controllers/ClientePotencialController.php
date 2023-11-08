<?php

namespace App\Http\Controllers;

use App\Mail\ClientePotencial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ClientePotencialController extends Controller
{
    function clientePotencial($nombre_negocio, $email) {
        Mail::to($email)->send(new ClientePotencial($nombre_negocio));
        echo 'Correo enviado';
    }
}
