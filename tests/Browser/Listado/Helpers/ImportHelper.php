<?php

namespace Tests\Browser\Listado\Helpers;

class ImportHelper {

    function __construct($browser) {

        $this->browser = $browser;

    }

    function marcar_props($props) {

        $this->browser->waitFor("#limpiar_posiciones");
        $this->browser->scrollIntoView("#limpiar_posiciones");
        $this->browser->click("#limpiar_posiciones");
        $this->browser->pause(500);

        foreach ($props as $prop) {
            
            $input = "#{$prop['key']}-position";

            $this->browser->waitFor($input, 60);
            
            $this->browser->scrollIntoView($input);
            $this->browser->pause(500);
            $this->browser->type($input, $prop['position']);
            $this->browser->pause(1000);
            dump('Se escribio '.$prop['position'].' en '.$input);
        }
    }

    function marcar_crear_y_a_actualizar_radio() {

        $this->browser->click("#cargar_y_actualizar");
        $this->browser->pause(500);
    }

    function esperar_notificacion() {
        $modal_notification = "#global-notification";

        $this->browser->waitFor($modal_notification, 40);

        $this->browser->assertSeeIn($modal_notification, "Importacion de Excel finalizada correctamente");

        dump('Excel importado OK');

        $this->browser->pause(1000);

        $btn_close = "$modal_notification .close";
        $this->browser->waitFor($btn_close);
        $this->browser->click($btn_close);
        $this->browser->pause(1000);
    }

    function enviar_archivo($aceptar_confirm = false) {
        $this->browser->click("#btn_importar");

        if ($aceptar_confirm) {
            $this->browser->pause(1000);
            $this->browser->acceptDialog();
        }
    }

    function subir_archivo($file) {
        $input = "#input-file-article";

        $ruta = storage_path("app/test/$file.xlsx");

        $this->browser->scrollIntoView($input);
        
        $this->browser->pause(500);

        $this->browser->attach($input, $ruta);

        $this->browser->pause(500);
    }

    function abrir_modal_importacion() {
        $btn_dropdown = "#dropdown_article .dropdown-toggle";
        $this->browser->waitFor($btn_dropdown);
        $this->browser->click($btn_dropdown);

        $this->browser->pause(500);

        $this->browser->waitFor("#btn_import");
        $this->browser->click("#btn_import");
        
        $this->browser->pause(500);
    }
    
}
