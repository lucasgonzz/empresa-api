<?php

namespace App\Http\Controllers\Helpers;

use App\Models\ArticleVariant;

class ArticleVariantHelper {
	
	function __construct($article_id) {

        $this->variantes_ya_creadas = ArticleVariant::where('article_id', $article_id)
        												->get();

	}

    function check_cambio_en_cantidad_propiedades($models) {
        if (count($this->variantes_ya_creadas) >= 1) {
            $propiedades = $this->variantes_ya_creadas[0]->article_property_values;

            if (count($models[0]['article_property_values']) != count($propiedades)) {

                $this->delete_current_variants();
            }
        }
    }

    function delete_current_variants() {
        foreach ($this->variantes_ya_creadas as $article_variant) {
            
            $article_variant->delete();
        }
    }

	function variant_ya_esta_creada($model) {

		$this->model_request = $model;
        
        $this->ya_esta_creada = false;

        foreach ($this->variantes_ya_creadas as $variante_ya_creada) {

        	$this->set_propiedades_para_chequear();

            foreach ($this->propiedades_para_chequear as $propiedad_para_chequear) {

                foreach ($variante_ya_creada->article_property_values as $propiedad_de_variante_ya_creada) {
                    
                    if ($propiedad_para_chequear['id'] == $propiedad_de_variante_ya_creada->id) {

                        $this->propiedades_para_chequear[$propiedad_para_chequear['id']]['creada'] = true;
                    }
                }

            }

            $this->check_article_property_values_creadas();

            if ($this->ya_esta_creada) {
                break;
            }
            
        }

        return $this->ya_esta_creada;
    }

    function set_propiedades_para_chequear() {

        $this->propiedades_para_chequear = [];

    	foreach ($this->model_request['article_property_values'] as $article_property_value) {

        	$this->propiedades_para_chequear[$article_property_value['id']] = $article_property_value;

        	$this->propiedades_para_chequear[$article_property_value['id']]['creada'] = false;
    		
    	}
    }

    function check_article_property_values_creadas() {

        $this->ya_esta_creada = true;

        foreach ($this->propiedades_para_chequear as $propiedad_para_chequear) {

            if (!$propiedad_para_chequear['creada']) {

                $this->ya_esta_creada = false;
            }

        }

    }

}