<?php

/*
 * Action classifier: real messages that already matter to the person, and
 * what each asks of them (pillars 0 to 3 of docs/human-interaction-pillars.md).
 * A reply goes with almost everything and is not scored.
 */

return [
    // Calls.
    ['ask' => '¿Tienes 15 minutos hoy para una llamada rápida sobre el prepago?', 'classes' => ['call'], 'confidence' => 'sure'],
    ['ask' => 'John, ¿lo vemos mañana en una call con Pablo? Así cerramos precios.', 'classes' => ['call'], 'confidence' => 'sure'],
    ['ask' => 'Necesito media hora contigo esta semana para revisar el roadmap.', 'from' => 'Andrés Gil', 'classes' => ['call'], 'confidence' => 'sure'],
    ['ask' => 'Equipo, el jueves hacemos una reunión para hablar del lanzamiento.', 'classes' => ['call'], 'confidence' => 'doubt'],
    ['ask' => 'Habría que hablarlo con alguien de producto en algún momento.', 'from' => 'Lucía Pons', 'classes' => ['call'], 'confidence' => 'doubt'],
    ['ask' => 'Llámame cuando puedas, es urgente: se ha caído el cobro en Grupo Vela.', 'from' => 'Pablo Ortega', 'classes' => ['call'], 'confidence' => 'sure'],

    // Tasks.
    ['ask' => '¿Me pasas el informe de conversión del Q3 antes del viernes? Lo necesito para el comité.', 'from' => 'Andrés Gil', 'classes' => ['task'], 'confidence' => 'sure'],
    ['ask' => 'Necesito que escribas la user story del paso 3 del checkout para el sprint del lunes.', 'classes' => ['task'], 'confidence' => 'sure'],
    ['ask' => 'Te mando el contrato de Grupo Vela; revísalo y me dices algo antes del miércoles, que firmamos el jueves.', 'from' => 'Pablo Ortega', 'classes' => ['task'], 'confidence' => 'sure'],
    ['ask' => 'Cuando puedas échale un ojo al hilo de #producto.', 'classes' => []],
    ['ask' => 'Habría que documentar el flujo de reembolsos en algún momento.', 'from' => 'Lucía Pons', 'classes' => ['task'], 'confidence' => 'doubt'],
    ['ask' => '¿Puedes preparar la demo para el cliente del martes? Diez minutos, enseñando el prepago.', 'from' => 'Andrés Gil', 'classes' => ['task'], 'confidence' => 'sure'],
    ['ask' => 'Gracias por el PRD, le doy una vuelta y te digo.', 'classes' => []],

    // Questions.
    ['ask' => '¿En qué estado está el prepago? ¿Sale este sprint?', 'from' => 'Andrés Gil', 'classes' => ['question']],
    ['ask' => '¿Dónde está documentado el webhook de pagos?', 'from' => 'Pablo Ortega', 'classes' => ['question']],
    ['ask' => '¿Por qué decidimos quitar el pago con transferencia?', 'from' => 'Lucía Pons', 'classes' => ['question']],
    ['ask' => '¿Cuántos clientes usan ya el prepago?', 'classes' => ['question']],

    // Just a reply.
    ['ask' => '¿Te va bien que lo movamos a las 12?', 'classes' => []],
    ['ask' => 'Ya está desplegado el fix, avísame si ves algo raro.', 'from' => 'Pablo Ortega', 'classes' => []],
    ['ask' => 'Dile a Alba que el comité se mueve al jueves.', 'classes' => []],
    ['ask' => '¡Buen trabajo con la release de ayer!', 'from' => 'Andrés Gil', 'classes' => []],

    // Several at once.
    ['ask' => '¿Cómo va la integración con Stripe? Y mándame el resumen del comité antes del lunes, que lo presento yo.', 'from' => 'Andrés Gil', 'classes' => ['question', 'task'], 'confidence' => 'sure'],
    ['ask' => '¿Sabes si el bug de facturas está resuelto? Si no, hagamos una call mañana con Pablo.', 'classes' => ['question', 'call']],
    ['ask' => 'Revisa la propuesta de precios y lo hablamos el jueves en una llamada de 30 minutos.', 'from' => 'Andrés Gil', 'classes' => ['task', 'call']],
    ['ask' => 'Te lo paso antes del jueves sin falta.', 'from' => 'John León', 'classes' => ['task']],
];
