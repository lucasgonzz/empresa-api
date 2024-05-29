<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessArchivoDeIntercambioClientes;
use App\Jobs\ProcessArchivoDeIntercambioProductos;
use App\Jobs\ProcessArchivoDeIntercambioPrecios;
use App\Models\Article;
use Illuminate\Http\Request;

class TsvFileController extends Controller
{

    public $user_id = 600;
    
    function leer_archivo_articulos() {

        ProcessArchivoDeIntercambioProductos::dispatch($this->user_id);

        echo 'Se despacharon los articulos!';

    }
    function leer_archivo_precios(){
        ProcessArchivoDeIntercambioPrecios::dispatch($this->user_id);
        echo 'Se despacharon los precios!';
    }

    function leer_archivo_clientes() {

        ProcessArchivoDeIntercambioClientes::dispatch($this->user_id);

        echo 'Se despacharon los clientes!';

    }

}
