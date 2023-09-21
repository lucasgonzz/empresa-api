<?php 

use Carbon\Carbon;

$model = [
            'client' => 'Bartolome Kablan',
            'offer_validity'    => Carbon::now()->addDays(7),
            'hour_price'        => 3500,
            'delivery_time'     => '6 semanas, el tiempo de entrega puede variar dependiendo las revisiones solicitadas por el cliente.',
            'titles'             => [
                [
                    'text' => 'Presupuesto para el desarrollo de Aplicación Web con almacenamiento de datos en la Nube.'
                ],
                [
                    'text' => 'La tecnología en la Nube permite acceder la información desde cualquier dispositivo conectado a internet.'
                ],
                [
                    'text' => 'El desarrollo en esta arquitectura permite que se puedan ir haciendo mejoras en el sistema una que vez el cliente comienza a usarlo, estas mejoras a realizarse, las irá identificando el cliente conforme utilice el programa.'
                ],
                [
                    'text' => 'El soporte de servidores corre por nuestra cuenta, con copias de seguridad diarias de la información cargada en el sistema. Soporte para que ingresen los propietarios, empleados del negocio y los clientes, por lo que se cobra un mantenimiento mensual de $1000. Además se brindara un dominio, escogido por el cliente, para que accedan los sus clientes, este dominio tiene un costo de renovación anual de $4000.',
                ],
            ],
            'features'          => [
                [
                    'title'             => 'Dar de alta canchas',
                    'description'       => 'El usuario administrador podrá dar de alta, editar y eliminar canchas dentro del sistema, cada cancha constara de un nombre y una descripción.',
                    'development_time'  => 3,
                ],
                [
                    'title'             => 'Dar de alta Horarios',
                    'description'       => 'El usuario administrador podrá dar de alta, editar y eliminar horarios dentro del sistema, cada horario constara de un nombre y una descripción.',
                    'development_time'  => 3,
                ],
                [
                    'title'             => 'Vincular las canchas con los horarios',
                    'description'       => 'Una vez creada, por ejemplo, la cancha "Futbol" y los horarios "Tarde" y "Noche", podrá vincular la cancha Futbol al horario Tarde, asignando la duración en horas del turno y un precio por la duración asignada, y a la misma cancha vincularla al horario Noche con otro precio distinto. Todas las canchas van a poder vincularse con todos los horarios dados de alta.',
                    'development_time'  => 3,
                ],
                [
                    'title'             => 'Dar de alta turnos para las canchas',
                    'description'       => 'El usuario podrá crear turnos para las canchas, que representaran los alquileres de las mismas. Los pasos para dar de alta un turno son:',
                    'items'             => [
                        'Escoger la cancha y la fecha.',
                        'En base a la cancha y la fecha seleccionadas se mostrarían los horarios/turnos disponibles.',
                        'Una vez seleccionado el horario, podrá seleccionar el método de pago.',
                        'Luego de indicar la información anterior, se dará de alta el turno.'
                    ],
                    'development_time'  => 5,
                ],
                [
                    'title'             => 'Dar de alta productos',
                    'description'       => 'El usuario podrá dar de alta, editar y eliminar productos dentro del sistema, cada producto constara de un nombre, costo, precio y stock. Todo el conjunto de productos conformara el inventario.',
                    'development_time'  => 3,
                ],
                [
                    'title'             => 'Dar de alta descuentos para ventas',
                    'description'       => 'Se podrán crear, editar y eliminar descuentos para asignarles a una nueva venta.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Crear ventas de productos',
                    'description'       => 'El usuario podrá crear ventas, las ventas estaran conformadas por los productos previamente dados de alta, a los cuales indicara la cantidad al mometo de agregar a una venta.',
                    'items'             => [
                        'Las ventas solo estarán conformadas por productos.',
                        'No tendran la posibilidad de asignar un cliente.',
                        'No tendran la posibilidad de imprimirlas.',
                        'Tendran la posibilidad de indicar que empleado la realizo.',
                        'Se le podrán aplicar uno o mas descuentos.',
                    ],
                    'development_time'  => 5,
                ],
                [
                    'title'             => 'Sección CAJA',
                    'description'       => 'Habrá una sección para ver los movimientos de la CAJA, aquí se visualizaran las ventas de la cantina, los turnos dados de alta, y un resumen de los metodos de pago utilizados y los montos para cada método, tanto para la cantina como para los alquileres.',
                    'development_time'  => 5,
                ],
                [
                    'title'             => 'Dar de alta empleados',
                    'description'       => 'Se podrán crear, editar y eliminar empleados, con el fin de darles acceso a las distintas áreas dentro del sistema y que cada uno pueda dejar registro de las ventas que realizo.',
                    'development_time'  => 4,
                ],
                [
                    'title'             => 'Pagina para el ingreso de los clientes y reserva de canchas',
                    'description'       => 'Pagina alojada en un dominio a elección del cliente, por ejemplo "canchas-bartolo.com", a la que ingresaran los clientes, con la opción para reservar una cancha. Los pasos serian:',
                    'items'             => [
                        'Escoger la cancha y la fecha.',
                        'En base a la cancha y la fecha seleccionadas se mostrarían los horarios disponibles.',
                        'Una vez seleccionado el horario, tendrá la única opción de abonar el total con su cuenta de MercadoPago.',
                        'Luego de recibir el pago, se informaría mediante mail al cliente de la correcta reservación de la cancha y se actualizaría la lista de reservas en la parte del negocio.'
                    ],
                    'development_time'  => 8,
                ],
                [
                    'title'             => 'Dar de alta socios y suscripciones',
                    'description'       => 'Se podrán crear, editar y eliminar socios y suscripciones de MercadoPago, con el fin de vincular a un socio con una suscripción para que se le debite automáticamente el monto indicado en la suscripción creada.',
                    'development_time'  => 5,
                ],
            ],
        ];