<?php

namespace App\Http\Controllers\Helpers;

use App\Models\PermissionEmpresa;

/**
 * Catálogo único de los permisos de los empleados (misión empleados-duplicar-y-permisos, 29/9/2026).
 *
 * Es la fuente de verdad de tres cosas por cada permiso, identificado por su `slug`:
 *  - el GRUPO en el que aparece en la pantalla de Empleados (`permission_empresas.model_name`),
 *  - el NOMBRE que lee la persona (`permission_empresas.name`),
 *  - el ORDEN en que se muestran (el del propio array: grupos de arriba hacia abajo y, dentro de
 *    cada grupo, entrar -> crear -> editar -> eliminar -> alcance).
 *
 * Por qué existe: antes cada seeder escribía a mano su `name` y su `model_name`, así que había 29
 * grupos para 136 permisos (`Vender`/`ventas`, `Estadisticas` mezclando "Ver la CAJA" con "Ver
 * REPORTES", `articulos` en minúscula...) y el orden salía por `id`, distinto en cada base.
 *
 * 🔴 Reglas que no se rompen (las verificó una auditoría contra todo el código):
 *  - El `slug` NO se toca jamás: es lo que compara `can()` en la SPA, los helpers del asistente y
 *    `RecordatorioCobroController`, y va en los pivots.
 *  - Nunca se borra ni se reinserta una fila: `aplicar()` solo hace UPDATE por slug o crea la que
 *    falta. Los pivots (`permission_empresa_user`) y `DatabaseEmployeeHelper` van por `id`.
 *  - Dos permisos no pueden llamarse igual: el asistente resuelve el permiso por nombre y, si hay
 *    dos iguales, pregunta cuál (lo cuida el test de este catálogo).
 *
 * PHP 7.4: sin argumentos nombrados ni funciones flecha con tipos.
 */
class PermisosCatalogoHelper
{
    /**
     * Grupo de los permisos que hoy no habilitan nada en la interfaz (ni en la SPA ni en el API).
     * Se muestran igual —hay empleados que los tienen tildados y no se borran— pero al final y con
     * el aviso en el nombre del grupo, para que nadie tilde algo esperando un efecto que no existe.
     */
    const GRUPO_SIN_EFECTO = 'Sin efecto por ahora';

    /**
     * Grupos en el orden en que se muestran, cada uno con su lista `slug => nombre`.
     *
     * Para agregar un permiso nuevo: sumalo acá, en el grupo del módulo donde se usa, y corré
     * `PermisosOrdenarYCompletarSeeder` en producción (ver el seeder).
     *
     * @return array
     */
    public static function catalogo()
    {
        return [

            'Vender' => [
                'sale.store'                                    => 'Usar Vender (hacer ventas)',
                'article.vender.change_price'                   => 'Cambiar el precio de un artículo al vender',
                'article.vender.change_name'                    => 'Cambiar el nombre de un artículo al vender',
                'vender.change_employee'                        => 'Cambiar el empleado de la venta',
                'vender.create_article'                         => 'Crear un artículo nuevo desde Vender',
                'vender.article_discount'                       => 'Aplicar descuento a cada artículo',
                'sale.discount_surchage.aplicar'                => 'Aplicar descuentos y recargos a la venta',
                'sale.discount_surchage.crear'                  => 'Crear descuentos y recargos nuevos desde Vender',
                'vender.cambiar_address_id'                     => 'Cambiar la sucursal o el depósito de la venta',
                'vender.limpiar_venta'                          => 'Usar el botón Limpiar venta',
                'vender.discount_stock'                         => 'Elegir si la venta descuenta stock',
                'vender.iva_aplicado'                           => 'Elegir si los precios llevan IVA',
                'vender.prohibir_eliminar_articulos_de_venta'   => 'PROHIBIR sacar artículos de una venta (pide la clave del dueño)',
                'vender.prohibir_camibar_lista_de_precios'      => 'PROHIBIR cambiar la lista de precios al vender',
            ],

            'Ventas' => [
                'sale.index'                                    => 'Ver el listado de ventas',
                'sale.update'                                   => 'Modificar ventas ya cargadas',
                'sale.delete'                                   => 'Eliminar ventas',
                'sale.index.previus_days'                       => 'Ver ventas de días anteriores (sin esto solo ve las de hoy)',
                'sale.index.total'                              => 'Ver el total vendido',
                'sale.index.addresses.all'                      => 'Ver las ventas de todas las sucursales',
                'sale.index.addresses.only_your'                => 'Ver solo las ventas de su sucursal (si tiene también el de todas, gana ese)',
                'sale.index.employees.all'                      => 'Ver las ventas de todos los empleados',
                'sale.index.employees.only_your'                => 'Ver solo sus propias ventas (si tiene también el de todos, gana ese)',
                'devolucion.store'                              => 'Hacer devoluciones',
            ],

            'Artículos (Listado)' => [
                'article.index'                                 => 'Ver el listado de artículos',
                'article.store'                                 => 'Crear artículos',
                'article.update'                                => 'Editar artículos',
                'article.delete'                                => 'Eliminar artículos',
                'article.excel.import'                          => 'Importar artículos desde Excel',
                'article.excel.export'                          => 'Exportar artículos a Excel',
                'article.export_excel_clients'                  => 'Exportar listas de precios a Excel para clientes',
                'article.cost'                                  => 'Ver los costos de los artículos',
                'article.percentage_gain'                       => 'Ver los márgenes de ganancia',
                'article.provider'                              => 'Ver el proveedor de cada artículo',
            ],

            'Stock y depósitos' => [
                'article.edit_stock'                            => 'Modificar el stock',
                'article.edit_stock_only_sucursal'              => 'Modificar el stock solo de su sucursal',
                'article.stock_only_sucursal'                   => 'Ver el stock solo de su sucursal',
                'article.stock_min_max'                         => 'Ver y editar el stock mínimo y máximo',
                /*
                    Movimientos de depósito (misión movimientos-deposito-auditoria, 3/10/2026). Los
                    tres los chequea DepositMovementController (el dueño y admin_access pueden todo).
                    `deposit_movement.update` vivía en "Sin efecto por ahora" y desde esta misión
                    cuenta: deja cambiar todo lo que no son artículos. Los artículos piden
                    `update_articles`, y el botón "Mover stock", `move_stock`.
                */
                'deposit_movement.update'                       => 'Editar movimientos de depósito (estado, depósitos, empleado y notas)',
                'deposit_movement.update_articles'              => 'Editar los artículos de un movimiento de depósito',
                'deposit_movement.move_stock'                   => 'Mover el stock de un movimiento de depósito',
                'deposit_movement.index.previus_days'           => 'Ver movimientos de depósito de días anteriores',
                'deposito_para_checkear'                        => 'Usar Depósito: ventas para chequear',
                'deposito_checkeadas'                           => 'Usar Depósito: ventas chequeadas',
            ],

            'Clientes' => [
                'client.index'                                  => 'Ver clientes',
                'client.store'                                  => 'Crear clientes',
                'client.update'                                 => 'Editar clientes',
                'client.delete'                                 => 'Eliminar clientes',
                'client.excel.import'                           => 'Importar clientes desde Excel',
                'client.excel.export'                           => 'Exportar clientes a Excel',
                'payment_plan.store'                            => 'Crear planes de pago (cuotas)',
                'payment_plan.update'                           => 'Editar planes de pago (cuotas)',
            ],

            'Proveedores y compras' => [
                'provider.index'                                => 'Ver proveedores',
                'provider.store'                                => 'Crear proveedores',
                'provider.update'                               => 'Editar proveedores',
                'provider.delete'                               => 'Eliminar proveedores',
                'provider.excel.import'                         => 'Importar proveedores desde Excel',
                'provider.excel.export'                         => 'Exportar proveedores a Excel',
                'provider_order.index'                          => 'Ver compras (pedidos a proveedor)',
                'provider_order.store'                          => 'Crear compras',
                'provider_order.update'                         => 'Editar compras',
                'provider_order.delete'                         => 'Eliminar compras',
                'provider_order.index.previus_days'             => 'Ver compras de días anteriores',
            ],

            'Presupuestos' => [
                'budget.index'                                  => 'Ver presupuestos',
                'budget.store'                                  => 'Crear presupuestos',
                'budget.update'                                 => 'Editar presupuestos',
                'budget.delete'                                 => 'Eliminar presupuestos',
            ],

            'Cajas y tesorería' => [
                'caja.index'                                    => 'Entrar a Tesorería (ver las cajas)',
                'movimiento_entre_caja.index'                   => 'Ver movimientos entre cajas',
                'movimiento_entre_caja.store'                   => 'Crear movimientos entre cajas',
                'movimiento_entre_caja.update'                  => 'Editar movimientos entre cajas',
                'movimiento_entre_caja.delete'                  => 'Eliminar movimientos entre cajas',
                'movimiento_entre_caja.index.previus_days'      => 'Ver movimientos entre cajas de días anteriores',
                'expense.index'                                 => 'Ver gastos',
                'expense.store'                                 => 'Crear gastos',
                'expense.update'                                => 'Editar gastos',
                'expense.delete'                                => 'Eliminar gastos',
                'reportes.cheques'                              => 'Ver y gestionar cheques (y su gráfico)',
            ],

            'Reportes' => [
                'reportes.index'                                => 'Entrar a Reportes',
                'reportes.cards'                                => 'Ver Estado de Resultados, Flujo de Caja y Posición Fiscal',
                'reportes.graficos'                             => 'Ver los gráficos',
                'reportes.articulos'                            => 'Ver el rendimiento de artículos',
                'reportes.ingresos'                             => 'Ver los ingresos de la empresa',
                'reportes.sucursales.index'                     => 'Ver ventas por sucursal',
                'reportes.sucursales.index.all'                 => 'Reportes: ver todas las sucursales',
                'reportes.sucursales.index.only_your'           => 'Reportes: ver solo su sucursal (si tiene también el de todas, gana ese)',
                'reportes.empleados.index'                      => 'Ver ventas por empleado',
                'reportes.empleados.index.all'                  => 'Reportes: ver todos los empleados',
                'reportes.empleados.index.only_your'            => 'Reportes: ver solo sus ventas (si tiene también el de todos, gana ese)',
                'reportes.gastos'                               => 'Ver el gráfico de gastos',
                'reportes.clientes'                             => 'Ver el gráfico de clientes',
            ],

            'Tienda online' => [
                'order.index'                                   => 'Ver pedidos de la tienda',
                'order.store'                                   => 'Crear pedidos de la tienda',
                'order.update'                                  => 'Editar pedidos de la tienda',
                'order.delete'                                  => 'Eliminar pedidos de la tienda',
                'order.index.previus_days'                      => 'Ver pedidos de la tienda de días anteriores',
                'buyer.index'                                   => 'Ver clientes, mensajes y promociones de la tienda',
                'buyer.store'                                   => 'Crear clientes de la tienda',
                'buyer.update'                                  => 'Editar clientes de la tienda',
                'buyer.delete'                                  => 'Eliminar clientes de la tienda',
                // Los dos que siguen los consulta el menú (router/routes.js) pero ningún seeder los
                // creaba: el ítem no aparecía para ningún empleado sin acceso de administrador.
                'cupon.index'                                   => 'Ver los cupones de la tienda',
                'mercado_libre.orders'                          => 'Usar MercadoLibre',
            ],

            'Alertas y agenda' => [
                'alerts.provider_orders'                        => 'Ver alertas de compras a proveedor',
                'alerts.orders'                                 => 'Ver alertas de pedidos de la tienda',
                'alerts.messages'                               => 'Ver alertas de mensajes de la tienda',
                'alerts.problemas_al_facturar'                  => 'Ver alertas de problemas al facturar',
                'alerts.recordatorio_cobro'                     => 'Mandar recordatorios de cobro por WhatsApp',
                'pending.index'                                 => 'Usar la Agenda',
            ],

            'Entregas y hojas de ruta' => [
                'road_map.index'                                => 'Ver Por entregar y hojas de ruta',
                'road_map.store'                                => 'Crear hojas de ruta',
                'road_map.update'                               => 'Editar hojas de ruta',
                'road_map.delete'                               => 'Eliminar hojas de ruta',
                'road_map.terminadas.index'                     => 'Entrar a Rutas (ver rutas asignadas)',
            ],

            'Producción' => [
                'produccion.index'                              => 'Usar Producción',
                'recipe.index'                                  => 'Ver recetas',
                'recipe.store'                                  => 'Crear recetas',
                'recipe.update'                                 => 'Editar recetas',
                'recipe.delete'                                 => 'Eliminar recetas',
                // El módulo viejo ya no está en el menú, pero sigue ruteado por /produccion.
                'order_production.index'                        => 'Ver órdenes de producción (módulo viejo)',
                'order_production.store'                        => 'Crear órdenes de producción (módulo viejo)',
                'order_production.update'                       => 'Editar órdenes de producción (módulo viejo)',
                'order_production.delete'                       => 'Eliminar órdenes de producción (módulo viejo)',
                'production_movement.index'                     => 'Ver movimientos de producción (módulo viejo)',
                'production_movement.store'                     => 'Crear movimientos de producción (módulo viejo)',
                'production_movement.update'                    => 'Editar movimientos de producción (módulo viejo)',
                'production_movement.delete'                    => 'Eliminar movimientos de producción (módulo viejo)',
            ],

            'Configuración (ABM)' => [
                'abm'                                           => 'Usar el módulo ABM (categorías, marcas, precios, etc.)',
            ],

            /*
                Ningún archivo de la SPA ni del API consulta estos slugs hoy (o solo los consulta un
                componente que no importa nadie). Tildarlos no cambia nada. Se dejan porque hay
                empleados que los tienen asignados, y por si el día de mañana se les da efecto: en
                ese caso se los mueve al grupo de su módulo, sin tocar el slug.
            */
            self::GRUPO_SIN_EFECTO => [
                'caja.reports'                                  => 'Ver la Caja del módulo viejo',
                'caja.charts'                                   => 'Ver las Estadísticas de caja del módulo viejo',
                'reportes'                                      => 'Ver REPORTES (el que vale es "Entrar a Reportes")',
                'reportes.info_facturacion'                     => 'Ver información de facturación',
                'deposit_movement.index'                        => 'Ver movimientos de depósito',
                'deposit_movement.store'                        => 'Crear movimientos de depósito',
                'deposit_movement.delete'                       => 'Eliminar movimientos de depósito',
                'road_map.terminadas.only_your'                 => 'Ver solo sus hojas de ruta',
                'road_map.terminadas.all'                       => 'Ver todas las hojas de ruta',
                'whatsapp.see_owner_chats'                      => 'Ver los chats de WhatsApp del dueño',
                'whatsapp.see_other_users_chats'                => 'Ver los chats de WhatsApp de otros empleados',
                'support.see_owner_chats'                       => 'Ver los chats de soporte del dueño',
                'support.see_other_users_chats'                 => 'Ver los chats de soporte de otros empleados',
            ],
        ];
    }

    /**
     * Sinónimos con los que un comerciante busca cada permiso ("plata" para las cajas, "borrar" para
     * los `.delete`). Viajan en la respuesta de `GET api/permission` como `palabras_clave` (no se
     * guardan en la base) y el buscador de la pantalla de Empleados los suma al nombre y al grupo.
     *
     * Cada fila es `[regex sobre el slug, palabras]` y se acumulan todas las que coincidan. Las
     * palabras van sin tildes y en minúscula; el buscador las normaliza igual.
     *
     * @var array
     */
    const PALABRAS_CLAVE = [
        ['/\.delete$/',                                     'borrar anular quitar eliminar'],
        ['/^sale\.delete$/',                                'anular factura comprobante'],
        ['/\.excel\.import$/',                              'planilla xls xlsx subir cargar'],
        ['/\.excel\.export$/',                              'planilla xls xlsx descargar bajar'],
        ['/^sale\./',                                       'venta ventas factura comprobante'],
        ['/^devolucion\./',                                 'nota de credito cambio reclamo'],
        ['/^vender\.|^article\.vender\./',                  'punto de venta mostrador'],
        ['/^article\.vender\.change_price$|^vender\.prohibir_camibar/', 'precio lista de precios'],
        ['/discount/',                                      'descuento recargo promocion rebaja'],
        ['/^vender\.prohibir_/',                            'bloquear restringir clave'],
        ['/^vender\.prohibir_eliminar/',                    'borrar anular quitar'],
        ['/^vender\.change_employee$|employees\.|^reportes\.empleados/', 'vendedor comision usuario empleado'],
        ['/addresses\.|^reportes\.sucursales|address/',     'sucursal local negocio'],
        ['/^article\./',                                    'producto mercaderia catalogo'],
        ['/^article\.cost$/',                               'costo precio de compra'],
        ['/^article\.percentage_gain$/',                    'margen ganancia rentabilidad utilidad'],
        ['/^article\.provider$/',                           'proveedor'],
        ['/stock|^deposit|^deposito/',                      'inventario existencias mercaderia deposito'],
        ['/^client\./',                                     'cobrar cobranza deuda saldo fiado cuenta corriente'],
        ['/^payment_plan\./',                               'tarjeta financiar cuotas credito'],
        ['/^provider/',                                     'compra compras pedido pedidos mercaderia'],
        ['/^budget\./',                                     'cotizacion presupuesto'],
        ['/^caja\.index$/',                                 'caja cajas plata dinero efectivo arqueo apertura cierre cierre de caja tesoreria'],
        ['/^movimiento_entre_caja|^expense\./',             'plata dinero traspaso transferencia egresos'],
        ['/^reportes\./',                                   'estadisticas informe grafico'],
        ['/^reportes\.cards$/',                             'utilidad rentabilidad ganancia balance resultado'],
        ['/^reportes\.cheques$/',                           'cheque cheques banco'],
        ['/^alerts\.problemas_al_facturar$/',               'afip arca cae factura electronica error'],
        ['/^alerts\.recordatorio_cobro$/',                  'cobrar cobranza deuda cliente fiado mensaje'],
        ['/^alerts\./',                                     'aviso avisos notificacion'],
        ['/^pending\./',                                    'tareas recordatorios agenda'],
        ['/^road_map\./',                                   'envio envios reparto entrega logistica'],
        ['/^order\.|^buyer\.|^cupon\.|^mercado_libre\./',   'ecommerce web online tienda pedidos'],
        ['/^produccion|^recipe|^order_production|^production_movement/', 'fabricar receta elaborar'],
        ['/^abm$/',                                         'configuracion ajustes categorias marcas listas de precios'],
    ];

    /**
     * Palabras clave de un permiso (ver PALABRAS_CLAVE), juntas en un solo texto.
     *
     * @param  string  $slug
     * @return string
     */
    public static function palabras_clave($slug)
    {
        $palabras = [];

        foreach (self::PALABRAS_CLAVE as $regla) {
            if (preg_match($regla[0], (string) $slug)) {
                $palabras[] = $regla[1];
            }
        }

        return implode(' ', $palabras);
    }

    /**
     * Le agrega `palabras_clave` a cada permiso, solo para la respuesta (no se persiste).
     *
     * @param  \Illuminate\Support\Collection  $permisos
     * @return \Illuminate\Support\Collection
     */
    public static function conPalabrasClave($permisos)
    {
        return $permisos->each(function ($permiso) {
            $permiso->setAttribute('palabras_clave', self::palabras_clave($permiso->slug));
        });
    }

    /**
     * Lista plana del catálogo, con la posición de cada permiso. Es lo que usan `aplicar()` y
     * `ordenar()`.
     *
     * @return array  slug => ['grupo' => string, 'nombre' => string, 'orden_grupo' => int, 'orden' => int]
     */
    public static function filas()
    {
        $filas = [];
        $orden = 0;
        $orden_grupo = 0;

        foreach (self::catalogo() as $grupo => $permisos) {
            foreach ($permisos as $slug => $nombre) {
                $filas[$slug] = [
                    'grupo'         => $grupo,
                    'nombre'        => $nombre,
                    'orden_grupo'   => $orden_grupo,
                    'orden'         => $orden,
                ];
                $orden++;
            }
            $orden_grupo++;
        }

        return $filas;
    }

    /**
     * Deja `permission_empresas` alineada con el catálogo: crea los slugs que faltan y le pone a los
     * que ya están el grupo y el nombre del catálogo. Idempotente: correrla dos veces no cambia nada.
     *
     * Es lo que hace falta en producción y no alcanza con cambiar `PermissionSeeder`: solo corre en
     * bases nuevas, y las bases viejas tienen los nombres viejos y, además, catálogos incompletos
     * (medido en copias de producción: entre 8 y 31 permisos menos). Sin los que faltan el dueño no
     * puede dárselos a un empleado aunque la SPA los consulte.
     *
     * Los slugs que NO están en el catálogo (alguno propio de un cliente) no se tocan.
     *
     * @return array  ['creados' => int, 'actualizados' => int]
     */
    public static function aplicar()
    {
        $creados = 0;
        $actualizados = 0;

        foreach (self::filas() as $slug => $fila) {

            $existentes = PermissionEmpresa::where('slug', $slug)->get();

            if (!$existentes->count()) {
                // `forceCreate`: el modelo no declara `$fillable` y este es el único lugar que escribe el catálogo.
                PermissionEmpresa::forceCreate([
                    'slug'          => $slug,
                    'name'          => $fila['nombre'],
                    'model_name'    => $fila['grupo'],
                ]);
                $creados++;
                continue;
            }

            // Si por algún seeder viejo el slug quedó repetido se actualizan todas las filas: los
            // pivots pueden estar colgados de cualquiera de los ids y ninguno se borra.
            foreach ($existentes as $existente) {
                if ($existente->name != $fila['nombre'] || $existente->model_name != $fila['grupo']) {
                    PermissionEmpresa::where('id', $existente->id)->update([
                        'name'          => $fila['nombre'],
                        'model_name'    => $fila['grupo'],
                    ]);
                    $actualizados++;
                }
            }
        }

        return ['creados' => $creados, 'actualizados' => $actualizados];
    }

    /**
     * Ordena los permisos como los tiene que ver la persona: por grupo y, dentro del grupo, por la
     * posición del catálogo. Lo que no está en el catálogo va al final, en el orden de `id`.
     *
     * El orden lo fija el API y no la SPA porque hoy `PermissionEmpresa::all()` sale por `id`, que es
     * distinto en cada base.
     *
     * @param  \Illuminate\Support\Collection  $permisos
     * @return \Illuminate\Support\Collection
     */
    public static function ordenar($permisos)
    {
        $filas = self::filas();
        $fuera_de_catalogo = count($filas) + 1;

        return $permisos->sort(function ($a, $b) use ($filas, $fuera_de_catalogo) {

            $pos_a = isset($filas[$a->slug]) ? $filas[$a->slug]['orden'] : $fuera_de_catalogo;
            $pos_b = isset($filas[$b->slug]) ? $filas[$b->slug]['orden'] : $fuera_de_catalogo;

            if ($pos_a != $pos_b) {
                return $pos_a <=> $pos_b;
            }

            return $a->id <=> $b->id;
        })->values();
    }
}
