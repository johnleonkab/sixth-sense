<?php

/*
 * Comunicación: Slack and Gmail. A reply or a message to someone else ends as
 * a proposal; reading never proposes.
 */

$reply = ['propose_slack_message', 'propose_slack_reaction', 'propose_email_reply', 'propose_new_email', 'propose_email_forward'];
$slack = ['propose_slack_message', 'propose_slack_reaction'];
$mail = ['propose_email_reply', 'propose_new_email', 'propose_email_forward'];
$reads = ['show_slack_unread', 'show_slack_conversation', 'search_slack_messages', 'search_emails', 'show_email_results', 'open_email', 'list_alerts', 'review_alert'];

return [
    ['ask' => 'Contesta a Marta en Slack que lo reviso esta tarde.', 'any_tool' => $slack, 'proposes' => true],
    ['ask' => 'Dile a Pablo por Slack que la daily se mueve a las diez.', 'any_tool' => $slack, 'proposes' => true],
    ['ask' => 'Reacciona con un ok al último mensaje de Lucía.', 'any_tool' => $slack, 'proposes' => true],
    ['ask' => 'Responde a Andrés que el contrato lo firmo mañana a primera hora.', 'any_tool' => $mail, 'proposes' => true],
    ['ask' => 'Responde a todos en el email del contrato que lo vemos el jueves.', 'any_tool' => ['propose_email_reply'], 'proposes' => true],
    ['ask' => 'Reenvía a Pablo el email de Marta sobre el prepago.', 'any_tool' => ['propose_email_forward'], 'proposes' => true],
    ['ask' => 'Escríbele un email a Lucía preguntando cuándo cerramos la migración.', 'any_tool' => ['propose_new_email'], 'proposes' => true],
    ['ask' => 'Marta me pide que le diga algo a Alba: díselo a Alba y a Marta dile que vale.', 'any_tool' => $slack, 'proposes' => true],
    ['ask' => 'Contesta al aviso pendiente de Slack con lo que se proponía.', 'any_tool' => $reply, 'proposes' => true],
    ['ask' => 'Contesta al hilo de #producto diciendo que ya está desplegado.', 'any_tool' => $slack, 'proposes' => true],
    ['ask' => 'Avisa en el canal de producto de que mañana no hay daily.', 'any_tool' => $slack, 'proposes' => true],
    ['ask' => 'Dale las gracias a Pablo por el informe, con un mensaje corto.', 'any_tool' => $slack, 'proposes' => true],
    ['ask' => 'Pregúntale a Lucía por Slack si puede revisar el PR hoy, y etiqueta a Pablo.', 'any_tool' => ['propose_slack_message'], 'proposes' => true],
    ['ask' => '¿Qué me ha dicho Marta hoy en Slack?', 'any_tool' => $reads, 'no_tool' => $reply, 'proposes' => false],
    ['ask' => '¿Tengo algún email de Andrés sin contestar?', 'any_tool' => $reads, 'no_tool' => $reply, 'proposes' => false],
    ['ask' => 'Resúmeme el hilo del lanzamiento en Slack.', 'any_tool' => $reads, 'no_tool' => $reply, 'proposes' => false],
    ['ask' => '¿Qué avisos tengo pendientes de responder?', 'any_tool' => ['list_alerts', 'review_alert'], 'no_tool' => $reply, 'proposes' => false],
    ['ask' => 'Contesta a Julián que no llegamos al lunes y proponle el jueves.', 'any_tool' => $reply, 'proposes' => true],
    ['ask' => 'Responde a Marta en el email del prepago que me parece bien el paso 3.', 'any_tool' => $mail, 'proposes' => true],
    ['ask' => 'Escribe a Pablo por privado que lo hablamos en la 1:1.', 'any_tool' => $slack, 'proposes' => true],
];
