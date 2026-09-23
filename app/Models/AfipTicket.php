<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class AfipTicket extends Model
{
    use SoftDeletes;

    protected $guarded = [];
    // protected $dates = ['cae_expired_at'];
    protected $casts = [
        /**
         * Cast del snapshot de IVA para facilitar consumo desde PDF sin parseo manual repetido.
         */
        'iva_detalle_enviado_json' => 'array',
    ];

    function scopeWithAll($q) {
        $q->with('afip_information', 'afip_tipo_comprobante');
    }

    // Venta a la que pertenece la factura
    function sale() {
        return $this->belongsTo(Sale::class);
    }

    // Movimiento de la N.C de la C/C a la que pertenece la factura 
    function nota_credito() {
        return $this->belongsTo(CurrentAcount::class, 'nota_credito_id');
    }



    /* 
        Notas de credito facturadas (afip_ticket) que tiene una Factura de venta (afip_ticket) 
        Una Factura de venta puede tener muchas notas de credito facturadas

        Cuando creo una nota de credito desde DevolucionesController con AfipNotaCreditoHelper, creo un afip_tikcet
        que pertenece a una factura de venta (una afip_tikcet). 
        Entonces esa factura de venta pasa a tener una nueva nota de credito dentro de sus @nota_credito_afip  

    */
    function nota_credito_afip() {
        return $this->hasMany(AfipTicket::class, 'sale_afip_ticket_id');
    }



    /* 
        Factura (afip_tikcet) a la que pertenece una Nota de credito facturada (afip_ticket)
        Me devuelve la Factura de venta a la que pertenece una Nota de credito facturada
        Es la inversa del metodo nota_credito_afip
    */  
    function sale_afip() {
        return $this->belongsTo(AfipTicket::class, 'sale_afip_ticket_id');
    }


    /*
        Venta (sale) a la que pertenece una nota de credito.
        Puede que varias notas de credito facturadas (afip_ticket) pertenezcan a la misma Sale, 
        pero cada una pertenece a su propia factura de venta (afip_ticket), o a la misma factura de venta 
    */
    function sale_nota_credito() {
        return $this->belongsTo(Sale::class, 'sale_nota_credito_id');
    }

    function afip_information() {
        return $this->belongsTo(AfipInformation::class);
    }

    /**
     * Cache del respaldo de `afip_information` (ver `getAfipInformationAttribute()`).
     *
     * Guarda la "firma" de lo que se busco (cuit, punto de venta y venta del ticket) junto con lo
     * que se encontro —que puede ser null: un "no hay" tambien se recuerda—, para no consultar la
     * base en cada acceso. Un PDF de factura lee `afip_information` decenas de veces.
     *
     * 🔴 NO se guarda con `setRelation()` a proposito: eso deja la relacion como "cargada" y
     * cambia lo que devuelven `toArray()`, `toJson()` y `getRelations()` de un ticket que antes no
     * traia ese dato. El respaldo es de solo lectura y no tiene que dejar rastro en el modelo.
     *
     * @var array|null Con las claves `firma` y `valor`; null mientras no se haya resuelto nada.
     */
    protected $afip_information_de_respaldo = null;

    /**
     * Configuracion fiscal (`AfipInformation`) del emisor de este comprobante, con respaldo para
     * los tickets viejos que no tienen `afip_information_id`.
     *
     * Eloquent usa este accessor cada vez que se lee `$ticket->afip_information`. La relacion
     * `afip_information()` NO cambia: `with('afip_information')`, `load()` y `setRelation()`
     * siguen funcionando igual.
     *
     * Por que existe. Los tickets que emitio codigo anterior a diciembre de 2025 no guardaron la
     * FK `afip_information_id`, y reimprimirlos reventaba con un 500 ("Trying to get property
     * 'iva_condition' of non-object"): el PDF y el calculador de importes asumen que la
     * configuracion siempre esta. Lo que esos tickets SI guardaron es la foto del emisor:
     * `cuit_negocio`, `iva_negocio` y `punto_venta`. Con el cuit y el punto de venta alcanza para
     * volver a encontrar la configuracion.
     *
     * Orden de resolucion:
     *  1. El valor normal de la relacion. Con cualquier ticket que tenga la FK y su fila, esto es
     *     lo unico que pasa: comportamiento identico al de siempre.
     *  2. Si no hay (FK NULL, o FK colgante), la `AfipInformation` con ese cuit y ese punto de
     *     venta. Ver `afip_information_por_cuit_y_punto_venta()` para las reglas.
     *
     * No escribe nada en la base: el respaldo es de solo lectura.
     *
     * @return \App\Models\AfipInformation|null
     */
    public function getAfipInformationAttribute()
    {
        // Camino de siempre: la relacion tal cual (cargada con `with()`, o resuelta por la FK).
        $afip_information = $this->getRelationValue('afip_information');

        if (!is_null($afip_information)) {
            return $afip_information;
        }

        return $this->afip_information_por_cuit_y_punto_venta();
    }

    /**
     * Busca la configuracion fiscal de un ticket sin vinculo por el cuit y el punto de venta que
     * el propio ticket guardo cuando se emitio.
     *
     * Reglas:
     *  - Solo se consulta si el ticket tiene `cuit_negocio` y un `punto_venta` numerico. Un ticket
     *    borrador en memoria no toca la base.
     *  - Si el ticket es de una venta (o una nota de credito de una venta), la busqueda se acota
     *    al duenio de esa venta: dos comercios que comparten base pueden tener el mismo cuit y el
     *    mismo punto de venta, y la configuracion del otro nunca es la respuesta. Si la venta
     *    existe en el ticket pero ya no se puede leer su duenio, no se adivina: devuelve null.
     *    Los tickets sin ninguna venta (pruebas, borradores) se buscan sin acotar.
     *  - 🔴 Exactamente UNA coincidencia. Cero o mas de una devuelve null: nunca se elige "la
     *    primera" entre dos, porque una configuracion equivocada imprime en un comprobante fiscal
     *    la razon social o la condicion de IVA de otro emisor.
     *
     * @return \App\Models\AfipInformation|null
     */
    protected function afip_information_por_cuit_y_punto_venta()
    {
        /** @var string $cuit CUIT del emisor tal como quedo guardado en el ticket. */
        $cuit = trim((string) $this->cuit_negocio);
        /** @var string $punto_venta Punto de venta guardado en el ticket (la columna es varchar). */
        $punto_venta = trim((string) $this->punto_venta);

        // Sin cuit o sin un punto de venta numerico no hay con que buscar (ni una consulta a la base).
        if ($cuit === '' || $punto_venta === '' || !is_numeric($punto_venta)) {
            return null;
        }

        /**
         * @var string $firma Identifica lo que se busco. Si alguno de estos datos cambia en la
         * instancia (o se hace `refresh()`), el cache deja de valer y se vuelve a resolver.
         */
        $firma = $cuit.'|'.(int) $punto_venta.'|'.$this->sale_id.'|'.$this->sale_nota_credito_id;

        if (!is_null($this->afip_information_de_respaldo) && $this->afip_information_de_respaldo['firma'] === $firma) {
            return $this->afip_information_de_respaldo['valor'];
        }

        /** @var \App\Models\AfipInformation|null $resultado Lo que se encontro, o null. */
        $resultado = null;

        // [¿el ticket tiene una venta?, id del duenio de esa venta].
        list($tiene_venta, $duenio_id) = $this->duenio_de_la_venta();

        // Con venta pero sin poder leer su duenio no se adivina: null (ver el docblock).
        if (!$tiene_venta || !is_null($duenio_id)) {

            $consulta = AfipInformation::with('iva_condition')
                                        ->where('cuit', $cuit)
                                        ->where('punto_venta', (int) $punto_venta);

            if ($tiene_venta) {
                $consulta->where('user_id', $duenio_id);
            }

            // Con dos alcanza para saber si es unica: no hace falta traer todas.
            $encontradas = $consulta->limit(2)->get();

            if ($encontradas->count() === 1) {
                $resultado = $encontradas->first();
            } else if ($encontradas->count() > 1) {
                Log::warning(
                    'AfipTicket '.$this->id.': tiene mas de una configuracion fiscal con el cuit '.$cuit.
                    ' y el punto de venta '.(int) $punto_venta.'. No se elige ninguna: el ticket se lee sin '.
                    'configuracion fiscal. Para que salga completo hay que dejar una sola.'
                );
            }
        }

        $this->afip_information_de_respaldo = ['firma' => $firma, 'valor' => $resultado];

        return $resultado;
    }

    /**
     * Duenio de la venta a la que pertenece este ticket, para acotar la busqueda del respaldo.
     *
     * Mira primero la venta del ticket (`sale`) y despues, para las notas de credito, la venta que
     * se acredito (`sale_nota_credito`). Si la relacion ya esta cargada usa ese modelo; si no, lee
     * solo `user_id` con una consulta chica, sin cargar la relacion (mismo motivo que el cache:
     * no dejar rastro en `toArray()`). La venta se lee con `withTrashed()`: el duenio de una venta
     * borrada sigue siendo el mismo.
     *
     * @return array [bool $tiene_venta, int|null $duenio_id]. `tiene_venta` en false = ticket sin
     *               ninguna venta, se busca sin acotar. Con `tiene_venta` en true y `duenio_id`
     *               null, la venta ya no existe: no se puede acotar y tampoco se adivina.
     */
    protected function duenio_de_la_venta()
    {
        // Relacion => columna con su id, en el orden en que se prueban.
        $ventas = ['sale' => 'sale_id', 'sale_nota_credito' => 'sale_nota_credito_id'];

        foreach ($ventas as $relacion => $columna) {

            if ($this->relationLoaded($relacion) && !is_null($this->getRelation($relacion))) {
                return [true, $this->getRelation($relacion)->user_id];
            }

            if (!is_null($this->{$columna})) {
                return [true, Sale::withTrashed()->where('id', $this->{$columna})->value('user_id')];
            }
        }

        return [false, null];
    }

    function afip_tipo_comprobante() {
        return $this->belongsTo(AfipTipoComprobante::class);
    }

    function afip_errors() {
        return $this->hasMany(AfipError::class);
    }

    function afip_observations() {
        return $this->hasMany(AfipObservation::class);
    }
}
