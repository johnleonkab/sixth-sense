<?php

/*
 * Vistas: composes OpenUI views with real data, validated before they are
 * shown. It never proposes changes.
 */

return array_map(fn (string $ask): array => ['ask' => $ask, 'any_tool' => ['compose_view'], 'proposes' => false], [
    'Un gráfico de mis reuniones por día este mes.',
    'Las horas de reunión por calendario esta semana.',
    'Un donut de los issues de Jira del proyecto CPD por estado.',
    'Los issues de Jira actualizados por día en las últimas dos semanas.',
    'Mis avisos de la semana por fuente.',
    'Cuántos avisos tengo pendientes, en cifras.',
    'Una tabla con los documentos más consultados del índice de conocimiento.',
    'El índice de conocimiento por fuente, en un gráfico.',
    'Usuarios por día en Analytics este mes, en una línea.',
    'Sesiones por canal en Analytics, en un donut.',
    'Las cinco personas con las que más me reúno este mes.',
    'Cuántas reuniones organizo yo y a cuántas me invitan, este mes.',
    'Los issues de Jira por prioridad, en barras.',
    'Avisos por día de la última semana.',
    'Mis avisos pendientes con pestañas por fuente.',
    'Una vista con los issues bloqueados y su responsable.',
    'Las horas de reunión por día de la semana.',
    'Los issues de Jira por persona asignada.',
    'Cuántos documentos de Notion, Jira y Archivo hay indexados.',
    'Una comparativa de reuniones de esta semana y la anterior.',
]);
