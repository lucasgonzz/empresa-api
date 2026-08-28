<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Address;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ClientImport implements ToCollection, WithMultipleSheets {

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
     * Sucursales (addresses) del usuario indexadas por nombre normalizado.
     * Null hasta que se cargan por primera vez.
     *
     * @var array|null
     */
    private $sucursales_por_nombre = null;

    /**
     * Nombres de sucursal que vinieron en el Excel y no existen en el sistema.
     * La clave es el nombre normalizado (para deduplicar) y el valor es el texto
     * original del Excel, que es el que se le muestra al usuario al finalizar.
     *
     * @var array
     */
    private $sucursales_no_encontradas = [];

    /**
     * Checkbox unico por importacion: que hacer con las celdas VACIAS de las columnas
     * que el usuario mapeo.
     *
     *   false (default): la celda vacia se saltea y el valor que ya tiene el cliente
     *                    queda como estaba. Es el comportamiento de siempre.
     *   true:            la celda vacia ESCRIBE vacio (null) sobre el cliente existente.
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
     * @param int         $hoja                     Indice 0-based de hoja. OPCIONAL: default 0.
     * @param string|null $hoja_nombre              Nombre de la hoja elegida. OPCIONAL: default null.
     * @param bool        $vaciar_valores_en_blanco Checkbox unico de la importacion. OPCIONAL: default false.
     */
    function __construct($columns, $create_and_edit, $start_row, $finish_row, $hoja = 0, $hoja_nombre = null, $vaciar_valores_en_blanco = false) {
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        Log::info($this->columns);
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();

        /*
         * Los tres ultimos parametros son OPCIONALES y con default a proposito:
         * AdminSync\AiExcelImportController construye esta clase con cuatro argumentos y
         * NO se toca en esta mision. Un parametro nuevo sin default seria un
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
     * El sintoma era este: el usuario sube un libro con "Clientes" en la hoja 2 y "Notas"
     * en la 1, elige "Clientes" en el selector del modal, ve el mapeo armado sobre esa
     * hoja, importa — y le quedaban clientes creados con nombre "Los precios de la lista
     * no incluyen IVA". El selector le prometia una decision que el backend ignoraba.
     *
     * ⚠️ CAMBIO DE COMPORTAMIENTO VISIBLE: antes se importaban todas las hojas del libro,
     * ahora se importa una sola (la 0 si nadie elige). Es lo que pide la mision: las hojas
     * no se combinan.
     *
     * El NOMBRE le gana al indice cuando viene, porque el indice lo calcula SheetJS en el
     * navegador y quien lee despues es otra libreria; los dos pueden discrepar. Con clave
     * string, Maatwebsite resuelve por nombre contra el propio libro (`Sheet::byName()`).
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
            'name'                    =>    'nombre',                    
            'phone'                   =>    'telefono',                  
            'address'                 =>    'direccion',                 
            'email'                   =>    'email',                     
            'razon_social'            =>    'razon_social',             
            'cuit'                    =>    'cuit',
            'cuil'                    =>    'cuil',
            'dni'                     =>    'dni',
            'description'             =>    'descripcion',               
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
                    $client = Client::where('user_id', $this->ct->userId());
                    if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                        $client = $client->where('num', ImportHelper::getColumnValue($row, 'numero', $this->columns));
                    } else {
                        $client = $client->where('name', ImportHelper::getColumnValue($row, 'nombre', $this->columns));
                    }
                    $client = $client->first();
                    $this->saveModel($row, $client);
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
                'btn_text'      => 'Actualizar lista de clientes',
                'function_name' => 'update_clients_after_import',
                'btn_variant'   => 'primary',
            ],
        ];


        $user->notify(new GlobalNotification([
            'message_text'              => 'Importacion de Excel finalizada correctamente',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $this->getInfoToShow(),
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }

    /**
     * Bloques informativos que se muestran al terminar la importacion.
     *
     * Hoy el unico bloque es el de sucursales que no existen: si el Excel traia
     * nombres de sucursal que no matchearon con ninguna, el usuario tiene que
     * enterarse, porque esos clientes quedaron sin sucursal asignada.
     *
     * Si no hay nada que informar se devuelve un array vacio: un bloque que diga
     * "0 sucursales no encontradas" es solo ruido.
     *
     * @return array
     */
    function getInfoToShow() {
        $info_to_show = [];

        if (count($this->sucursales_no_encontradas) > 0) {
            $parrafos = array_values($this->sucursales_no_encontradas);

            $parrafos[] = 'Los clientes de esas filas quedaron sin sucursal asignada. '
                . 'Podes crear las sucursales y volver a importar el archivo.';

            $info_to_show[] = [
                'title'    => 'Sucursales que no existen en el sistema',
                'parrafos' => $parrafos,
            ];
        }

        return $info_to_show;
    }

    function saveModel($row, $client) {
        $existing_client = !is_null($client);
        $this->props_vaciadas = [];
        $data = [];
        foreach ($this->props_to_set as $key => $value) {
            $excel_value = ImportHelper::getColumnValue($row, $value, $this->columns);

            if (!is_null($excel_value)) {

                // CUIT y CUIL se normalizan sin guiones, igual que en ClientController.
                if ($key == 'cuit' || $key == 'cuil') {
                    $data[$key] = str_replace('-', '', $excel_value);
                } else {

                    $data[$key] = $excel_value;
                }
            } else if ($this->debeVaciar($value, $key, $existing_client)) {
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
            } else {
                Log::warning('Importacion clientes: condicion frente al iva no reconocida ['.$iva_condition_excel.']');
            }
        } else if ($this->debeVaciarPorAliases($iva_aliases, 'iva_condition_id', $existing_client)) {
            /*
             * clients.iva_condition_id es `int unsigned DEFAULT NULL` y no tiene FK declarada:
             * dejarlo en null es "sin condicion asignada" y no revienta nada en la base.
             */
            $this->marcarVaciada($data, 'iva_condition_id');
        }
        // Provincia y localidad: la localidad se resuelve dentro de su provincia para permitir homónimos.
        $provincia_name = ImportHelper::getColumnValue($row, 'provincia', $this->columns);
        $localidad_name = ImportHelper::getColumnValue($row, 'localidad', $this->columns);
        $provincia_id = null;

        if (!is_null($provincia_name)) {
            $provincia_id = LocalImportHelper::saveProvincia($provincia_name, $this->ct);
            $data['provincia_id'] = $provincia_id;
        } else if ($this->debeVaciar('provincia', 'provincia_id', $existing_client)) {
            $this->marcarVaciada($data, 'provincia_id');
        }

        if (!is_null($localidad_name)) {
            if (!is_null($provincia_id)) {
                $data['location_id'] = LocalImportHelper::saveLocationWithProvincia($localidad_name, $provincia_id, $this->ct);
            } else {
                LocalImportHelper::saveLocation($localidad_name, $this->ct);
                $data['location_id'] = $this->ct->getModelBy('locations', 'name', $localidad_name, true, 'id');
            }
        } else if ($this->debeVaciar('localidad', 'location_id', $existing_client)) {
            $this->marcarVaciada($data, 'location_id');
        }
        if (!is_null(ImportHelper::getColumnValue($row, 'vendedor', $this->columns))) {
            LocalImportHelper::saveSeller(ImportHelper::getColumnValue($row, 'vendedor', $this->columns), $this->ct);
            $data['seller_id'] = $this->ct->getModelBy('sellers', 'name', ImportHelper::getColumnValue($row, 'vendedor', $this->columns), true, 'id');
        } else if ($this->debeVaciar('vendedor', 'seller_id', $existing_client)) {
            $this->marcarVaciada($data, 'seller_id');
        }
        if (!is_null(ImportHelper::getColumnValueByAliases($row, ['tipo_de_precio', 'tipo de precio'], $this->columns))) {
            $tipo_de_precio = ImportHelper::getColumnValueByAliases($row, ['tipo_de_precio', 'tipo de precio'], $this->columns);
            LocalImportHelper::savePriceType($tipo_de_precio, $this->ct);
            $data['price_type_id'] = $this->ct->getModelBy('price_types', 'name', $tipo_de_precio, true, 'id');
        } else if ($this->debeVaciarPorAliases(['tipo_de_precio', 'tipo de precio'], 'price_type_id', $existing_client)) {
            $this->marcarVaciada($data, 'price_type_id');
        }
        /*
         * Sucursal: se resuelve contra las sucursales que YA existen del mismo usuario.
         * A diferencia de vendedor y tipo de precio, aca nunca se crea nada: una sucursal
         * arrastra depositos de stock, cajas e identidad fiscal, y crear una por un error
         * de tipeo del Excel ensucia el sistema de una forma que despues hay que limpiar
         * a mano. Si el nombre no existe se deja el address_id como estaba y se informa
         * al final de la importacion.
         *
         * Si la celda viene vacia o la columna no esta mapeada, getColumnValue devuelve
         * null y no se toca address_id: una importacion de actualizacion sin la columna
         * sucursal no puede desasignar a nadie.
         *
         * La UNICA excepcion es el checkbox de valores en blanco: ahi el usuario mapeo la
         * columna sucursal, la dejo vacia y pidio explicitamente que vacio signifique vacio.
         * Sigue sin tocarse nada si la columna NO esta mapeada.
         */
        $sucursal_name = ImportHelper::getColumnValue($row, 'sucursal', $this->columns);

        if (!is_null($sucursal_name) && $sucursal_name !== '') {
            $sucursal_id = $this->getSucursalId($sucursal_name);

            if (!is_null($sucursal_id)) {
                $data['address_id'] = $sucursal_id;
            } else {
                $this->registrarSucursalNoEncontrada($sucursal_name);
            }
        } else if ($this->debeVaciar('sucursal', 'address_id', $existing_client)) {
            $this->marcarVaciada($data, 'address_id');
        }
        // Log::info('data');
        // Log::info($data);
        if (!is_null($client) && $this->isDataUpdated($client, $data)) {
            Log::info('actualizando cliente '.$client->name);
            $client->update($data);
        } else if (is_null($client) && $this->create_and_edit) {
            if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                $data['num'] = ImportHelper::getColumnValue($row, 'numero', $this->columns);
                Log::info('saco num del excel, data:');
                Log::info($data);
            } else {
                $data['num'] = $this->ct->num('clients');
            }
            $data['user_id'] = $this->ct->userId();
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $client = Client::create($data);
            
            CreditAccountHelper::crear_credit_accounts('client', $client->id);

            Log::info('creando cliente '.$client->name);
        }

        if (!is_null($client)) {
            LocalImportHelper::procesarSaldoImportacion($row, $this->columns, 'client', $client, $existing_client);
        }
    }

    /**
     * Columnas del Excel cuya celda vacia NUNCA vacia el campo, aunque el checkbox
     * este prendido. La clave es la PROPIEDAD del sistema, no la columna.
     *
     *   name -> `clients.name` es NOT NULL en la base. Un update con name = null es un
     *           error de SQL en el medio de la importacion. Ademas es inalcanzable:
     *           checkRow() saltea la fila entera cuando "nombre" viene vacio.
     *
     * @return array
     */
    private function propsQueNuncaSeVacian() {
        return ['name'];
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
     *   2. El cliente YA EXISTE. Al crear no hay nada que vaciar.
     *   3. La columna esta MAPEADA. Este es el punto caro: si el Excel trae 4 columnas y el
     *      sistema tiene 17 propiedades, prender el checkbox no puede vaciar las otras 13.
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
     * (condicion frente al iva, tipo de precio). Alcanza con que UNO este mapeado.
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
     * Se compara contra el valor actual para no escribir un update que no cambia nada
     * (un campo que ya estaba vacio no es un cambio).
     *
     * @param  \App\Models\Client $client
     * @return bool
     */
    private function hayVaciadoEfectivo($client) {
        foreach ($this->props_vaciadas as $prop_key => $marcada) {
            $valor_actual = $client->{$prop_key};

            if (!is_null($valor_actual) && $valor_actual !== '') {
                return true;
            }
        }

        return false;
    }

    function isDataUpdated($client, $data) {
        if ($this->hayVaciadoEfectivo($client)) {
            return true;
        }

        return  (isset($data['name']) && $data['name']                              != $client->name) ||
                (isset($data['phone']) && $data['phone']                            != $client->phone) ||
                (isset($data['address']) && $data['address']                        != $client->address) ||
                (isset($data['email']) && $data['email']                            != $client->email) ||
                (isset($data['razon_social']) && $data['razon_social']              != $client->razon_social) ||
                (isset($data['cuit']) && $data['cuit']                              != $client->cuit) ||
                (isset($data['cuil']) && $data['cuil']                              != $client->cuil) ||
                (isset($data['dni']) && $data['dni']                                != $client->dni) ||
                (isset($data['description']) && $data['description']                != $client->description) ||
                (isset($data['iva_condition_id']) && $data['iva_condition_id']      != $client->iva_condition_id) ||
                (isset($data['provincia_id']) && $data['provincia_id']              != $client->provincia_id) ||
                (isset($data['location_id']) && $data['location_id']                != $client->location_id) ||
                (isset($data['seller_id']) && $data['seller_id']                    != $client->seller_id) ||
                (isset($data['address_id']) && $data['address_id']                  != $client->address_id) ||
                (isset($data['price_type_id']) && $data['price_type_id']            != $client->price_type_id);
    }

    /**
     * Devuelve el id de la sucursal cuyo nombre coincide con el valor del Excel,
     * o null si no existe ninguna.
     *
     * La comparacion es exacta sobre el valor normalizado, no parcial: un LIKE
     * %...% haria que "Centro" matchee "Centro Norte" y el cliente termine en la
     * sucursal equivocada sin que nadie se entere.
     *
     * @param  string $nombre  Nombre de sucursal tal como vino del Excel
     * @return int|null
     */
    private function getSucursalId($nombre) {
        $key = $this->normalizarNombreSucursal($nombre);

        if ($key === '') {
            return null;
        }

        $sucursales = $this->getSucursalesPorNombre();

        if (isset($sucursales[$key])) {
            return $sucursales[$key];
        }

        return null;
    }

    /**
     * Sucursales del usuario indexadas por nombre normalizado.
     *
     * Se cargan una sola vez por importacion: el Excel puede traer miles de filas y
     * la lista de sucursales no cambia en el medio (esta importacion nunca crea una).
     *
     * El nombre de la sucursal vive en addresses.street (ver src/models/address.js
     * en el spa, donde street tiene text: 'Nombre').
     *
     * @return array  [nombre_normalizado => address_id]
     */
    private function getSucursalesPorNombre() {
        if (is_null($this->sucursales_por_nombre)) {
            $this->sucursales_por_nombre = [];

            $addresses = Address::where('user_id', $this->ct->userId())->get();

            foreach ($addresses as $address) {
                $key = $this->normalizarNombreSucursal($address->street);

                /*
                 * Si el usuario tiene dos sucursales con el mismo nombre no hay forma de
                 * saber cual quiso, asi que gana la primera y no se inventa ninguna regla.
                 */
                if ($key !== '' && !isset($this->sucursales_por_nombre[$key])) {
                    $this->sucursales_por_nombre[$key] = $address->id;
                }
            }
        }

        return $this->sucursales_por_nombre;
    }

    /**
     * Guarda un nombre de sucursal que no existe, para informarlo al finalizar.
     *
     * Se deduplica por el nombre normalizado (para que "Sucursal Centro" y
     * "SUCURSAL CENTRO " no aparezcan dos veces) pero se conserva el texto original
     * del Excel, que es el que el usuario va a poder buscar en su archivo.
     *
     * @param  string $nombre
     * @return void
     */
    private function registrarSucursalNoEncontrada($nombre) {
        $key = $this->normalizarNombreSucursal($nombre);

        if ($key === '' || isset($this->sucursales_no_encontradas[$key])) {
            return;
        }

        $this->sucursales_no_encontradas[$key] = $nombre;
    }

    /**
     * Normaliza el nombre de una sucursal para compararlo.
     *
     * Un Excel real trae "Sucursal Centro ", "SUCURSAL CENTRO" y "Sucursal Centro"
     * queriendo decir lo mismo, asi que se compara sin mayusculas, sin espacios
     * sobrantes y sin acentos. El resultado se usa SOLO para comparar: nunca se
     * guarda ni se le muestra al usuario.
     *
     * @param  string|null $value
     * @return string
     */
    private function normalizarNombreSucursal($value) {
        if (is_null($value)) {
            return '';
        }

        $value = mb_strtolower(trim((string) $value), 'UTF-8');

        /*
         * Los acentos se sacan con un mapa explicito y no con iconv //TRANSLIT:
         * iconv depende del locale del server y en algunos entornos devuelve '?'
         * en vez de la letra, que es peor que no normalizar nada.
         */
        $con_acento = ['á', 'é', 'í', 'ó', 'ú', 'à', 'è', 'ì', 'ò', 'ù', 'ä', 'ë', 'ï', 'ö', 'ü', 'â', 'ê', 'î', 'ô', 'û', 'ñ', 'ç'];
        $sin_acento = ['a', 'e', 'i', 'o', 'u', 'a', 'e', 'i', 'o', 'u', 'a', 'e', 'i', 'o', 'u', 'a', 'e', 'i', 'o', 'u', 'n', 'c'];

        $value = str_replace($con_acento, $sin_acento, $value);

        /* "Sucursal  Centro" y "Sucursal Centro" son el mismo nombre. */
        $sin_espacios_repetidos = preg_replace('/\s+/u', ' ', $value);

        /*
         * Con el modificador /u, preg_replace devuelve null si el texto trae UTF-8 invalido, y
         * trim(null) da '' en PHP 7.4: el nombre se ignoraria en silencio, sin matchear y sin
         * reportarse. Por este camino hoy no puede pasar (el mb_strtolower de arriba ya sanea
         * los bytes invalidos, se probo con latin-1 crudo y con secuencias truncadas), asi que
         * esto es un guard y no el arreglo de un bug: queda por si alguien mueve o saca esa
         * linea, porque el modo de falla seria mudo.
         */
        if (!is_null($sin_espacios_repetidos)) {
            $value = $sin_espacios_repetidos;
        }

        return trim($value);
    }
}
