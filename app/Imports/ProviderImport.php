<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ProviderImport implements ToCollection, WithMultipleSheets
{

    /**
     * Indice 0-based de la hoja a importar. Default 0 = primera hoja.
     *
     * @var int
     */
    private $hoja = 0;

    /**
     * Nombre de la hoja elegida, cuando el cliente lo manda. Gana sobre el indice.
     *
     * @var string|null
     */
    private $hoja_nombre = null;

    /**
     * Checkbox unico por importacion: que hacer con las celdas VACIAS de las columnas
     * que el usuario mapeo.
     *
     *   false (default): la celda vacia se saltea y el valor que ya tiene el proveedor
     *                    queda como estaba. Es el comportamiento de siempre.
     *   true:            la celda vacia ESCRIBE vacio (null) sobre el proveedor existente.
     *
     * @var bool
     */
    private $vaciar_valores_en_blanco = false;

    /**
     * Propiedades que esta fila vacia a proposito, como set [prop_key => true].
     *
     * Existe SOLO para isDataUpdated(): esa funcion pregunta por isset(), y isset() sobre
     * un null da false, asi que sin este registro un vaciado nunca dispararia el update()
     * y el checkbox no haria absolutamente nada. Se resetea en cada saveModel().
     *
     * @var array
     */
    private $props_vaciadas = [];

    /**
     * @param array       $columns
     * @param bool        $create_and_edit
     * @param int         $start_row
     * @param int|null    $finish_row
     * @param int|null    $provider_id
     * @param int         $hoja                     Indice 0-based de hoja. OPCIONAL: default 0.
     * @param string|null $hoja_nombre              Nombre de la hoja elegida. OPCIONAL: default null.
     * @param bool        $vaciar_valores_en_blanco Checkbox unico de la importacion. OPCIONAL: default false.
     */
    public function __construct($columns, $create_and_edit, $start_row, $finish_row, $provider_id, $hoja = 0, $hoja_nombre = null, $vaciar_valores_en_blanco = false) {
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();
        $this->provider_id = $provider_id;
        $this->provider = null;
        $this->created_models = 0;
        $this->updated_models = 0;

        /*
         * Los tres ultimos parametros son OPCIONALES y con default a proposito:
         * AdminSync\AiExcelImportController construye esta clase con cinco argumentos y NO
         * se toca en esta mision. Un parametro nuevo sin default seria un
         * ArgumentCountError en el endpoint que usa admin-api contra clientes reales.
         */
        $this->hoja = (is_numeric($hoja) && (int) $hoja >= 0) ? (int) $hoja : 0;
        $this->hoja_nombre = (is_string($hoja_nombre) && trim($hoja_nombre) !== '')
                                ? trim($hoja_nombre)
                                : null;
        $this->vaciar_valores_en_blanco = filter_var($vaciar_valores_en_blanco, FILTER_VALIDATE_BOOLEAN);

        $this->setProps();
    }

    /**
     * Hoja (una sola) que Maatwebsite tiene que recorrer.
     *
     * 🔴 SIN este metodo, Maatwebsite NO importa la primera hoja: importa TODAS.
     * `Reader::loadSpreadsheet()` hace
     * `if (!$import instanceof WithMultipleSheets) { $this->sheetImports = array_fill(0, $this->spreadsheet->getSheetCount(), $import); }`,
     * o sea le aplica el MISMO mapeo de columnas a cada hoja del libro y llama
     * `collection()` una vez por hoja. Medido con un libro de tres hojas: tres llamadas.
     *
     * El sintoma: el usuario elige "Proveedores" en el selector del modal, ve el mapeo
     * armado sobre esa hoja, importa — y le quedaban proveedores creados con el texto de
     * la hoja de notas. El selector le prometia una decision que el backend ignoraba.
     *
     * ⚠️ CAMBIO DE COMPORTAMIENTO VISIBLE: antes se importaban todas las hojas del libro,
     * ahora se importa una sola (la 0 si nadie elige). Es lo que pide la mision: las hojas
     * no se combinan.
     *
     * El NOMBRE le gana al indice cuando viene, porque el indice lo calcula SheetJS en el
     * navegador y quien lee despues es otra libreria; los dos pueden discrepar.
     *
     * @return array
     */
    public function sheets(): array {
        if (!is_null($this->hoja_nombre)) {
            return [$this->hoja_nombre => $this];
        }

        return [$this->hoja => $this];
    }

    function setProps() {
        $this->props_to_set = [
            'num'               => 'numero',
            'name'              => 'nombre',
            'phone'             => 'telefono',
            'address'           => 'direccion',
            'location_id'       => 'localidad',
            'email'             => 'email',
            'razon_social'      => 'razon_social',
            'cuit'              => 'cuit',
            'observations'      => 'observaciones',
        ];
    }

    function checkRow($row) {
        return !is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns));
    }

    public function collection(Collection $rows) {
        $this->num_row = 1;
        if (is_null($this->finish_row) || $this->finish_row == '') {
            $this->finish_row = count($rows);
        } 
        foreach ($rows as $row) {
            if ($this->num_row >= $this->start_row && $this->num_row <= $this->finish_row) {
                if ($this->checkRow($row)) {
                    $provider = Provider::where('user_id', $this->ct->userId());
                    if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                        $provider = $provider->where('num', ImportHelper::getColumnValue($row, 'numero', $this->columns));
                    } else {
                        $provider = $provider->where('name', ImportHelper::getColumnValue($row, 'nombre', $this->columns));
                    }
                    $provider = $provider->first();
                    $this->saveModel($row, $provider);
                }
            } else if ($this->num_row > $this->finish_row) {
                break;
            }
            $this->num_row++;
        }

        $this->enviar_notificacion();
    }

    function enviar_notificacion() {
            
        $user = User::find(UserHelper::userId());

        $functions_to_execute = [
            [
                'btn_text'      => 'Actualizar lista de proveedores',
                'function_name' => 'update_provider_after_import',
                'btn_variant'   => 'primary',
            ],
        ];

        $user->notify(new GlobalNotification([
            'message_text'              => 'Importacion de Excel finalizada correctamente',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => [],
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }

    function saveModel($row, $provider) {
        $existing_provider = !is_null($provider);
        $this->props_vaciadas = [];
        $data = [];
        foreach ($this->props_to_set as $key => $value) {
            $excel_value = ImportHelper::getColumnValue($row, $value, $this->columns);

            if (!is_null($excel_value)) {
                $data[$key] = $excel_value;
            } else if ($this->debeVaciar($value, $key, $existing_provider)) {
                $this->marcarVaciada($data, $key);
            }
        }
        $iva_aliases = [
            'condicion_frente_al_iva',
            'condicion frente al iva',
        ];

        $iva_condition_excel = ImportHelper::getColumnValueByAliases($row, $iva_aliases, $this->columns);

        if (!is_null($iva_condition_excel)) {
            $iva_condition_id = LocalImportHelper::getIvaConditionId($iva_condition_excel);

            if (!is_null($iva_condition_id)) {
                $data['iva_condition_id'] = $iva_condition_id;
            }
        } else if ($this->debeVaciarPorAliases($iva_aliases, 'iva_condition_id', $existing_provider)) {
            /*
             * providers.iva_condition_id es `int DEFAULT '0'` y acepta null: no hay FK
             * declarada contra iva_conditions, asi que null es "sin condicion asignada".
             */
            $this->marcarVaciada($data, 'iva_condition_id');
        }
        /*
         * OJO con la asimetria contra ClientImport: aca 'localidad' YA esta en
         * props_to_set (mapeada a location_id), asi que el vaciado lo resolvio el foreach
         * de arriba y este bloque no necesita un else. Si se le agregara uno, se marcaria
         * dos veces la misma propiedad.
         */
        if (!is_null(ImportHelper::getColumnValue($row, 'localidad', $this->columns))) {
            LocalImportHelper::saveLocation(ImportHelper::getColumnValue($row, 'localidad', $this->columns), $this->ct);
            $data['location_id'] = $this->ct->getModelBy('locations', 'name', ImportHelper::getColumnValue($row, 'localidad', $this->columns), true, 'id');
        }
        // Log::info('data');
        // Log::info($data);
        if (!is_null($provider) && $this->isDataUpdated($provider, $data)) {
            Log::info('actualizando proveedor '.$provider->name);
            $provider->update($data);
        } else if (is_null($provider) && $this->create_and_edit) {
            if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                $data['num'] = ImportHelper::getColumnValue($row, 'numero', $this->columns);
            } else {
                $data['num'] = $this->ct->num('providers');
            }
            $data['user_id'] = $this->ct->userId();
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $provider = Provider::create($data);
            Log::info('se creo proveedor '.$provider->name.' con la data: ');
            Log::info($data);
        }
        LocalImportHelper::setSaldoInicial($row, $this->columns, 'provider', $provider);
    }

    /**
     * Propiedades que NUNCA se vacian, aunque el checkbox este prendido.
     *
     *   name -> `providers.name` es NOT NULL en la base. Un update con name = null es un
     *           error de SQL. Ademas es inalcanzable: checkRow() saltea la fila cuando
     *           "nombre" viene vacio.
     *   num  -> es el IDENTIFICADOR con el que collection() busca el proveedor. Vaciarlo
     *           le borra el numero al proveedor y lo vuelve imposible de matchear en la
     *           proxima importacion. Es la diferencia con ClientImport, donde 'numero' se
     *           usa para matchear pero no esta en props_to_set.
     *
     * @return array
     */
    private function propsQueNuncaSeVacian() {
        return ['name', 'num'];
    }

    /**
     * Anota en $data que esta propiedad se vacia, y la registra para isDataUpdated().
     *
     * @param  array  $data  Se modifica por referencia
     * @param  string $prop_key
     * @return void
     */
    private function marcarVaciada(&$data, $prop_key) {
        $data[$prop_key] = null;
        $this->props_vaciadas[$prop_key] = true;
    }

    /**
     * ¿Hay que escribir vacio en $prop_key porque la celda de $column_key vino vacia?
     *
     * Las tres condiciones, y las tres importan:
     *   1. El checkbox de la importacion esta prendido.
     *   2. El proveedor YA EXISTE. Al crear no hay nada que vaciar.
     *   3. La columna esta MAPEADA. Este es el punto caro: si el Excel trae 4 columnas y el
     *      sistema tiene 9 propiedades, prender el checkbox no puede vaciar las otras 5.
     *      getColumnValue() devuelve null tanto para "columna sin mapear" como para "celda
     *      vacia", asi que sin isIgnoredColumn() los dos casos serian indistinguibles.
     *
     * @param  string $column_key  Clave de la columna en el mapeo (ej: 'telefono')
     * @param  string $prop_key    Propiedad del sistema (ej: 'phone')
     * @param  bool   $existing    Si el modelo ya existia
     * @return bool
     */
    private function debeVaciar($column_key, $prop_key, $existing) {
        return $this->debeVaciarPorAliases([$column_key], $prop_key, $existing);
    }

    /**
     * Igual que debeVaciar() pero para las columnas que se mapean con varios alias
     * (condicion frente al iva). Alcanza con que UNO este mapeado.
     *
     * @param  array  $column_keys
     * @param  string $prop_key
     * @param  bool   $existing
     * @return bool
     */
    private function debeVaciarPorAliases(array $column_keys, $prop_key, $existing) {
        if (!$this->vaciar_valores_en_blanco || !$existing) {
            return false;
        }

        if (in_array($prop_key, $this->propsQueNuncaSeVacian(), true)) {
            return false;
        }

        foreach ($column_keys as $column_key) {
            if (!ImportHelper::isIgnoredColumn($column_key, $this->columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Alguno de los vaciados de esta fila cambia algo de verdad?
     *
     * Va aparte de la lista de isset() de abajo porque isset() sobre null da false: sin
     * esto, un vaciado nunca dispararia el update() y el checkbox seria decorativo.
     *
     * @param  \App\Models\Provider $provider
     * @return bool
     */
    private function hayVaciadoEfectivo($provider) {
        foreach ($this->props_vaciadas as $prop_key => $marcada) {
            $valor_actual = $provider->{$prop_key};

            if (!is_null($valor_actual) && $valor_actual !== '') {
                return true;
            }
        }

        return false;
    }

    function isDataUpdated($provider, $data) {
        if ($this->hayVaciadoEfectivo($provider)) {
            return true;
        }

        return  (isset($data['name']) && $data['name']                              != $provider->name) ||
                (isset($data['phone']) && $data['phone']                            != $provider->phone) ||
                (isset($data['address']) && $data['address']                        != $provider->address) ||
                (isset($data['email']) && $data['email']                            != $provider->email) ||
                (isset($data['razon_social']) && $data['razon_social']              != $provider->razon_social) ||
                (isset($data['cuit']) && $data['cuit']                              != $provider->cuit) ||
                (isset($data['observations']) && $data['observations']                != $provider->observations) ||
                (isset($data['iva_condition_id']) && $data['iva_condition_id']      != $provider->iva_condition_id) ||
                (isset($data['location_id']) && $data['location_id']                != $provider->location_id);
    }
}
