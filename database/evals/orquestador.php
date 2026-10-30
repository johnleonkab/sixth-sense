<?php

/*
 * The orchestrator: which agents an ask goes to. Scored by the set of agents,
 * not their wording; readers run first and side by side.
 */

return [
    ['ask' => 'Contesta a Marta y díselo a Alba.', 'agents' => ['comunicacion']],
    ['ask' => 'Convoca una reunión con Pablo mañana a las diez.', 'agents' => ['agenda']],
    ['ask' => '¿Qué sabemos del prepago?', 'agents' => ['investigacion']],
    ['ask' => 'Crea una página en Notion con el plan de lanzamiento.', 'agents' => ['redaccion']],
    ['ask' => '¿Cuántos usuarios tuvo la web ayer en Analytics?', 'agents' => ['integraciones']],
    ['ask' => 'Un gráfico de mis reuniones por día este mes.', 'agents' => ['vistas']],
    ['ask' => 'Busca qué se decidió del prepago y contesta a Marta con eso.', 'agents' => ['investigacion', 'comunicacion']],
    ['ask' => 'Mira qué dice el PRD y crea las historias en Jira.', 'agents' => ['investigacion', 'redaccion']],
    ['ask' => 'Busca un hueco con Lucía y escríbele proponiéndolo.', 'agents' => ['agenda', 'comunicacion']],
    ['ask' => 'Responde al email de Andrés y añade la reunión que propone al calendario.', 'agents' => ['comunicacion', 'agenda']],
    ['ask' => 'Reacciona con un ok al mensaje de Pablo.', 'agents' => ['comunicacion']],
    ['ask' => 'Mueve la sync al viernes.', 'agents' => ['agenda']],
    ['ask' => 'Comenta en CPD-412 que falta el ok de Pagos.', 'agents' => ['redaccion']],
    ['ask' => 'Crea en Linear un issue del bug del checkout.', 'agents' => ['integraciones']],
    ['ask' => 'Un donut de los issues de Jira por estado.', 'agents' => ['vistas']],
    ['ask' => 'Prepárame el contexto de Experiences para la reunión de las cinco.', 'agents' => ['investigacion']],
    ['ask' => 'Resume el hilo del lanzamiento y deja un acta en Notion.', 'agents' => ['investigacion', 'redaccion']],
    ['ask' => 'Contesta a todos en el email del contrato que lo vemos el jueves y ponlo en el calendario.', 'agents' => ['comunicacion', 'agenda']],
    ['ask' => '¿Quién lleva la migración?', 'agents' => ['investigacion']],
    ['ask' => 'Dile a Pablo por Slack que la daily se mueve a las diez.', 'agents' => ['comunicacion']],
];
