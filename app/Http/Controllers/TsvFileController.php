<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessArchivoDeIntercambioProductos;
use App\Jobs\ProcessArchivoDeIntercambioPrecios;
use App\Models\Article;
use Illuminate\Http\Request;

class TsvFileController extends Controller
{

    public $user_id = 500;
    
    function leer_archivo_articulos() {

        ProcessArchivoDeIntercambioProductos::dispatch($this->user_id);

        echo 'Se despacho!';

    }
    function leer_archivo_precios(){
        ProcessArchivoDeIntercambioPrecios::dispatch($this->user_id);
        echo 'Se despacharon los precios!';
    }

    //  Codigo  3107
    // Descripcion BAGGIO MULTIFRUTA 8 X 1000 CC.
    // A   1
    // Familia 4
    // NFamilia   JUGOS BRIK 
    // Rubro   30
    // NRubro  BRIK X 1000 CC.
    // Marca   1
    // NMarca  BEBIDAS
    // Stock   3041
    // Alicuota  1  
    // Barra   7790036559223
    // Peso    1
    // Minimo  4
    // Multiplo 4

}
