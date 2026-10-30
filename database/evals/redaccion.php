<?php

/*
 * Redacción: long documents, Notion pages, Jira issues and Archive files. What
 * it writes is a proposal.
 */

$writes = ['propose_document_edit', 'propose_document_comment', 'propose_comment_reply', 'propose_notion_page', 'propose_notion_edit', 'propose_notion_comment', 'propose_jira_issue', 'propose_jira_update', 'propose_jira_comment', 'propose_jira_dependency', 'propose_sense_file', 'propose_sense_delete'];

return [
    ['ask' => 'Crea una página en Notion con el plan de comunicación del lanzamiento.', 'any_tool' => ['propose_notion_page'], 'proposes' => true],
    ['ask' => 'Añade al plan de lanzamiento en Notion una sección de riesgos.', 'any_tool' => ['propose_notion_edit'], 'proposes' => true],
    ['ask' => 'Comenta en la página del lanzamiento que Riesgos lo cierra el jueves.', 'any_tool' => ['propose_notion_comment'], 'proposes' => true],
    ['ask' => 'Crea una historia en Jira para el reembolso parcial automático.', 'any_tool' => ['propose_jira_issue'], 'proposes' => true],
    ['ask' => 'Crea una épica para Season Pass con tres historias.', 'any_tool' => ['propose_jira_issue'], 'proposes' => true],
    ['ask' => 'Sube la prioridad de CPD-412 a alta.', 'any_tool' => ['propose_jira_update'], 'proposes' => true],
    ['ask' => 'Comenta en CPD-405 que falta el ok de Pagos.', 'any_tool' => ['propose_jira_comment'], 'proposes' => true],
    ['ask' => 'Marca que CPD-412 depende de CPD-405.', 'any_tool' => ['propose_jira_dependency'], 'proposes' => true],
    ['ask' => 'Cambia el título del PRD del prepago a «Prepago v4».', 'any_tool' => ['propose_document_edit'], 'proposes' => true],
    ['ask' => 'Añade al PRD un apartado de métricas de éxito.', 'any_tool' => ['propose_document_edit'], 'proposes' => true],
    ['ask' => 'Comenta en el PRD que confirmemos con Pagos el paso 5.', 'any_tool' => ['propose_document_comment'], 'proposes' => true],
    ['ask' => 'Responde al comentario de Pablo en el PRD que sí, sigue en el paso 5.', 'any_tool' => ['propose_comment_reply'], 'proposes' => true],
    ['ask' => 'Escribe en el Archivo una nota con las decisiones de hoy sobre precios.', 'any_tool' => ['propose_sense_file'], 'proposes' => true],
    ['ask' => 'Actualiza la ficha del proyecto prepago en el Archivo con la nueva fecha.', 'any_tool' => ['propose_sense_file'], 'proposes' => true],
    ['ask' => 'Borra del Archivo la nota vieja de precios de 2025.', 'any_tool' => ['propose_sense_delete'], 'proposes' => true],
    ['ask' => 'Redacta un one-pager del Season Pass en Notion.', 'any_tool' => ['propose_notion_page'], 'proposes' => true],
    ['ask' => 'Convierte las notas de la reunión de hoy en historias de Jira.', 'any_tool' => ['propose_jira_issue'], 'proposes' => true],
    ['ask' => 'Reescribe el resumen del PRD para que sea más corto.', 'any_tool' => ['propose_document_edit'], 'proposes' => true],
    ['ask' => 'Añade a CPD-412 los criterios de aceptación del reembolso.', 'any_tool' => ['propose_jira_update'], 'proposes' => true],
    ['ask' => 'Deja en Notion un acta de la reunión de producto con los próximos pasos.', 'any_tool' => ['propose_notion_page', 'propose_notion_edit'], 'proposes' => true],
];
