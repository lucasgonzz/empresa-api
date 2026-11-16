<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Address;
use App\Models\PromocionVinoteca;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Contrato único de edición para el formulario y las propuestas del asistente. */
class EdicionPromocionVinotecaHelper
{
    const CAMPOS = ['name', 'cost', 'final_price', 'stock', 'online', 'address_id', 'description', 'peso', 'alto', 'ancho', 'profundidad'];
    const ETIQUETAS = ['name' => 'Nombre', 'cost' => 'Costo por promo', 'final_price' => 'Precio de venta',
        'stock' => 'Stock de promos', 'online' => 'Disponible en la tienda', 'address_id' => 'Depósito', 'description' => 'Descripción',
        'peso' => 'Peso (kg)', 'alto' => 'Alto (cm)', 'ancho' => 'Ancho (cm)', 'profundidad' => 'Largo / profundidad (cm)'];

    public static function promo($id, $owner_id, $candado = false)
    {
        if (!self::es_id($id)) {
            self::rechazar('promo', 'No encontramos esa promo en este negocio. Volvé a buscarla por su nombre y seleccioná la correcta.');
        }
        $query = PromocionVinoteca::where('user_id', $owner_id)->where('id', $id);
        $promo = ($candado ? $query->lockForUpdate() : $query)->first();
        if (!$promo) {
            self::rechazar('promo', 'No encontramos esa promo en este negocio. Volvé a buscarla por su nombre y seleccioná la correcta.');
        }
        return $promo;
    }

    public static function validar($promo, array $cuerpo, $owner_id, $solo_cambios = false)
    {
        // El formulario genérico reenvía su modelo completo. Estos datos se comprueban pero no
        // se guardan: ni la identidad ni los artículos de un lote se editan desde esta operación.
        $compatibles = ['id', 'user_id', 'slug', 'created_at', 'updated_at', 'deleted_at', 'articles', 'images', 'childrens', 'temporal_id'];
        $permitidos = $solo_cambios ? self::CAMPOS : array_merge(self::CAMPOS, $compatibles);
        foreach (array_keys($cuerpo) as $campo) {
            if (!in_array($campo, $permitidos, true)) {
                self::rechazar('promo', 'Ese dato no se puede editar en una promo. Indicá solamente nombre, costo por promo, precio, stock, publicación, depósito, descripción o medidas de envío.');
            }
        }
        foreach (['id' => $promo->id, 'user_id' => $owner_id] as $campo => $valor) {
            if (array_key_exists($campo, $cuerpo) && (!self::es_id($cuerpo[$campo]) || (string) $cuerpo[$campo] !== (string) $valor)) {
                self::rechazar('promo', 'La identidad de la promo no coincide. Volvé a abrir la promo correcta antes de guardar.');
            }
        }
        $datos = array_intersect_key($cuerpo, array_flip(self::CAMPOS));
        if (!$datos) {
            self::rechazar('promo', 'Indicá qué dato de la promo querés cambiar antes de guardar.');
        }
        $medidas = MedidasEnvioHelper::datos($datos);
        $datos = array_merge(array_diff_key($datos, array_flip(MedidasEnvioHelper::CAMPOS)), $medidas);
        if (isset($datos['name']) && is_string($datos['name'])) {
            $datos['name'] = trim($datos['name']);
        }
        if (isset($datos['description']) && is_string($datos['description']) && strlen($datos['description']) > 65535) {
            self::rechazar('description', 'La descripción es demasiado larga. Acortá el texto antes de guardar.');
        }
        foreach (['cost', 'final_price'] as $campo) {
            if (array_key_exists($campo, $datos) && $datos[$campo] !== null
                && (!is_scalar($datos[$campo]) || is_bool($datos[$campo])
                    || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', (string) $datos[$campo]))) {
                self::rechazar($campo, 'Ingresá '.self::ETIQUETAS[$campo].' como un número mayor o igual a cero, con hasta dos decimales.');
            }
        }
        foreach (['stock', 'address_id'] as $campo) {
            if (array_key_exists($campo, $datos) && is_bool($datos[$campo])) {
                self::rechazar($campo, 'Ingresá '.self::ETIQUETAS[$campo].' como un número entero mayor o igual a cero.');
            }
        }
        Validator::make($datos, [
            'name' => 'sometimes|required|string|max:255',
            'cost' => 'sometimes|required|numeric|min:0|max:9999999999999999',
            'final_price' => 'sometimes|required|numeric|min:0|max:9999999999999999',
            'stock' => 'sometimes|required|integer|min:0|max:2147483647',
            'online' => 'sometimes|required|boolean',
            'address_id' => 'sometimes|required|integer|min:0|max:2147483647',
            'description' => 'sometimes|nullable|string|max:65535',
        ], [
            'required' => 'Completá :attribute con un valor válido antes de guardar.',
            'numeric' => 'Ingresá :attribute como un número mayor o igual a cero.',
            'integer' => 'Ingresá :attribute como un número entero mayor o igual a cero.',
            'min' => ':attribute no puede ser negativo. Ingresá cero o un valor positivo.',
            'max' => ':attribute supera el máximo admitido. Revisá el valor antes de guardar.',
            'string' => 'Ingresá :attribute como texto.',
            'boolean' => 'Indicá si la promo está disponible en la tienda: sí o no.',
        ], self::ETIQUETAS)->validate();
        if (isset($datos['address_id']) && (int) $datos['address_id'] !== 0
            && !Address::where('user_id', $owner_id)->whereNull('buyer_id')->where('id', $datos['address_id'])->exists()) {
            self::rechazar('address_id', 'Ese depósito no pertenece a este negocio o ya no existe. Elegí uno de tus depósitos antes de guardar.');
        }
        if (array_key_exists('articles', $cuerpo)) {
            self::validar_articulos($promo, $cuerpo['articles'], $owner_id);
        }
        if (array_key_exists('images', $cuerpo)) {
            if (!is_array($cuerpo['images'])) {
                self::rechazar('images', 'Las imágenes no son válidas. Volvé a abrir la promo antes de guardar.');
            }
            foreach ($cuerpo['images'] as $imagen) {
                if (!is_array($imagen) || !isset($imagen['id']) || !self::es_id($imagen['id'])
                    || !$promo->images()->where('id', $imagen['id'])->exists()) {
                    self::rechazar('images', 'Una imagen no pertenece a esta promo. Volvé a abrirla antes de guardar.');
                }
            }
        }
        if (!empty($cuerpo['childrens'])) {
            self::rechazar('childrens', 'Los artículos e imágenes se administran desde sus controles. Volvé a abrir la promo antes de guardar.');
        }
        return $datos;
    }

    private static function validar_articulos($promo, $articulos, $owner_id)
    {
        if (!is_array($articulos)) {
            self::rechazar('articles', 'Los artículos de la promo no son válidos. Volvé a abrirla antes de guardar.');
        }
        $actuales = $promo->articles()->get()->keyBy('id');
        $vistos = [];
        foreach ($articulos as $articulo) {
            $id = is_array($articulo) && isset($articulo['id']) ? $articulo['id'] : null;
            $actual = self::es_id($id) ? $actuales->get($id) : null;
            if (!$actual || (int) $actual->user_id !== (int) $owner_id || isset($vistos[$id])) {
                self::rechazar('articles', 'Un artículo no corresponde a esta promo de tu negocio. Volvé a abrir la promo correcta.');
            }
            $vistos[$id] = true;
            foreach (['amount', 'unidades_por_promo'] as $campo) {
                $recibido = $articulo['pivot'][$campo] ?? null;
                $guardado = $actual->pivot->{$campo};
                if (($recibido === null) !== ($guardado === null)
                    || ($recibido !== null && (!is_numeric($recibido) || (float) $recibido !== (float) $guardado))) {
                    self::rechazar('articles', 'Las cantidades del armado no se cambian al editar una promo. Volvé a abrirla; para devolver artículos usá la devolución de stock.');
                }
            }
        }
        if (count($vistos) !== $actuales->count()) {
            self::rechazar('articles', 'La composición de la promo cambió. Volvé a abrirla antes de guardar.');
        }
    }

    private static function es_id($id)
    {
        return (is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]*$/D', (string) $id);
    }

    public static function referencia($promo, array $datos)
    {
        $campos = array_unique(array_merge(['name'], array_keys($datos)));
        sort($campos);
        $valores = [];
        foreach ($campos as $campo) {
            $valores[$campo] = in_array($campo, MedidasEnvioHelper::CAMPOS, true)
                ? $promo->{$campo} : $promo->getRawOriginal($campo);
        }
        return ['campos' => $campos, 'huella' => hash('sha256', json_encode($valores))];
    }

    public static function comprobar_referencia($promo, array $datos, $referencia)
    {
        $actual = self::referencia($promo, $datos);
        if (!is_array($referencia) || $actual !== $referencia) {
            self::rechazar('promo', 'La promo cambió después de preparar la tarjeta. Pedí una propuesta nueva y revisá el costo por promo y los demás cambios antes de confirmar.');
        }
    }

    public static function resumen($promo, array $datos)
    {
        $renglones = [['etiqueta' => 'Promo', 'valor' => $promo->name.' (ID '.$promo->id.')']];
        foreach ($datos as $campo => $valor) {
            $antes = $promo->{$campo};
            if ($campo === 'online') {
                $antes = $antes ? 'Sí' : 'No';
                $valor = $valor ? 'Sí' : 'No';
            }
            $renglones[] = ['etiqueta' => self::ETIQUETAS[$campo], 'valor' => mb_substr((string) $antes, 0, 120).' → '.mb_substr((string) $valor, 0, 120)];
        }
        return $renglones;
    }

    private static function rechazar($campo, $mensaje)
    {
        throw ValidationException::withMessages([$campo => [$mensaje]]);
    }
}
