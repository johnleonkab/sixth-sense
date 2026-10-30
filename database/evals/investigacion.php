<?php

/*
 * Investigación: gathers context and answers with facts and their source. It
 * only reads, so it never proposes anything.
 */

$sources = ['search_knowledge', 'read_knowledge', 'search_jira', 'open_jira_issue', 'search_notion', 'open_notion_page', 'search_documents', 'find_files', 'open_document', 'list_sense_files', 'read_sense_file', 'search_emails', 'open_email', 'search_slack_messages', 'show_slack_conversation', 'find_calendar_events'];
$never = ['propose_slack_message', 'propose_email_reply', 'propose_new_event', 'propose_document_edit', 'propose_jira_issue', 'propose_notion_page'];

return array_map(fn (string $ask): array => ['ask' => $ask, 'any_tool' => $sources, 'no_tool' => $never, 'proposes' => false], [
    '¿Qué sabemos del flujo de prepago?',
    '¿En qué estado está CPD-412?',
    '¿Quién lleva la migración y cuándo cierra?',
    '¿Qué se decidió en la última reunión de producto?',
    '¿Dónde está el PRD del prepago?',
    'Resume lo que hay en Notion sobre el plan de lanzamiento.',
    '¿Qué issues de Jira están bloqueados en el proyecto CPD?',
    '¿Qué le dijo Andrés del contrato por email?',
    '¿De qué se habló en #producto esta semana sobre el despliegue?',
    '¿Qué pone el archivo de trabajo sobre las prioridades del trimestre?',
    '¿Qué riesgos tiene el lanzamiento según la documentación?',
    '¿Cuándo es la próxima reunión con Grupo Vela y qué hay que preparar?',
    '¿Qué comentarios abiertos tiene el documento del prepago?',
    '¿Qué dependencias tiene la épica del prepago?',
    '¿Quién es Lucía y en qué proyectos está?',
    'Busca todo lo que haya sobre reembolsos parciales.',
    '¿Qué dijo Pablo sobre precios en Slack?',
    '¿Qué falta para cerrar CPD-405?',
    'Hazme un resumen del contexto de Experiences antes de mi reunión.',
    '¿Hay algún documento reciente sobre el onboarding de clientes?',
]);
