<?php

namespace App\Models\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RenglonesDeVenta extends BelongsToMany
{
    public const TABLAS = ['article_sale', 'combo_sale', 'sale_service', 'article_current_acount', 'current_acount_service'];
    public const PRECIO_CONSOLIDADO = 'price_consolidacion';

    private static $disponible = [];

    public function con_precio_consolidado(): self
    {
        if ($this->admite_precio_consolidado()) {
            $this->withPivot(self::PRECIO_CONSOLIDADO);
        }

        return $this;
    }

    /**
     * Conserva el precio preciso sin cambiar la escala de price de los renglones comunes.
     */
    public function conservar_precision(array $item): array
    {
        $precio = (float) $item['price'];
        $item['price'] = round($precio, 2);
        if ($precio != $item['price']) {
            if (!$this->admite_precio_consolidado()) {
                throw new \RuntimeException('La actualizacion de precios para ventas consolidadas todavia esta en curso.');
            }

            $item[self::PRECIO_CONSOLIDADO] = $precio;
        }

        return $item;
    }

    public function admite_precio_consolidado(): bool
    {
        $conexion = $this->getParent()->getConnection();
        $tabla = $this->getTable();
        $clave = $conexion->getName().':'.$conexion->getDatabaseName().':'.$tabla;

        if (!array_key_exists($clave, self::$disponible)) {
            self::$disponible[$clave] = $conexion->getSchemaBuilder()->hasColumn($tabla, self::PRECIO_CONSOLIDADO);
        }

        return self::$disponible[$clave];
    }

    public function expresion_de_precio(): string
    {
        return $this->admite_precio_consolidado()
            ? 'COALESCE('.self::PRECIO_CONSOLIDADO.', price)'
            : 'price';
    }

    /**
     * Expone el precio preciso al leer el renglon para facturacion, PDF y notas de credito.
     * El pivot sigue siendo el de Laravel y conserva el camino habitual de attach y sync.
     */
    public function newExistingPivot(array $attributes = [])
    {
        if (isset($attributes[self::PRECIO_CONSOLIDADO])) {
            $precio = (string) $attributes[self::PRECIO_CONSOLIDADO];
            $attributes['price'] = strpos($precio, '.') === false ? $precio : rtrim(rtrim($precio, '0'), '.');
        }
        unset($attributes[self::PRECIO_CONSOLIDADO]);

        return parent::newExistingPivot($attributes);
    }
}
