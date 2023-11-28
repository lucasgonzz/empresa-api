<?php

namespace App\Http\Controllers\Helpers;

class RendimientoEmpresaHelper {
	
	function __construct($user) {
		$this->user = $user;
	}

	function rendimiento_del_mes($mes) {
		foreach ($sales as $sale) {
			foreach ($sale->articles as $article) {
				$this->article = $article;
				$rendimiento = $this->rendimiento_del_articulo();
				$cantidad_ventas++;
			}
		}
	}

	function rendimiento_del_articulo() {
		$precio_de_ese_momento = $this->article->pivot->price;
		$costo_de_ese_momento = $this->costo_de_ese_momento();
		if (!is_null($costo_de_ese_momento)) {
			return $precio_de_ese_momento - $costo_de_ese_momento;
		}
		return null;
	}

	function costo_de_ese_momento() {
		$marguen_de_ganancia = $this->marguen_de_ganancia_del_articulo();
		if (!is_null($marguen_de_ganancia_del_articulo)) {
			
		}
	}

	function marguen_de_ganancia_del_articulo() {
		if (!is_null($this->article->percentage_gain)) {
			return $this->article->percentage_gain;
		}
		if ($this->article->apply_provider_percentage_gain && !is_null($this->article->provider->percentage)) {
			return $this->article->provider->percentage;
		}

		if (!is_null($this->article->cost)) {
			return ($this->article->price * 100 / $this->article->cost) - 100;
		}

		if (is_null($this->article->cost)) {
			$article_de_mismo_proveedor_y_sub_categoria = Article::where('user_id', $this->user->id);
			if (!is_null($this->article->provider_id)) {
				$article_de_mismo_proveedor_y_sub_categoria = $article_de_mismo_proveedor_y_sub_categoria
																->where('provider_id', $this->article->provider_id);
			}
			if (!is_null($this->article->sub_category_id)) {
				$article_de_mismo_proveedor_y_sub_categoria = $article_de_mismo_proveedor_y_sub_categoria
																->where('sub_category_id', $this->article->sub_category_id);

			} else if (!is_null($this->article->category_id)) {
				$article_de_mismo_proveedor_y_sub_categoria = $article_de_mismo_proveedor_y_sub_categoria
																->where('category_id', $this->article->category_id);

			}

			$article_de_mismo_proveedor_y_sub_categoria = $article_de_mismo_proveedor_y_sub_categoria
															->whereNotNull('percentage_gain')
															->first();
			if (!is_null($article_de_mismo_proveedor_y_sub_categoria)) {
				return $article_de_mismo_proveedor_y_sub_categoria->percentage_gain;
			}
		}
		return null;
	}

}