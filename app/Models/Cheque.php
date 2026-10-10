<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cheque extends Model
{
    protected $guarded = [];

    public $dates = [
        'fecha_emision',
        'fecha_pago',
    ];

    /*
     * `cheque_banco` y `endosado_en_expense.expense_concept` se suman desde la misión
     * cheques-endoso-y-bancos (21/9/2026): la SPA muestra el nombre del banco del catálogo y, en la
     * solapa Endosados, el gasto (número y concepto) cuando el cheque se endosó en un gasto y no a un
     * proveedor.
     */
    function scopeWithAll($q) {
        $q->with('client', 'provider', 'cobrado_por', 'rechazado_por', 'endosado_a_provider', 'endosado_desde_client', 'cheque_banco', 'endosado_en_expense.expense_concept');
    }

    /**
     * Columnas del listado de cheques que la tabla MUESTRA distinto de como están guardadas, con la
     * expresión SQL de lo que se ve. ColumnFiltersHelper::apply() la usa para la lupa (que contenga,
     * igual que, en blanco / no en blanco) y para las flechas de orden de esa columna, en vez de la
     * columna cruda (misión cheques-filtro-banco-catalogo, 10/10/2026). Ver el docblock de
     * ColumnFiltersHelper: el mecanismo es genérico y solo aplica a filtros de tipo text / textarea.
     *
     * `banco`: la columna "Banco" de Tesorería → Cheques muestra el nombre del banco del catálogo
     * (`cheque_bancos.name`) si el cheque tiene `cheque_banco_id` apuntando a un banco que existe y
     * cuyo nombre no es vacío; si no, el texto libre `cheques.banco`. Hasta esta misión el filtro y
     * el orden iban sobre el texto, y después de que el asistente unifica los bancos (asigna el id y
     * no toca el texto) lo que se veía y lo que se filtraba no coincidían: "Banco BSAS" se ve "Banco
     * Provincia de Buenos Aires" y filtrar "Provincia" no lo encontraba.
     *
     * 🔴 LA REGLA ESTÁ REPETIDA EN TRES LUGARES Y SE CAMBIAN JUNTOS:
     *  - `cheque_banco_texto` de empresa-spa (src/mixins/model_functions.js): lo que se ve.
     *  - esta expresión: lo que filtra y ordena la lupa.
     *  - ChequesFilteredExport (app/Exports): lo que sale en el Excel.
     *
     * Por qué así:
     *  - Subconsulta correlacionada y no JOIN (mismo criterio que el orden por relación de
     *    ColumnFiltersHelper::apply_order_filter()): no choca con withAll() ni con el `select *` del
     *    listado y no multiplica filas en la paginación.
     *  - SIN scope por `user_id` en la subconsulta, a propósito: la relación cheque_banco() —que es
     *    lo que la tabla muestra— tampoco lo tiene, y el filtro tiene que espejar lo que se ve. El
     *    scope por dueño lo pone el listado sobre `cheques`.
     *  - `NULLIF(..., '')`: un banco con nombre vacío no se muestra (la SPA mira `name` como
     *    verdadero), así que cae al texto, igual que un id que no existe (la subconsulta da NULL).
     *  - `CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci` en LAS DOS ramas: `cheque_bancos`
     *    nació el 21/9/2026 con la collation de Laravel y `cheques` puede ser mucho más vieja en una
     *    base de producción; un COALESCE de dos columnas con collations distintas da "Illegal mix of
     *    collations" (500). Con las dos explícitas e iguales no puede pasar, y como es la collation
     *    de la conexión (config/database.php) "Ríos" / "rios" se siguen encontrando igual que antes.
     *  - Es código, no dato: nada del pedido entra a esta expresión.
     *
     * @return array<string, string>  columna => expresión SQL de lo que la tabla muestra.
     */
    public static function columnas_mostradas_para_filtros()
    {
        $nombre_del_catalogo = 'NULLIF(CONVERT((SELECT cheque_bancos.name FROM cheque_bancos'
            . ' WHERE cheque_bancos.id = cheques.cheque_banco_id LIMIT 1) USING utf8mb4)'
            . ' COLLATE utf8mb4_unicode_ci, \'\')';

        $texto = 'CONVERT(cheques.banco USING utf8mb4) COLLATE utf8mb4_unicode_ci';

        return [
            'banco' => 'COALESCE(' . $nombre_del_catalogo . ', ' . $texto . ')',
        ];
    }

    function endosado_desde_client() {
        return $this->belongsTo(Client::class, 'endosado_desde_client_id');
    }

    /**
     * En la copia EMITIDA que nace de un endoso, el cheque recibido del que salió.
     */
    function endosado_desde_cheque() {
        return $this->belongsTo(Cheque::class, 'endosado_desde_cheque_id');
    }

    function current_acount() {
        return $this->belongsTo(CurrentAcount::class);
    }

    /**
     * El gasto en el que se cargó este cheque (nuevo o endosado).
     */
    function expense() {
        return $this->belongsTo(Expense::class);
    }

    /**
     * En un cheque RECIBIDO, el gasto en el que se endosó. Es la segunda forma de salir de cartera
     * (la primera es `endosado_a_provider`): por eso "sigue en cartera" se pregunta siempre por
     * ChequeHelper::en_cartera() / sin_endosar(), nunca mirando una sola de las dos columnas.
     */
    function endosado_en_expense() {
        return $this->belongsTo(Expense::class, 'endosado_en_expense_id');
    }

    /**
     * 🔴 La relación se llama `cheque_banco` y NUNCA `banco`: toArray() mergea las relaciones sobre
     * los atributos, y una relación `banco` pisaría el texto legacy `cheques.banco` en el JSON que
     * lee la SPA anterior, el Excel y el mostrador.
     */
    function cheque_banco() {
        return $this->belongsTo(ChequeBanco::class);
    }

    function cobrado_por() {
        return $this->belongsTo(User::class, 'cobrado_por_id');
    }

    function rechazado_por() {
        return $this->belongsTo(User::class, 'rechazado_por_id');
    }

    function client() {
        return $this->belongsTo(Client::class);
    }

    function provider() {
        return $this->belongsTo(Provider::class);
    }

    function endosado_a_provider() {
        return $this->belongsTo(Provider::class, 'endosado_a_provider_id');
    }

    function caja() {
        return $this->belongsTo(Caja::class);
    }
}
