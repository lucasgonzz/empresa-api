<?php 

use Carbon\Carbon;

$model = [ 
            'client' => 'Scrap Free',
            'offer_validity'    => Carbon::now()->addDays(7),
            'hour_price'        => 3500,
            'delivery_time'     => '10 semanas, el tiempo de entrega puede variar dependiendo las revisiones solicitadas por el cliente.',
            'titles'             => [
                [
                    'text' => 'Presupuesto para el desarrollo de Aplicación Web con almacenamiento de datos en la Nube.'
                ],
                [
                    'text' => 'La tecnología en la Nube permite acceder la información desde cualquier dispositivo conectado a internet.'
                ],
                [
                    'text'  => 'Para que sea mas ameno, solo se detallaran las propiedades de las Entidades que estén vinculadas a otras Entidades.',
                ],
                [
                    'text'  => 'Solo se detallaran la funcionalidades utilizadas por los empleados, faltaría examinar las herramientas utilizadas por el administrador (Mauro), como los mapas de calor y demás para agregarlas al presupuesto.',
                ],
            ],
            'features'          => [
                [
                    'title'             => 'CRUD Asegurados',
                    'description'       => 'Se podrán crear, editar y eliminar Asegurados, para luego vincularlos a un Siniestro.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'CRUD Aseguradoras',
                    'description'       => 'Se podrán crear, editar y eliminar Aseguradoras, para luego vincularlas a un Siniestro.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'CRUD Honorarios de Liquidacion',
                    'description'       => 'Se podrán crear, editar y eliminar Honorarios de Liquidacion, para luego vincularlos a las Aseguradoras.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Unidades de Negocio',
                    'description'       => 'Se podrán crear, editar y eliminar Unidades de Negocio, para luego vincularlos a los Gestores.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Gestores de aseguradoras',
                    'description'       => 'Se podrán crear, editar y eliminar Gestores, para luego vincularlos a una Aseguradora y a un Siniestro.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Gestores de Scrap Free',
                    'description'       => 'Se podrán crear, editar y eliminar Gestores, para luego vincularlos a un Siniestro y a una Unidad de Negocio.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Provincias',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Localidades',
                    'description'       => 'Se podrán crear, editar y eliminar Localidades, para luego vincularlas a una Provincia.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Lineas',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Sub Lineas',
                    'description'       => 'Se podrán crear, editar y eliminar Sub Lineas, para luego vincularlas a una Linea, y luego vincularas a un Bien.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Estados de Bienes',
                    'description'       => 'Se podrán crear, editar y eliminar Estados de Bienes, para luego vincularlos a un Bien.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Causas Bien',
                    'description'       => 'Se podrán crear, editar y eliminar Causas de un Bien, para luego vincularlas a un Bien.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Bienes',
                    'description'       => 'Se podrán crear, editar y eliminar Bienes, para luego vincularlos a:',
                    'items'             => [
                        'Un Siniestro',
                        'Una Linea y SubLinea',
                        'Estado de bien',
                        'Causa Bien',
                        'Tecnico Asegurado',
                        'Tecnico Scrap Free',
                        'Logistica',
                    ],
                    'development_time'  => 4,
                ],
                [
                    'title'             => 'CRUD Polizas',
                    'description'       => 'Se podrán crear, editar y eliminar Polizas, para luego vincularlas a un Asegurado.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'CRUD Coberturas',
                    'description'       => 'Se podrán crear, editar y eliminar Coberturas, para luego vincularlas a una Poliza.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'CRUD Tipos de Orden de Servicio',
                    'description'       => 'Se podrán crear, editar y eliminar Tipos de Orden de Servicio, para luego vincularlas a un Siniestro.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Causas de Siniestro',
                    'description'       => 'Se podrán crear, editar y eliminar Causas de Siniestro, para luego vincularlas a un Siniestro.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Estados de Siniestro',
                    'description'       => 'Se podrán crear, editar y eliminar Estados de Siniestro, para luego vincularlos a un Siniestro.',
                    'items'             => [
                        'El Siniestro pertenecera a un solo Estado por vez.',
                        'No obstante, se guardara registro de todos los estados por los que ha pasado',
                        'Tambien se dejara registro de el tiempo que permanecio en cada estado por los cuales paso.'
                    ],
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'CRUD Siniestros',
                    'description'       => 'Se podrán crear, editar y eliminar Siniestros, este a su vez estará vinculado a las siguientes entidades:',
                    'items'             => [
                        'Aseguradora',
                        'Asegurado',
                        'Causa Siniestro',
                        'Estado Siniestro',
                        'Provincia',
                        'Localidad',
                        'Tipo de Orden de Servicio',
                        'Gestor Aseguradora',
                        'Gestor Scrap Free',
                    ],
                    'development_time'  => 4,
                ],
                [
                    'title'             => 'CRUD Transportistas',
                    'description'       => 'Se podrán crear, editar y eliminar Transportistas, para luego vincularlos a una Logistica.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Logisticas',
                    'description'       => 'Se podrán crear, editar y eliminar Logisticas, para luego vincularlas a un Siniestro o a un Bien. A su vez una Logistica estara vinculada con:',
                    'items'             => [
                        'Transporte Retiro',
                        'Transporte Devolucion',
                        'Siniestro',
                        'Bien',
                    ],
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'CRUD Tecnicos',
                    'description'       => 'Se podrán crear, editar y eliminar Tecnicos, para luego vincularlos a un Informe Tecnico.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Causas Probables',
                    'description'       => 'Se podrán crear, editar y eliminar Causas Probables, para luego vincularlas a un Informe Tecnico.',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'CRUD Informes Tecnicos',
                    'description'       => 'Se podrán crear, editar y eliminar Informes Tecnicos, que estaran vinculados con las siguientes Entidades:',
                    [
                        'Tecnico',
                        'Causa Probable',
                        'Bien',
                        'Siniestro',
                    ],
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Sección Siniestros',
                    'description'       => 'En esta sección se listaran todos los Siniestros en proceso, ordenados por los Estados de Siniestro a los que pertenezcan, con la opción de verlos en detalle y editarlos. Se mostraran las propiedades de cada siniestro junto con la siguiente información:',
                    [
                        'Hace cuantos dias se dio de alta en el sistema.',
                        'Hace cuantos dias esta en el actual estado.',
                    ],
                    'development_time'  => 4,
                ],
                [
                    'title'             => 'Creacion de Plantillas de Emails',
                    'description'       => 'Se podrán dar de alta, editar y eliminar Plantillas de Emails, cada una vinculada a un Estado de Siniestro, para que cada vez que se actualice el Estado de Siniestro de un Siniestro, se procederá a informar mediante email al Asegurado, utilizando la plantilla preformateada del nuevo Estado de Siniestro',
                    'items'             => [
                        'La idea es que se deje asentado un formato de mensaje preestablecido para cada instancia a la que va avanzando un Siniestro.',
                        'Puede que para el primer Estado de Siniestro "Contactar Asegurado" el mensaje preformateado sea: "Buenos dias {asegurado.nombre}, nos contacamos de Scrap Free para informarle que estaremos procesando su siniestro dado de alta en {asegurado.aseguradora.nombre}".',
                        'Así mismo, cuando el Estado de Siniestro se actualice a "Pendiente Informe Tecnico", el mensaje preformateado sera: "Hola {asegurado.nombre}, el tecnico {siniestro.tecnico.nombre} esta evaluando la condicion de su {siniestro.bien.nombre}"',
                    ],
                    'development_time'  => 10,
                ],
                [
                    'title'             => 'Generación automática de PDF',
                    'description'       => 'Podrán generarse documentos PDF, utilizando una plantilla previamente programada, en la cual se detallaría la información que se necesite y el PDF generado se insertaría en un Email. Estas plantillas PDF tendrían que especificarse de antemanto para proceder a su confección y dejarlas listas para su uso. Cada plantilla tendría un tiempo de desarrollo estimado de 1hs.',
                    'development_time'  => 0,
                ],
            ],
        ];