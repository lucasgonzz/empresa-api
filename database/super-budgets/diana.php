<?php 

use Carbon\Carbon;

$model = [
            'client' => 'Comision CEF',
            'offer_validity'    => Carbon::now()->addDays(7),
            'hour_price'        => 3500,
            'delivery_time'     => '4 semanas, el tiempo de entrega puede variar dependiendo las revisiones solicitadas por el cliente.',
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
                    'text' => 'El soporte de servidores corre por nuestra cuenta, con copias de seguridad diarias de la información cargada en el sistema, por lo que se cobra un mantenimiento anual de $4000.',
                ],
            ],
            'features'          => [
                [
                    'title'             => 'Dar de alta socios',
                    'description'       => 'El usuario administrador podrá dar de alta, editar y eliminar socios dentro del sistema, cada socio contara con uno o mas servicios y un historial de pagos.',
                    'development_time'  => 3,
                ],
                [
                    'title'             => 'Dar de alta Servicios',
                    'description'       => 'Los servicios representan las actividades a las que los socios pueden adherir, constaran de un nombre, precio y opcionalmente una descripción.',
                    'development_time'  => 3,
                ],
                [
                    'title'         => 'Imprimir recibos de pago',
                    'description'   => 'Opción para generar un PDF con los datos del Socio y el Servicio que esta pagando.',
                    'development_time'  => 2,
                ],
                [
                    'title'         => 'Imprimir historial de pago de un socio o de todos los socios',
                    'description'   => 'Opción para generar un PDF con una lista de los pagos que ha ido abonando un Socio, o que han ido abonando todos los socios, en un plazo de tiempo dado.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Dar de alta Proveedores con historial de pagos',
                    'description'       => 'El usuario administrador podrá dar de alta, editar y eliminar proveedores dentro del sistema, cada preveedor contara con un historial de compras, cada compra llevara los datos de la factura: fecha de emision, importe, numero de boleta.',
                    'development_time'  => 4,
                ],
            ],
        ];