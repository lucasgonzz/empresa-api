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
