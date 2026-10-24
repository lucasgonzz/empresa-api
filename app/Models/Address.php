<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Address extends Model
{
    protected $guarded = [];

    /** Columna del depósito madre (misión deposito-madre). */
    const COLUMNA_MADRE = 'es_deposito_madre';

    /**
     * Memo de la guarda de esquema de COLUMNA_MADRE. Solo se memoiza el SÍ (ver
     * columna_madre_existe()).
     *
     * @var bool|null
     */
    private static $columna_madre_existe = null;

    /**
     * ¿La tabla addresses ya tiene es_deposito_madre?
     *
     * 🔴 POR QUÉ HAY GUARDA DE ESQUEMA (mismo criterio que AjusteDePreciosDeSucursalHelper): un
     * deploy de empresa sube los archivos ANTES de migrar. En esa ventana (o si el migrate se
     * traba) el cliente tiene este código sin la columna, y nombrarla a secas era un 500 en el
     * alta y la edición de sucursales, y una excepción en deposito_madre_de(), que tumbaba las
     * sugerencias de stock y la carpeta Stock del mostrador. Sin la columna, todo se comporta
     * como sin madre: el comportamiento histórico.
     *
     * Memoizada con un Schema::hasColumn() por proceso, con una diferencia a propósito respecto
     * del helper del ajuste: se memoiza solo el SÍ. El motor de sugerencias corre en la cola, y un
     * queue:work booteado DENTRO de la ventana se quedaría para siempre "sin madre"; así, mientras
     * la columna no existe se vuelve a preguntar (es una ventana de minutos), y en cuanto existe
     * queda fija. Los tests llaman a olvidar_esquema() después de esconder y devolver la columna.
     *
     * @return bool
     */
    public static function columna_madre_existe()
    {
        if (self::$columna_madre_existe !== true) {
            self::$columna_madre_existe = Schema::hasColumn('addresses', self::COLUMNA_MADRE);
        }

        return self::$columna_madre_existe;
    }

    /**
     * Borra el memo de la guarda (lo usan los tests que esconden la columna).
     *
     * @return void
     */
    public static function olvidar_esquema()
    {
        self::$columna_madre_existe = null;
    }

    /**
     * Unicidad del depósito madre (misión deposito-madre, 2/10/2026): un comercio tiene UNA sola
     * sucursal madre, y marcar otra desmarca la anterior.
     *
     * Vive en el modelo y no en AddressController para que la regla valga por CUALQUIER camino
     * que escriba una sucursal: el ABM, el asistente IA (que llama al mismo controller pero podría
     * no hacerlo mañana), los seeders y los tests.
     *
     * - `saving`: una dirección de COMPRADOR (buyer_id no nulo, las escribe tienda-api en la misma
     *   tabla) nunca queda como madre: se fuerza a 0 antes de escribir. Se eligió forzarlo acá y no
     *   rechazarlo en el controller porque el controller no es el único que escribe, y porque un
     *   domicilio de comprador no es una sucursal: no hay nada que avisarle a nadie.
     * - `saved`: si el registro quedó como madre, se apagan las OTRAS del mismo user_id que hoy
     *   tengan el tilde (0, 1 o, con un dato viejo, 2 filas), guardando cada una POR MODELO. Por
     *   modelo y no con un update() por query a propósito (post-chequeo del 2/10/2026): el update
     *   del builder no dispara eventos de Eloquent y el desmarque quedaba fuera de la auditoría
     *   de cambios (AuditLogRecorder). No recursiona: el hook de la otra sucursal corre con el
     *   valor en 0 y corta en la primera línea.
     *
     * @return void
     */
    protected static function booted()
    {
        static::saving(function ($address) {
            if (!is_null($address->buyer_id) && (int) $address->es_deposito_madre === 1) {
                $address->es_deposito_madre = 0;
            }

            // Un domicilio de comprador tampoco es nunca la sucursal principal (D2, misión
            // sucursal-siempre): misma regla y mismo motivo que el depósito madre.
            if (!is_null($address->buyer_id) && (int) $address->default_address === 1) {
                $address->default_address = 0;
            }
        });

        static::saved(function ($address) {
            if ((int) $address->es_deposito_madre !== 1 || is_null($address->user_id)) {
                return;
            }

            $otras = static::where('user_id', $address->user_id)
                ->where('id', '!=', $address->id)
                ->where('es_deposito_madre', 1)
                ->get();

            foreach ($otras as $otra) {
                $otra->es_deposito_madre = 0;
                $otra->save();
            }
        });

        /*
         * Unicidad de la sucursal PRINCIPAL (misión sucursal-siempre, 23/10/2026, D2): la columna
         * `default_address` ("Depósito por defecto" en el ABM) pasa a ser LA sucursal principal del
         * comercio, y es una sola. Marcar una desmarca las otras del mismo dueño, igual que el depósito
         * madre de arriba y por los mismos motivos (por modelo, para que quede en la auditoría; no
         * recursiona porque el hook de la otra corre con el valor en 0).
         *
         * Lo que NO hace, a propósito: marcar sola una sucursal nueva cuando el dueño no tiene ninguna
         * marcada. `principal_de()` ya resuelve ese caso (madre o la de menor id) y marcarla acá
         * convertiría la primera sucursal de cualquier alta en "con marcas", que en la eliminación de
         * sucursales pide elegir reemplazo (D9 de eliminar-sucursal-con-stock). La marca explícita la
         * ponen el user setup, la migración de datos y el comando `sucursales:migrar-a-principal`.
         */
        static::saved(function ($address) {
            if ((int) $address->default_address !== 1 || is_null($address->user_id) || !is_null($address->buyer_id)) {
                return;
            }

            /*
             * Solo cuando ESTE guardado marcó la principal (alta o cambio de la columna). Sin esto, el
             * hook del depósito madre de arriba —que guarda por modelo la sucursal que deja de ser
             * madre— disparaba este hook en esa otra sucursal (que seguía marcada como principal) y
             * desmarcaba a la que se acababa de marcar: pasaba al eliminar una sucursal con las tres
             * marcas y pasarlas al reemplazo (D9 de eliminar-sucursal-con-stock).
             */
            if (!$address->wasRecentlyCreated && !$address->wasChanged('default_address')) {
                return;
            }

            $otras = static::where('user_id', $address->user_id)
                ->whereNull('buyer_id')
                ->where('id', '!=', $address->id)
                ->where('default_address', 1)
                ->get();

            foreach ($otras as $otra) {
                $otra->default_address = 0;
                $otra->save();
            }
        });
    }

    /**
     * Las sucursales de verdad de un comercio: del dueño (`user_id`) y nunca un domicilio de comprador
     * de la tienda (`buyer_id` nulo: la tabla es compartida con tienda-api). Ordenadas por id.
     *
     * @param int $user_id Dueño del comercio
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function sucursales_de($user_id)
    {
        return static::where('user_id', (int) $user_id)
            ->whereNull('buyer_id')
            ->orderBy('id');
    }

    /**
     * La sucursal PRINCIPAL de un comercio (misión sucursal-siempre, D2), o null si no tiene ninguna
     * sucursal.
     *
     * Es la sucursal que usa cualquier operación que no dice cuál: el motor de stock con un movimiento
     * sin sucursal sobre un artículo que reparte (D3), la materialización del stock global (D4), los
     * caminos que escriben "el stock global" cuando el negocio tiene una sola sucursal (D15).
     *
     * Orden, siempre determinístico:
     *  1. La marcada como principal (`default_address = 1`). Si por un dato viejo hubiera más de una,
     *     la de menor id (el hook de `booted()` impide que vuelva a pasar con el próximo guardado).
     *  2. El depósito madre (`deposito_madre_de()`, con su guarda de esquema).
     *  3. La de menor id: la más vieja del comercio.
     *
     * @param int|null $user_id Dueño del comercio
     * @return Address|null
     */
    public static function principal_de($user_id)
    {
        if (is_null($user_id)) {
            return null;
        }

        $marcada = static::sucursales_de($user_id)
            ->where('default_address', 1)
            ->first();

        if (!is_null($marcada)) {
            return $marcada;
        }

        $madre = static::deposito_madre_de($user_id);

        if (!is_null($madre)) {
            return $madre;
        }

        return static::sucursales_de($user_id)->first();
    }

    /**
     * La ÚNICA sucursal del comercio, o null si tiene cero o más de una (misión sucursal-siempre, D15).
     *
     * Con una sola sucursal la interfaz se comporta como "sin sucursales" (D1): la SPA manda el stock
     * global como siempre y la API lo escribe en esta sucursal. Con dos o más, los caminos del stock
     * global se quedan como estaban.
     *
     * @param int|null $user_id
     * @return Address|null
     */
    public static function unica_sucursal_de($user_id)
    {
        if (is_null($user_id)) {
            return null;
        }

        $sucursales = static::sucursales_de($user_id)->limit(2)->get();

        return count($sucursales) === 1 ? $sucursales[0] : null;
    }

    /**
     * Lo que hay hoy en la fila de un artículo (o de una variante) en una sucursal, leído de la base
     * (suma de las filas del par, que la tabla no tiene único). 0 si no tiene fila.
     *
     * @param int      $article_id
     * @param int      $address_id
     * @param int|null $article_variant_id  Con valor, lee `address_article_variant`.
     * @return float
     */
    public static function stock_en_fila($article_id, $address_id, $article_variant_id = null)
    {
        if (!is_null($article_variant_id)) {
            return (float) \Illuminate\Support\Facades\DB::table('address_article_variant')
                ->where('article_variant_id', (int) $article_variant_id)
                ->where('address_id', (int) $address_id)
                ->sum('amount');
        }

        return (float) \Illuminate\Support\Facades\DB::table('address_article')
            ->where('article_id', (int) $article_id)
            ->where('address_id', (int) $address_id)
            ->sum('amount');
    }

    /**
     * ¿La línea de esta sucursal en el encabezado de un PDF de venta repetiría la dirección de la
     * empresa? (misión sucursal-siempre, 23/10/2026, §9 del plan). Al crear la sucursal principal se
     * le precarga el domicilio (`street_number`) con `users.address_company` (D8), así que SalePdf y
     * NewSalePdf imprimían la misma dirección dos veces. Si el domicilio de la sucursal es textualmente
     * el de la empresa (sin los espacios de los bordes y sin distinguir mayúsculas), la línea de la
     * sucursal no se imprime. Cualquier otra diferencia se imprime como siempre: no se toca otra
     * lógica del encabezado.
     *
     * Vive acá y no en SalePdf porque cargar SalePdf desde NewSalePdf vuelve a incluir fpdf.php y
     * redefine sus constantes.
     *
     * @param  Address      $address
     * @param  string|null  $address_company
     * @return bool
     */
    public static function repite_la_direccion_de_la_empresa($address, $address_company)
    {
        $empresa = mb_strtolower(trim((string) $address_company));

        if ($empresa === '' || is_null($address)) {
            return false;
        }

        return mb_strtolower(trim((string) $address->street_number)) === $empresa;
    }

    /**
     * Deja marcada UNA sola principal para el comercio, si tiene sucursales (D2). Si no hay ninguna
     * marcada marca la que devuelve `principal_de()` (madre o menor id); si hay más de una, deja la de
     * menor id. Idempotente. No crea sucursales.
     *
     * La usan el user setup, el setup de la demo y el comando `sucursales:migrar-a-principal`.
     *
     * @param int|null $user_id
     * @return Address|null La principal, o null si el comercio no tiene sucursales.
     */
    public static function asegurar_principal($user_id)
    {
        $principal = static::principal_de($user_id);

        if (is_null($principal)) {
            return null;
        }

        if ((int) $principal->default_address !== 1) {
            $principal->default_address = 1;
            // El hook desmarca cualquier otra que hubiera quedado prendida.
            $principal->save();
        } else {
            // Ya marcada: igual se apagan las otras de un dato viejo (el hook solo corre al guardar).
            $otras = static::sucursales_de($user_id)
                ->where('id', '!=', $principal->id)
                ->where('default_address', 1)
                ->get();

            foreach ($otras as $otra) {
                $otra->default_address = 0;
                $otra->save();
            }
        }

        return $principal->fresh();
    }

    /**
     * El depósito madre de un comercio, o null si no marcó ninguno. Es la ÚNICA lectura del madre
     * que usan el motor de sugerencias, el ranking de prioridades y el recolector del mostrador:
     * que los tres resuelvan igual es lo que evita que el informe muestre un orden y la sugerencia
     * guardada otro.
     *
     * Siempre una sucursal del propio comercio (user_id) y nunca un domicilio de comprador de la
     * tienda (buyer_id nulo). Si por un dato viejo quedara más de una prendida, gana la de menor id
     * (determinístico; el hook de arriba impide que vuelva a pasar con el próximo guardado).
     *
     * Sin la columna todavía (deploy a medio migrar, ver columna_madre_existe()) devuelve null:
     * todo sigue como sin madre.
     *
     * @param int $user_id Dueño del comercio
     * @return Address|null
     */
    public static function deposito_madre_de($user_id)
    {
        if (is_null($user_id) || !self::columna_madre_existe()) {
            return null;
        }

        return static::where('user_id', $user_id)
            ->whereNull('buyer_id')
            ->where('es_deposito_madre', 1)
            ->orderBy('id')
            ->first();
    }

    function scopeWithAll($q) {
        // Carga la identidad fiscal por defecto de la sucursal (para remitos
        // en negro) y las afip_information disponibles (con su condicion IVA)
        // para el select del form de sucursal.
        $q->with(['default_afip_information.iva_condition', 'afip_informations.iva_condition']);
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount');
    }

    /**
     * afip_information registrados para esta sucursal (afip_informations.address_id).
     * Se usan como opciones del select "facturacion por defecto" y para el filtrado en el modulo de vender.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function afip_informations()
    {
        return $this->hasMany('App\\Models\\AfipInformation', 'address_id');
    }

    /**
     * afip_information por defecto para ventas en negro desde esta sucursal.
     * Si es null, la resolución de identidad fiscal cae a user->afip_information.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function default_afip_information()
    {
        return $this->belongsTo('App\\Models\\AfipInformation', 'default_afip_information_id');
    }
}
