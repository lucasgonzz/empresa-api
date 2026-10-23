<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

use App\Models\Address;
use Illuminate\Database\Eloquent\Model;

/**
 * Las sucursales en el encabezado del PDF de venta, presupuesto y pedido (misión
 * pdf-direccion-sucursales, 23/10/2026).
 *
 * La dirección del comprobante deja de salir del perfil del usuario (`users.address_company` /
 * `users.all_addresses_in_sale_pdf`, que ningún PDF actual imprimía) y sale de las sucursales
 * (`addresses`). El usuario la elige con tres chips del emisor en el diseñador del encabezado
 * (`header_layout.emisor.izquierda/derecha`):
 *
 *  - `sucursal_comprobante`: la sucursal del comprobante (`address_id` de la venta, del presupuesto
 *    o del pedido).
 *  - `todas_las_sucursales`: un renglón por cada sucursal del dueño, la principal primero y después
 *    por id. Sigue sola a las sucursales nuevas.
 *  - `sucursal_<id>`: una sucursal puntual. Si ya no existe o no es del dueño no imprime nada.
 *
 * Formato de cada renglón (D5): rótulo + valor, como el resto de los chips.
 *  - Valor: el Domicilio (`street_number`) y la ciudad y la provincia que haya, separados por coma.
 *    Sin Domicilio ni ciudad el renglón no se imprime (vacío = se saltea, como web/teléfono/email).
 *  - Rótulo: con UNA sola sucursal, "Dirección: "; con dos o más, "Sucursal <Nombre>: " (el Nombre es
 *    `street`, así lo carga la SPA), o "Sucursal: " si no tiene nombre.
 *  - Si dos chips resuelven a la misma sucursal se imprime una sola vez: la primera que aparece
 *    (izquierda antes que derecha, en el orden del layout).
 *
 * Sucursales del dueño = `addresses` con `user_id` del dueño del comprobante y `buyer_id` NULL: en la
 * misma tabla hay domicilios de compradores de la tienda vieja, que no son sucursales. La tabla no
 * tiene borrado lógico (una sucursal eliminada ya no está).
 *
 * Una instancia por dibujo del encabezado: lleva la cuenta de las sucursales ya impresas (dedupe) y
 * consulta la base recién la primera vez que se le pide un chip. Un layout sin chips de sucursal no
 * dispara ninguna consulta y el PDF sale idéntico al de antes.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor ni enum.
 */
class SucursalesDelEncabezadoPdf
{
    /** Chip de la sucursal del comprobante. */
    const CHIP_DEL_COMPROBANTE = 'sucursal_comprobante';

    /** Chip de todas las sucursales del dueño. */
    const CHIP_TODAS = 'todas_las_sucursales';

    /** Chip de una sucursal puntual: `sucursal_<id>`. */
    const PATRON_DE_SUCURSAL_PUNTUAL = '/^sucursal_(\d+)$/';

    /**
     * El comprobante (Sale, o el objeto que imita una venta de BudgetPdfDocument / OrderPdfDocument).
     *
     * @var mixed
     */
    protected $documento;

    /** @var int|null */
    protected $owner_id;

    /**
     * Sucursales del dueño en el orden en que se imprimen, o null si todavía no se consultaron.
     *
     * @var \Illuminate\Support\Collection|null
     */
    protected $sucursales = null;

    /**
     * Ids de las sucursales ya impresas en este encabezado.
     *
     * @var array<int, bool>
     */
    protected $impresas = [];

    /**
     * @param mixed    $documento El comprobante del encabezado.
     * @param int|null $owner_id  El dueño del comprobante: el mismo usuario del que el PDF saca el
     *                            resto de los datos del emisor.
     */
    public function __construct($documento, $owner_id)
    {
        $this->documento = $documento;
        $this->owner_id = is_null($owner_id) ? null : (int) $owner_id;
    }

    /**
     * ¿La clave es un chip de sucursal?
     *
     * @param mixed $key
     * @return bool
     */
    public static function es_chip($key)
    {
        if (! is_string($key)) {
            return false;
        }

        return $key === self::CHIP_DEL_COMPROBANTE
            || $key === self::CHIP_TODAS
            || preg_match(self::PATRON_DE_SUCURSAL_PUNTUAL, $key) === 1;
    }

    /**
     * ¿El header_layout tiene algún chip de sucursal en el emisor (izquierda o derecha)?
     *
     * @param mixed $layout
     * @return bool
     */
    public static function layout_tiene_chip($layout)
    {
        if (! is_array($layout) || ! isset($layout['emisor']) || ! is_array($layout['emisor'])) {
            return false;
        }

        foreach (['izquierda', 'derecha'] as $lado) {
            if (! isset($layout['emisor'][$lado]) || ! is_array($layout['emisor'][$lado])) {
                continue;
            }

            foreach ($layout['emisor'][$lado] as $key) {
                if (self::es_chip($key)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Los renglones que imprime un chip de sucursal, sin las sucursales que ya imprimió otro chip de
     * este encabezado. Las que devuelve quedan marcadas como impresas.
     *
     * @param string $key
     * @return array<int, array{rotulo: string, valor: string}>
     */
    public function renglones($key)
    {
        $renglones = [];

        foreach ($this->sucursales_del_chip($key) as $sucursal) {
            $id = (int) $sucursal->id;

            if (isset($this->impresas[$id])) {
                continue;
            }

            $valor = self::valor($sucursal);

            /** Sin Domicilio ni ciudad no hay nada que imprimir. */
            if ($valor === '') {
                continue;
            }

            $this->impresas[$id] = true;
            $renglones[] = [
                'rotulo' => $this->rotulo($sucursal),
                'valor' => $valor,
            ];
        }

        return $renglones;
    }

    /**
     * El valor del renglón: Domicilio, ciudad y provincia, los que haya, separados por coma. Vacío si
     * la sucursal no tiene Domicilio ni ciudad (la provincia sola no alcanza para ubicarla).
     *
     * @param mixed $sucursal
     * @return string
     */
    public static function valor($sucursal)
    {
        $domicilio = trim((string) $sucursal->street_number);
        $ciudad = trim((string) $sucursal->city);
        $provincia = trim((string) $sucursal->province);

        if ($domicilio === '' && $ciudad === '') {
            return '';
        }

        $partes = [];
        foreach ([$domicilio, $ciudad, $provincia] as $parte) {
            if ($parte !== '') {
                $partes[] = $parte;
            }
        }

        return implode(', ', $partes);
    }

    /**
     * El rótulo del renglón: "Dirección: " si el dueño tiene una sola sucursal; si tiene más,
     * "Sucursal <Nombre>: " ("Sucursal: " sin nombre).
     *
     * @param mixed $sucursal
     * @return string
     */
    protected function rotulo($sucursal)
    {
        if ($this->sucursales()->count() <= 1) {
            return 'Dirección: ';
        }

        $nombre = trim((string) $sucursal->street);

        return $nombre === '' ? 'Sucursal: ' : 'Sucursal '.$nombre.': ';
    }

    /**
     * Las sucursales que resuelve un chip, en orden (antes del dedupe).
     *
     * @param string $key
     * @return array
     */
    protected function sucursales_del_chip($key)
    {
        if ($key === self::CHIP_TODAS) {
            return $this->sucursales()->all();
        }

        if ($key === self::CHIP_DEL_COMPROBANTE) {
            $id = self::id_de_la_sucursal_del_comprobante($this->documento);

            return is_null($id) ? [] : $this->sucursal_por_id($id);
        }

        if (is_string($key) && preg_match(self::PATRON_DE_SUCURSAL_PUNTUAL, $key, $partes) === 1) {
            return $this->sucursal_por_id((int) $partes[1]);
        }

        return [];
    }

    /**
     * La sucursal del dueño con ese id, en una lista de 0 o 1 elementos. Una sucursal ajena (de otro
     * dueño o de un comprador) o que ya no existe no está en la lista del dueño: no imprime nada.
     *
     * @param int $id
     * @return array
     */
    protected function sucursal_por_id($id)
    {
        $sucursal = $this->sucursales()->first(function ($sucursal) use ($id) {
            return (int) $sucursal->id === (int) $id;
        });

        return is_null($sucursal) ? [] : [$sucursal];
    }

    /**
     * El id de la sucursal del comprobante:
     *  - `sucursal_id` si el objeto lo trae (el pedido online: su `address` es el texto de la entrega
     *    y su sucursal es `orders.address_id`, ver OrderPdfDocument::header_document());
     *  - `address_id` de un modelo (la venta);
     *  - el id de su relación `address` (el objeto del presupuesto, ver BudgetPdfDocument).
     *
     * @param mixed $documento
     * @return int|null
     */
    public static function id_de_la_sucursal_del_comprobante($documento)
    {
        if (! is_object($documento)) {
            return null;
        }

        if (isset($documento->sucursal_id)) {
            return (int) $documento->sucursal_id ?: null;
        }

        if ($documento instanceof Model) {
            $id = $documento->getAttribute('address_id');

            return empty($id) ? null : (int) $id;
        }

        if (isset($documento->address) && is_object($documento->address) && isset($documento->address->id)) {
            return (int) $documento->address->id;
        }

        return null;
    }

    /**
     * Las sucursales del dueño (la principal primero y después por id), consultadas una sola vez.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function sucursales()
    {
        if (is_null($this->sucursales)) {
            $this->sucursales = self::del_dueno($this->owner_id);
        }

        return $this->sucursales;
    }

    /**
     * Las sucursales de un dueño, en el orden en que se imprimen: la principal (`default_address`)
     * primero y después por id. Sin domicilios de compradores (`buyer_id` no nulo).
     *
     * @param int|null $owner_id
     * @return \Illuminate\Support\Collection
     */
    public static function del_dueno($owner_id)
    {
        if (empty($owner_id)) {
            return collect();
        }

        return Address::where('user_id', $owner_id)
            ->whereNull('buyer_id')
            ->orderByRaw('COALESCE(default_address, 0) DESC')
            ->orderBy('id')
            ->get(['id', 'street', 'street_number', 'city', 'province', 'default_address']);
    }
}
