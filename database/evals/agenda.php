<?php

/*
 * Agenda: the calendar. A change ends as a proposal; a question about the
 * calendar only reads.
 */

$changes = ['propose_new_event', 'propose_event_invite', 'propose_event_summary', 'propose_event_response', 'propose_event_update', 'propose_event_cancellation', 'schedule_task'];
$reads = ['find_calendar_events', 'show_calendar'];

return [
    ['ask' => 'Convoca una reunión con Marta mañana a las once para revisar el prepago.', 'any_tool' => ['propose_new_event'], 'proposes' => true],
    ['ask' => 'Mueve la sync de producto y ventas al viernes a las doce.', 'any_tool' => ['propose_event_update'], 'proposes' => true],
    ['ask' => 'Cancela la 1:1 con Marta de hoy.', 'any_tool' => ['propose_event_cancellation'], 'proposes' => true],
    ['ask' => 'Invita a Pablo a la revisión del flujo de prepago.', 'any_tool' => ['propose_event_invite'], 'proposes' => true],
    ['ask' => 'Acepta la daily de producto de mañana.', 'any_tool' => ['propose_event_response'], 'proposes' => true],
    ['ask' => 'Dile que no a la reunión de Grupo Vela, que no llego.', 'any_tool' => ['propose_event_response'], 'proposes' => true],
    ['ask' => 'Añade un resumen a la 1:1 con Marta: revisar el paso 3 y cerrar precios.', 'any_tool' => ['propose_event_summary'], 'proposes' => true],
    ['ask' => 'Búscame media hora libre esta semana con Lucía.', 'any_tool' => [...$reads, 'propose_new_event'], 'proposes' => null],
    ['ask' => 'Agéndame una hora para revisar el PRD antes del viernes.', 'any_tool' => ['schedule_task'], 'proposes' => null],
    ['ask' => 'Ponme un bloque de foco de dos horas mañana por la mañana.', 'any_tool' => ['propose_new_event', 'schedule_task'], 'proposes' => null],
    ['ask' => 'Cambia el título de la sync a «Sync ventas».', 'any_tool' => ['propose_event_update'], 'proposes' => true],
    ['ask' => 'Quita a Lucía de la sync de producto y ventas.', 'any_tool' => ['propose_event_update'], 'proposes' => true],
    ['ask' => 'Retrasa media hora la revisión del prepago.', 'any_tool' => ['propose_event_update'], 'proposes' => true],
    ['ask' => '¿Qué tengo mañana por la tarde?', 'any_tool' => $reads, 'no_tool' => $changes, 'proposes' => false],
    ['ask' => '¿Cuándo es mi próxima reunión con Andrés?', 'any_tool' => $reads, 'no_tool' => $changes, 'proposes' => false],
    ['ask' => '¿Tengo algún hueco el jueves a primera hora?', 'any_tool' => $reads, 'no_tool' => $changes, 'proposes' => false],
    ['ask' => '¿Cuántas reuniones tengo esta semana?', 'any_tool' => $reads, 'no_tool' => $changes, 'proposes' => false],
    ['ask' => 'Convoca una reunión de 45 minutos con Pablo y Lucía el lunes a las diez, con Meet.', 'any_tool' => ['propose_new_event'], 'proposes' => true],
    ['ask' => 'Mueve todo lo de mañana por la tarde al jueves.', 'any_tool' => ['propose_event_update'], 'proposes' => true],
    ['ask' => 'Quita del calendario la tarea de revisar el PRD.', 'any_tool' => ['cancel_scheduled_task', 'find_calendar_events'], 'proposes' => null],
];
