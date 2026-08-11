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

class ClientImport implements ToCollection {

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

    function __construct($columns, $create_and_edit, $start_row, $finish_row) {
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        Log::info($this->columns);
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();
        $this->setProps();
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
        $data = [];
        foreach ($this->props_to_set as $key => $value) {
            if (!is_null(ImportHelper::getColumnValue($row, $value, $this->columns))) {

                // CUIT y CUIL se normalizan sin guiones, igual que en ClientController.
                if ($key == 'cuit' || $key == 'cuil') {
                    $documento = ImportHelper::getColumnValue($row, $value, $this->columns);
                    $documento = str_replace('-', '', $documento);

                    $data[$key] = $documento;
                } else {

                    $data[$key] = ImportHelper::getColumnValue($row, $value, $this->columns);
                }
            }
        }
        $iva_condition_excel = ImportHelper::getColumnValueByAliases($row, [
            'condicion_frente_al_iva',
            'condicion frente al iva',
        ], $this->columns);

        if (!is_null($iva_condition_excel)) {
            $iva_condition_id = LocalImportHelper::getIvaConditionId($iva_condition_excel);

            if (!is_null($iva_condition_id)) {
                $data['iva_condition_id'] = $iva_condition_id;
            } else {
                Log::warning('Importacion clientes: condicion frente al iva no reconocida ['.$iva_condition_excel.']');
            }
        }
        // Provincia y localidad: la localidad se resuelve dentro de su provincia para permitir homónimos.
        $provincia_name = ImportHelper::getColumnValue($row, 'provincia', $this->columns);
        $localidad_name = ImportHelper::getColumnValue($row, 'localidad', $this->columns);
        $provincia_id = null;

        if (!is_null($provincia_name)) {
            $provincia_id = LocalImportHelper::saveProvincia($provincia_name, $this->ct);
            $data['provincia_id'] = $provincia_id;
        }

        if (!is_null($localidad_name)) {
            if (!is_null($provincia_id)) {
                $data['location_id'] = LocalImportHelper::saveLocationWithProvincia($localidad_name, $provincia_id, $this->ct);
            } else {
                LocalImportHelper::saveLocation($localidad_name, $this->ct);
                $data['location_id'] = $this->ct->getModelBy('locations', 'name', $localidad_name, true, 'id');
            }
        }
        if (!is_null(ImportHelper::getColumnValue($row, 'vendedor', $this->columns))) {
            LocalImportHelper::saveSeller(ImportHelper::getColumnValue($row, 'vendedor', $this->columns), $this->ct);
            $data['seller_id'] = $this->ct->getModelBy('sellers', 'name', ImportHelper::getColumnValue($row, 'vendedor', $this->columns), true, 'id');
        }
        if (!is_null(ImportHelper::getColumnValueByAliases($row, ['tipo_de_precio', 'tipo de precio'], $this->columns))) {
            $tipo_de_precio = ImportHelper::getColumnValueByAliases($row, ['tipo_de_precio', 'tipo de precio'], $this->columns);
            LocalImportHelper::savePriceType($tipo_de_precio, $this->ct);
            $data['price_type_id'] = $this->ct->getModelBy('price_types', 'name', $tipo_de_precio, true, 'id');
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
         */
        $sucursal_name = ImportHelper::getColumnValue($row, 'sucursal', $this->columns);

        if (!is_null($sucursal_name) && $sucursal_name !== '') {
            $sucursal_id = $this->getSucursalId($sucursal_name);

            if (!is_null($sucursal_id)) {
                $data['address_id'] = $sucursal_id;
            } else {
                $this->registrarSucursalNoEncontrada($sucursal_name);
            }
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

    function isDataUpdated($client, $data) {
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
         * Con el modificador /u, preg_replace devuelve null si el texto trae UTF-8 invalido.
         * Si eso pasa se sigue con el valor sin colapsar los espacios: se pierde esa tolerancia
         * pero el nombre se compara igual y, si no matchea, se informa. Devolver '' haria que
         * el nombre se ignore en silencio, que es el peor de los dos comportamientos.
         */
        if (!is_null($sin_espacios_repetidos)) {
            $value = $sin_espacios_repetidos;
        }

        return trim($value);
    }
}
