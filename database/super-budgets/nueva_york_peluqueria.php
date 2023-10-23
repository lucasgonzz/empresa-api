<?php 

use Carbon\Carbon;

$model = [
			'client'            => 'Barberia NEW YORK',
            'offer_validity'    => Carbon::now()->addDays(7),
            'hour_price'        => 4000,
            'delivery_time'     => '6 semanas, el tiempo de entrega puede variar dependiendo las revisiones solicitadas por el cliente.',
            'titles'             => [
                [
                    'text' => 'Presupuesto para el desarrollo de Aplicación Web con almacenamiento de datos en la Nube.'
                ],

                // PWA
                [
                    'title' => 'Beneficios de una PWA (Aplicacion Web Progresiva)',
                ],
                [
                    'title' => 'Acceso desde cualquier dispositivo:',
                    'text'  => 'Las PWAs son responsivas y se pueden utilizar en una variedad de dispositivos, incluyendo teléfonos móviles, tabletas y computadoras de escritorio, lo que brinda flexibilidad a los usuarios.',
                ],
                [
                    'title' => 'Menos espacio de almacenamiento:',
                    'text'  => 'Las PWAs no ocupan mucho espacio en el dispositivo del usuario en comparación con las aplicaciones nativas, lo que es beneficioso para dispositivos con capacidad de almacenamiento limitada.',
                ],
                [
                    'title' => 'Actualizaciones automáticas:',
                    'text'  => 'Las PWAs se actualizan automáticamente en segundo plano, lo que garantiza que los usuarios siempre tengan acceso a la última versión de la aplicación sin tener que preocuparse por las actualizaciones manuales.',
                ],
                [
                    'title' => 'Seguridad mejorada:',
                    'text'  => 'Al estar alojadas en servidores seguros y actualizarse automáticamente, las PWAs tienden a ser más seguras y menos susceptibles a vulnerabilidades de seguridad.',
                ],
                [
                    'title' => 'Facilidad de distribución:',
                    'text'  => 'Las PWAs no requieren ser descargadas desde una tienda de aplicaciones, lo que elimina barreras y procesos engorrosos de aprobación de tiendas.',
                ],
                [
                    'title' => 'Costos reducidos:',
                    'text'  => 'Al desarrollar una PWA en lugar de aplicaciones nativas para diferentes plataformas, se reducen los costos de desarrollo y mantenimiento, ya que se comparte gran parte del código y los recursos.',
                ],
                [
                    'title' => 'Interacción más rápida:',
                    'text'  => 'Las PWAs ofrecen una experiencia de usuario más fluida y rápida, lo que puede aumentar la retención de usuarios y la satisfacción.',
                ],
                [
                    'title' => 'Facilidad de compartir:',
                    'text'  => 'Los usuarios pueden compartir enlaces directos a las PWAs con otras personas, lo que promueve la adquisición de nuevos usuarios.',
                ],
                [
                    'title' => 'Compatibilidad multiplataforma:',
                    'text'  => 'Las PWAs son compatibles con varios navegadores, lo que garantiza un amplio alcance entre diferentes sistemas y dispositivos.',
                ],
                [
                    'title' => 'Integración con dispositivos:',
                    'text'  => 'Pueden acceder a características de hardware, como la cámara y el GPS, lo que permite una amplia gama de funcionalidades.',
                ],
                [
                    'title' => 'Experiencia de usuario personalizada:',
                    'text'  => 'Las PWAs pueden utilizar tecnologías como notificaciones push para ofrecer una experiencia altamente personalizada y atractiva.',
                ],

                // Nube
                [
                    'title' => 'Beneficios de la tecnologia en la NUBE',
                ],
                [
                    'title' => 'Escalabilidad:',
                    'text'  => 'Al aprovechar la tecnologia en la nube, la infraestructura de la aplicación puede escalarse fácilmente para manejar un mayor volumen de usuarios o datos sin la necesidad de realizar inversiones significativas en hardware adicional.',
                ],
                [
                    'title' => 'Ahorro de Costos:',
                    'text'  => 'La tecnología en la nube reduce la necesidad de adquirir y mantener costosos servidores y centros de datos físicos, lo que ahorra dinero en infraestructura para el cliente.',
                ],
                [
                    'title' => 'Mantenimiento Simplificado:',
                    'text'  => 'La nube se encarga del mantenimiento de servidores y actualizaciones de software, liberando a tu cliente de estas tareas técnicas.',
                ],
                [
                    'title' => 'Respaldos Automáticos:',
                    'text'  => 'Los datos se respaldan automáticamente en la nube, lo que reduce el riesgo de pérdida de información crítica.',
                ],
                [
                    'title' => 'Acceso Remoto:',
                    'text'  => 'La tecnología en la nube permite el acceso a la aplicación desde cualquier lugar con una conexión a Internet, lo que fomenta la colaboración y el trabajo remoto.',
                ],

                // Modificaciones
                [
                    'title' => 'Modificaciones Futuras y Flexibilidad:',
                ],
                [
                    'title' => 'Escalabilidad Vertical:',
                    'text'  => 'La infraestructura en la nube facilita la incorporación de nuevas características y mejoras a medida que las necesidades cambian, sin afectar negativamente la experiencia del usuario actual.',
                ],
                [
                    'title' => 'Pruebas Continuas:',
                    'text'  => 'Puedes realizar pruebas y desarrollos en entornos de nube separados antes de implementar cambios en la producción, lo que minimiza el riesgo de errores.',
                ],
                [
                    'title' => 'Retención de Datos:',
                    'text'  => 'Las soluciones en la nube ofrecen una forma segura de retener la información existente mientras se realizan actualizaciones y modificaciones en la aplicación.',
                ],
            ],
            'features' => [
                [
                    'title'             => 'Página de Inicio',
                    'items' => [
                        'Esta sera la pagina principal del sitio.',
                        'Aqui los usuarios contaran con un boton "RESERVAR TURNO" para acceder a la página de TURNOS, para dar de alta el turno que necesiten.',
                        'En esta pagina se mostraran las siguientes secciones.',
                    ],
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Sección de "Cortes/trabajos realizados" en la página Inicio',
                    'items' => [
                        'En esta sección, que estara a continuacion del boton "RESERVAR TURNO", se mostraran imagenes de cortes que se hayan realizado.',
                        'Estas imagenes seran DINAMICAS, esto significa que el negocio podra subir o modificar las imagenes desde el panel de administración.',
                    ],
                    'development_time'  => 4,
                ],
                [
                    'title'             => 'Sección "SOBRE NOSOTROS" en la página INICIO',
                    'items' => [
                        'En esta sección, que estara a continuacion de "Cortes/trabajos realizados", se mostrara la informacion del negocio.',
                        'La informacion del negocio comprende:',
                        'Barberos disponibles (Cada uno con su nombre y foto).',
                        'Los dias y horarios de atencion.',
                        'El valor del servicio.',
                        'La direccion del local.',
                        'Todos estos datos son DINAMICOS, es decir que pueden modificarce desde el panel de administración.'
                    ],
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Página de TURNOS',
                    'items' => [
                        'Desde esta página los usuarios podran dar de alta sus turnos.',
                        'Primero se identificaran con su correo electronico. En caso de no estar registrados, se les pedira su nombre y apellido, en caso de que ya sean un usuario registrado, el nombre y apellido se completaran automáticamente.',
                        'Una vez identificados, podran dar de alta su turno completando los siguientes datos:',
                        '1- Seleccionar la fecha.',
                        '2- Seleccionar el barbero.',
                        '3- En base a la información indicada, se mostrarán los turnos disponibles para que el usuario pueda escoger su turno.',
                        'Una vez seleccionado el turno, se notificara automáticamente via email tanto al usuario como al negocio del nuevo turno dado de alta.',
                    ],
                    'development_time'  => 16,
                ],
                [
                    'title'             => 'Panel de administración',
                    'items' => [
                        'El panel de administración solo podra ser accedido por el negocio, protegido con un usuario y contraseña.',
                        'Desde esta sección, el negocio podra editar la siguiente información:',
                        'Imagenes de cortes realizados.',
                        'Informacion del negocio:',
                        'Costo del servicio. (Este dato será utilizado para mostrar el valor del servicio a los usuarios y para confeccionar un resumen de las ganancias generadas).',
                        'Barberos disponibles.',
                        'Direccion del local.',
                        'Dias y horarios de atencion.',
                        'Duracion del servicio.',
                        'En base a los horarios de atencion y la duracion del servicio, se calcularan los turnos disponibles. Por ejemplo, si se coloca un horario de atencion para el dia lunes de 11hs a 12hs y una duracion de 20min, y un usuario quiere dar de alta un turno para el dia lunes, el sistema mostrara los siguientes turnos disponibles: a las 11, a las 11:20 y a las 11:40. Si se coloca la duracion del servicio en 30min, se ofrecerian los siguientes turnos: a las 11 y a las 11:30.',
                        'Desde esta sección tambien podra:',
                        'Ver un calendario con los turnos pendientes (los que fueron dados de alta y no han sido ejecutados).',
                        'Ver un resumen de todos los cortes realizados en una fecha en especifico, o en un rango de fechas seleccionado. En ambos casos de mostrara el total recaudado. Esto servira a modo de cierre de caja seleccionando la fecha del dia corriente, o como resumen de lo recaudado en un mes seleccionando un rango de fechas desde el primer dia del mes hasta el ultimo dia del mes.',
                        'Dar de alta nuevos turnos, del mismo modo que los usuarios desde la página TURNOS.',
                    ],
                    'development_time'  => 24,
                ],
            ],
        ];