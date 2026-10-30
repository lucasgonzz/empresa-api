<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\User;
use App\Services\Mostrador\RecolectorStock;
use Carbon\Carbon;

/**
 * "¿CUÁLES SON LOS QUE MENOS ROTAN?" — LA HERRAMIENTA consultar_articulos_sin_rotacion (misión
 * agente-mejoras-uso-real, E2 punto 5, 29/10/2026).
 *
 * 🔴 EL CASO REAL. Pablo (Ferre Total, CONV 11) pidió «el top ten con menos rotación». El agente no
 * tenía con qué contestar: armó un ranking a mano con resumir_datos sobre los renglones de venta
 * (que solo ve lo que SÍ se vendió), dijo «cuatro artículos» y nombró seis, reescribió los nombres
 * («HºCARROCERA» → «H° carrocera», «90 º 60 X 60» → «90° 60x60») y cuando Pablo preguntó «¿pero son
 * productos que alguna vez compré? ¿y tienen stock?» no encontró las fichas de dos de ellos.
 *
 * La respuesta sale del MISMO cálculo que el informe de Stock del Mostrador
 * (RecolectorStock::sin_rotacion_con_opciones()): artículos activos con stock > 0 y sin ventas reales
 * en la ventana, con el valor a costo en pesos de cada uno y el valor inmovilizado de TODOS. Lo que
 * agrega esta herramienta:
 *  - la ventana elegible (30, 60, 90 o 180 días; el default del Mostrador, 90, no cambia);
 *  - el tope (hasta 100) y el orden (valor, días sin venta o stock);
 *  - `solo_comprados`: solo los que alguna vez se le compraron a un proveedor;
 *  - por artículo, su `article_id` (para pedir la ficha sin reescribir el nombre), cuándo se vendió
 *    por última vez (o si nunca), cuándo se compró y cuántas compras tiene;
 *  - `incluir_poco_vendidos`: si los que no se vendieron nada no llegan al tope, se completa con los
 *    que MENOS unidades vendieron en la ventana (con stock), aparte y dicho como tal.
 *
 * Es de LECTURA: no toca nada. Corre sin Auth (el dueño viene explícito), como todas las del registro.
 *
 * PHP 7.4: sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class ArticulosSinRotacionIaHelper
{
    /** Las ventanas que se pueden pedir. La primera que no es ninguna de éstas cae al default. */
    const DIAS_VALIDOS = [30, 60, 90, 180];

    /** El default: el mismo del Mostrador (RecolectorStock::DIAS_SIN_ROTACION). */
    const DIAS_POR_DEFECTO = RecolectorStock::DIAS_SIN_ROTACION;

    /** Cuántos se listan si no se pide otra cosa ("el top ten"). */
    const LIMITE_POR_DEFECTO = 10;

    /** Techo duro de la lista: la respuesta viaja entera en cada vuelta del loop. */
    const LIMITE_MAXIMO = 100;

    /** Los órdenes válidos. */
    const ORDENES = ['valor', 'dias', 'stock'];

    /**
     * La herramienta.
     *
     * @param  int  $owner_id  Dueño. Nunca Auth.
     * @param  array  $input  dias, limite, orden, solo_comprados, incluir_poco_vendidos.
     * @return array<string, mixed>
     */
    public static function consultar($owner_id, array $input)
    {
        $owner = User::find((int) $owner_id);

        if (is_null($owner)) {

            return ['error' => 'No se encontró el negocio.'];
        }

        $dias = isset($input['dias']) ? (int) $input['dias'] : self::DIAS_POR_DEFECTO;

        if (!in_array($dias, self::DIAS_VALIDOS, true)) {

            $dias = self::DIAS_POR_DEFECTO;
        }

        $limite = isset($input['limite']) ? (int) $input['limite'] : self::LIMITE_POR_DEFECTO;

        if ($limite <= 0) {

            $limite = self::LIMITE_POR_DEFECTO;
        }

        $limite = min($limite, self::LIMITE_MAXIMO);

        $orden = isset($input['orden']) ? (string) $input['orden'] : 'valor';

        if (!in_array($orden, self::ORDENES, true)) {

            $orden = 'valor';
        }

        $solo_comprados = !empty($input['solo_comprados']) && filter_var($input['solo_comprados'], FILTER_VALIDATE_BOOLEAN);
        $incluir_poco_vendidos = !empty($input['incluir_poco_vendidos']) && filter_var($input['incluir_poco_vendidos'], FILTER_VALIDATE_BOOLEAN);

        $hoy = Carbon::today();
        $recolector = new RecolectorStock();

        $sin_rotacion = $recolector->sin_rotacion_con_opciones($owner, $hoy, [
            'dias'           => $dias,
            'limite'         => $limite,
            'orden'          => $orden,
            'solo_comprados' => $solo_comprados,
            'con_detalle'    => true,
            'con_imagenes'   => false,
        ]);

        $articulos = [];

        foreach ($sin_rotacion['lista'] as $fila) {

            $articulos[] = [
                'article_id'      => (int) $fila['article_id'],
                'nombre'          => (string) $fila['nombre'],
                'stock'           => (float) $fila['stock_total'],
                'dias_sin_venta'  => $fila['dias_sin_venta'],
                'ultima_venta'    => $fila['ultima_venta'],
                'nunca_se_vendio' => (bool) $fila['nunca_se_vendio'],
                'ultima_compra'   => $fila['ultima_compra'],
                'compras'         => (int) $fila['compras'],
                'valor_a_costo'   => $fila['valor_a_costo'],
            ];
        }

        $respuesta = [
            'dias'                      => $dias,
            'desde'                     => $hoy->copy()->subDays($dias)->format('Y-m-d'),
            'criterio'                  => 'Artículos activos con stock mayor a 0 y sin ninguna venta real (sin ventas borradas ni las contenedoras de una consolidación AFIP) en los últimos ' . $dias . ' días' . ($solo_comprados ? ', que alguna vez se le compraron a un proveedor' : '') . '. Es el mismo cálculo que el informe de Stock del Mostrador.',
            'orden'                     => $orden,
            'sin_rotacion_encontrados'  => (int) $sin_rotacion['cantidad'],
            'sin_rotacion_en_esta_lista' => count($articulos),
            'valor_inmovilizado'        => (float) $sin_rotacion['valor_inmovilizado'],
            'articulos'                 => $articulos,
            'nota'                      => 'valor_inmovilizado es el valor a costo, en pesos, de TODOS los sin rotación (no solo los listados). Para la ficha, el stock por sucursal o las compras de uno de estos, pasá su article_id a la herramienta (articulo_id): no reescribas el nombre.',
        ];

        /*
         * Si los que no se vendieron nada no llegan al tope y se pidió, se completa con los que menos
         * vendieron, APARTE: "no se vendió nada" y "se vendió poco" son dos cosas distintas y el
         * dueño tiene que saber cuál es cuál.
         */
        if ($incluir_poco_vendidos && count($articulos) < $limite) {

            $poco = $recolector->menos_vendidos_con_stock($owner, $hoy, [
                'dias'           => $dias,
                'limite'         => $limite - count($articulos),
                'solo_comprados' => $solo_comprados,
                'excluir'        => array_column($articulos, 'article_id'),
            ]);

            $respuesta['poco_vendidos'] = $poco;
            $respuesta['nota_poco_vendidos'] = 'poco_vendidos SÍ se vendieron en la ventana, pero poco: son los de menos unidades vendidas (con stock), de menor a mayor. Decilo así, separados de los que no se vendieron nada.';
        }

        return $respuesta;
    }
}
