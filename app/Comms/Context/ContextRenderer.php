<?php

namespace App\Comms\Context;

use App\Knowledge\Gaps\GapKind;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * One way to put a pack in front of a reader: a data block with refs for a
 * model, prose for the voice, and Markdown files for the work archive.
 */
class ContextRenderer
{
    private const ORIGINS = [
        'user' => 'lo dijiste tú',
        'correction' => 'aprendido',
        'observed' => 'visto',
        'inferred' => 'deducido',
    ];

    public function __construct(private ContextEngine $context) {}

    /**
     * Pack and render for a model in one call: what most readers need.
     */
    public function block(User $user, ContextRequest $request): string
    {
        return $this->forModel($this->context->pack($user, $request));
    }

    /**
     * Pack and render for the voice.
     */
    public function voice(User $user): string
    {
        return $this->forVoice($this->context->pack($user, new ContextRequest(Audience::Voice)));
    }

    /**
     * The block a model reads, wrapped as data, every line with its ref.
     */
    public function forModel(ContextPack $pack): string
    {
        if ($pack->isEmpty()) {
            return '';
        }

        $sections = [];
        $line = fn (array $fact, ?string $text = null): string => "- [{$fact['ref']}] ".($text ?? $fact['text']);

        if (($summary = $pack->where('summary')) !== []) {
            $sections[] = "Resumen:\n".$line($summary[0]);
        }

        $people = array_filter($pack->facts, fn (array $fact): bool => $fact['kind'] === 'person');

        if ($people !== []) {
            $sections[] = "Personas:\n".implode("\n", array_map(fn (array $fact): string => $line(
                $fact,
                $this->name($fact).' · '.$fact['attribute'].': '.$fact['text'].' ('.self::ORIGINS[$fact['origin']].')',
            ), $people));
        }

        $areas = array_filter($pack->facts, fn (array $fact): bool => $fact['kind'] === 'area');

        if ($areas !== []) {
            $sections[] = "Áreas que lleva:\n".implode("\n", array_map(fn (array $fact): string => $line($fact), $areas));
        }

        $preferences = array_filter($pack->facts, fn (array $fact): bool => $fact['kind'] === 'preference');

        if ($preferences !== []) {
            $sections[] = "Preferencias:\n".implode("\n", array_map(fn (array $fact): string => $line(
                $fact,
                $this->preference($fact),
            ), $preferences));
        }

        $slack = $this->slack($pack);

        if ($slack !== '') {
            $sections[] = "Política de atención en Slack, aprendida de cómo ha respondido:\n".$slack;
        }

        $workstreams = array_filter($pack->facts, fn (array $fact): bool => $fact['kind'] === 'workstream');

        if ($workstreams !== []) {
            $sections[] = "En qué está:\n".implode("\n", array_map(fn (array $fact): string => $line($fact, $this->workstream($fact)), $workstreams));
        }

        $terms = array_filter($pack->facts, fn (array $fact): bool => $fact['kind'] === 'term');

        if ($terms !== []) {
            $sections[] = "Lo que significa aquí:\n".implode("\n", array_map(fn (array $fact): string => $line($fact, $this->name($fact).': '.$fact['text']), $terms));
        }

        $rules = array_filter($pack->facts, fn (array $fact): bool => $fact['attribute'] === 'rule' && $fact['kind'] !== 'channel');

        if ($rules !== []) {
            $sections[] = "Reglas aprendidas:\n".implode("\n", array_map(fn (array $fact): string => $line($fact), $rules));
        }

        if ($pack->intents !== []) {
            $sections[] = "Abierto:\n".implode("\n", array_map(fn (array $intent): string => "- [{$intent['ref']}] {$intent['text']}", $pack->intents));
        }

        if ($pack->documents !== []) {
            $sections[] = "Documentos:\n".implode("\n", array_map(fn (array $document): string => "- [{$document['ref']}] {$document['title']}: {$document['text']}", $pack->documents));
        }

        if ($pack->findings !== []) {
            $sections[] = "Desacuerdos entre fuentes:\n".implode("\n", array_map(fn (array $finding): string => '- '.$finding['summary'], $pack->findings));
        }

        return "<context role=\"data\">\n".implode("\n", $sections)."\n</context>";
    }

    /**
     * The same content as prose for the voice: no refs, no sensitive facts.
     */
    public function forVoice(ContextPack $pack): string
    {
        $summary = $pack->where('summary');
        $workstreams = array_filter($pack->facts, fn (array $fact): bool => $fact['kind'] === 'workstream' && $fact['attribute'] === 'state');
        $notes = $pack->where('note', 'person');
        $languages = $pack->where('language', 'person');
        $hot = $pack->where('hot');
        $jira = $pack->where('moved');
        $tones = $pack->where('tone', 'preference');
        $lines = [];

        if ($summary !== [] || $workstreams !== []) {
            $at = ($summary[0] ?? array_values($workstreams)[0])['at'];
            $lines[] = 'Contexto de su trabajo estos días, preparado el '.$at->locale('es')->isoFormat('dddd D [de] MMMM').' leyendo (sin cambiar nada) su Slack, su correo, su Drive, su Notion y su Jira. '
                .'Úsalo para entender de qué y de quién habla, en qué idioma, y cómo escribe, sin que te lo explique. No lo recites ni digas que lo tienes si no viene a cuento. '
                .'Puede estar desactualizado: lo que veas con tus herramientas manda.';
        }

        if ($summary !== []) {
            $lines[] = 'Resumen: '.$summary[0]['text'];
        }

        if ($workstreams !== []) {
            $lines[] = "En qué está:\n".implode("\n", array_map(fn (array $fact): string => '- '.$this->workstream($fact), $workstreams));
        }

        if ($notes !== []) {
            $lines[] = 'Personas: '.implode('; ', array_map(fn (array $fact): string => $this->name($fact).' ('.$fact['text'].')', $notes)).'.';
        }

        if ($languages !== []) {
            $lines[] = "Contactos frecuentes (idioma en el que hay que hablarles):\n".implode("\n", array_map(fn (array $fact): string => '- '.$this->contact($fact), $languages));
        }

        foreach ($tones as $tone) {
            $lines[] = 'Cómo escribe en '.$this->channel($tone['subject']).': '.$tone['text'];
        }

        foreach (['file' => 'Archivos calientes', 'page' => 'Páginas calientes de Notion'] as $prefix => $title) {
            $items = array_filter($hot, fn (array $fact): bool => str_starts_with($fact['subject'], $prefix.':'));

            if ($items !== []) {
                $lines[] = "{$title}:\n".implode("\n", array_map(fn (array $fact): string => '- '.$this->name($fact).($fact['text'] !== '' ? ': '.$fact['text'] : ''), $items));
            }
        }

        if ($jira !== []) {
            $lines[] = 'Jira reciente: '.implode('; ', array_column($jira, 'text')).'.';
        }

        $told = array_filter($pack->facts, fn (array $fact): bool => $fact['origin'] === 'user' && $fact['attribute'] !== 'tone' && in_array($fact['kind'], ['person', 'area', 'preference'], true));

        if ($told !== []) {
            $lines[] = "Lo que el usuario te ha dicho de su trabajo:\n".implode("\n", array_map(fn (array $fact): string => '- '.$this->said($fact), $told));
        }

        $terms = array_filter($pack->facts, fn (array $fact): bool => $fact['kind'] === 'term');

        if ($terms !== []) {
            $lines[] = "Lo que el usuario te ha explicado cuando preguntaste:\n".implode("\n", array_map(fn (array $fact): string => '- '.$this->term($fact), $terms));
        }

        $rules = $pack->rules();

        if ($rules !== []) {
            $lines[] = "Aprendido de sus correcciones:\n- ".implode("\n- ", $rules);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $fact
     */
    private function term(array $fact): string
    {
        $kind = GapKind::tryFrom(Str::before($fact['subject'], ':'));

        return ($kind !== null ? "{$kind->label()} «{$this->name($fact)}»" : $this->name($fact)).': '.$fact['text'];
    }

    /**
     * The files the work archive keeps: now.md, the writer agents, the review
     * agent and what the person explained.
     *
     * @return array<string, string> Contents by path.
     */
    public function forExport(ContextPack $pack): array
    {
        $summary = $pack->summary();
        $workstreams = array_values(array_filter($pack->facts, fn (array $fact): bool => $fact['kind'] === 'workstream' && $fact['attribute'] === 'state'));
        $contacts = $pack->where('language', 'person');
        $hot = $pack->where('hot');
        $jira = array_column($pack->where('moved'), 'text');
        $tone = fn (string $channel): string => collect($pack->where('tone', 'preference'))->firstWhere('subject', 'channel:'.$channel)['text'] ?? '';
        $fronts = array_map(fn (array $fact): string => '- **'.$this->name($fact).'.** '.(string) ($fact['value']['what'] ?? $fact['text']).(filled($fact['value']['next'] ?? null) ? ' Siguiente: '.$fact['value']['next'].'.' : ''), $workstreams);

        $now = ["# Ahora\n", $summary, "\n## Frentes\n", ...$fronts];
        $now[] = "\n## Cómo escribo\n";
        $now[] = "### Slack\n\n".($tone('slack') !== '' ? $tone('slack') : 'Aún no hay bastante.');
        $now[] = "\n### Gmail\n\n".($tone('gmail') !== '' ? $tone('gmail') : 'Aún no hay bastante.');
        $now[] = "\n## Contactos frecuentes\n";

        foreach ($contacts as $contact) {
            $now[] = '- **'.$this->name($contact).'**'
                .($contact['text'] !== '' ? ' · '.$contact['text'] : '')
                .(filled($contact['value']['where'] ?? null) ? ' · '.$contact['value']['where'] : '')
                .(filled($contact['value']['note'] ?? null) ? '. '.$contact['value']['note'] : '');
        }

        foreach (['file' => 'Archivos calientes', 'page' => 'Páginas calientes'] as $prefix => $title) {
            $now[] = "\n## {$title}\n";

            foreach (array_filter($hot, fn (array $fact): bool => str_starts_with($fact['subject'], $prefix.':')) as $item) {
                $now[] = '- '.$this->name($item).($item['text'] !== '' ? ': '.$item['text'] : '');
            }
        }

        $now[] = "\n## Jira\n";

        foreach ($jira as $update) {
            $now[] = '- '.$update;
        }

        $files = [
            'now.md' => implode("\n", $now)."\n",
            'gmail' => $this->writer('gmail-writer', 'Escribe correos con la voz de la persona. Úsalo cuando haya que redactar, contestar o reenviar un email.', $tone('gmail'), $contacts, 'Gmail'),
            'slack' => $this->writer('slack-writer', 'Escribe mensajes de Slack con la voz de la persona. Úsalo cuando haya que escribir en un canal, un hilo o un mensaje directo.', $tone('slack'), $contacts, 'Slack'),
            'review' => $this->review($summary, $workstreams, $jira),
        ];

        $answers = array_filter($pack->facts, fn (array $fact): bool => $fact['kind'] === 'term');

        foreach (['meeting' => ['memory/meetings.md', 'Reuniones'], 'term' => ['memory/terms.md', 'Siglas y proyectos']] as $kind => [$path, $title]) {
            $items = array_filter($answers, fn (array $fact): bool => str_starts_with($fact['subject'], $kind.':'));

            if ($items === []) {
                continue;
            }

            usort($items, fn (array $left, array $right): int => strcmp($this->name($left), $this->name($right)));
            $lines = ["# {$title}\n", 'Lo que se ha explicado cuando sixth-sense lo preguntó. Se reescribe cada noche desde sixth-sense.', ''];

            foreach ($items as $item) {
                $lines[] = '## '.$this->name($item)."\n\n".trim($item['text'])."\n";
            }

            $files[$path] = implode("\n", $lines);
        }

        return $files;
    }

    /**
     * @param  array{subject: string, value: array<string, mixed>}  $fact
     */
    public function name(array $fact): string
    {
        return (string) ($fact['value']['name'] ?? $fact['value']['title'] ?? Str::after($fact['subject'], ':'));
    }

    /**
     * One fact as a short sentence, for the settings pages and the voice.
     *
     * @param  array{kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>}  $fact
     */
    public function said(array $fact): string
    {
        return match (true) {
            $fact['kind'] === 'preference' => $this->preference($fact),
            $fact['kind'] === 'area' => 'Lleva '.$fact['text'],
            $fact['attribute'] === 'relation' => $this->name($fact).' es '.$fact['text'],
            default => $this->name($fact).' · '.$fact['attribute'].': '.$fact['text'],
        };
    }

    /**
     * @param  array{subject: string, attribute: string, text: string, value: array<string, mixed>}  $fact
     */
    private function preference(array $fact): string
    {
        return match ($fact['attribute']) {
            'hours' => 'Horario: '.$fact['text'].(filled($fact['value']['days'] ?? null) ? ' ('.implode(', ', (array) $fact['value']['days']).')' : ''),
            'timezone' => 'Zona horaria: '.$fact['text'],
            'locale' => 'Idioma: '.$fact['text'],
            'tone' => 'Cómo escribe en '.$this->channel($fact['subject']).': '.$fact['text'],
            default => $fact['attribute'].': '.$fact['text'],
        };
    }

    private function channel(string $subject): string
    {
        return match ($subject) {
            'channel:slack' => 'Slack',
            'channel:gmail' => 'Gmail',
            default => Str::after($subject, ':'),
        };
    }

    /**
     * @param  array{value: array<string, mixed>, text: string, subject: string}  $fact
     */
    private function workstream(array $fact): string
    {
        $value = $fact['value'];

        if (! isset($value['what'])) {
            return $fact['text'];
        }

        return implode(' ', array_filter([
            $this->name($fact).': '.rtrim((string) $value['what'], '.').'.',
            filled($value['why'] ?? null) ? "Por qué: {$value['why']}." : null,
            filled($value['with'] ?? null) ? 'Con: '.implode(', ', (array) $value['with']).'.' : null,
            filled($value['where'] ?? null) ? 'Dónde: '.implode(', ', (array) $value['where']).'.' : null,
            filled($value['next'] ?? null) ? "Siguiente paso: {$value['next']}." : null,
        ]));
    }

    /**
     * @param  array{value: array<string, mixed>, text: string, subject: string}  $fact
     */
    private function contact(array $fact): string
    {
        return $this->name($fact)
            .($fact['text'] !== '' ? ' · '.$fact['text'] : '')
            .(filled($fact['value']['where'] ?? null) ? ' · '.$fact['value']['where'] : '')
            .(filled($fact['value']['note'] ?? null) ? ' · '.$fact['value']['note'] : '');
    }

    private function slack(ContextPack $pack): string
    {
        $said = array_column(array_filter($pack->where('rule', 'channel'), fn (array $fact): bool => str_starts_with($fact['subject'], 'slack')), 'text');
        $profile = array_column(array_filter($pack->where('profile', 'channel'), fn (array $fact): bool => $fact['subject'] === 'slack'), 'text');

        return trim(($said !== [] ? "Reglas que ha dicho ella, por encima del histórico:\n".implode("\n", $said)."\n\n" : '').implode("\n", $profile));
    }

    /**
     * @param  list<array{value: array<string, mixed>, text: string, subject: string}>  $contacts
     */
    private function writer(string $name, string $description, string $writing, array $contacts, string $where): string
    {
        $theirs = array_values(array_filter($contacts, fn (array $contact): bool => blank($contact['value']['where'] ?? null) || str_contains(mb_strtolower((string) $contact['value']['where']), mb_strtolower($where))));
        $list = $theirs === [] ? 'No hay contactos frecuentes todavía.' : implode("\n", array_map(
            fn (array $contact): string => '- '.$this->name($contact).($contact['text'] !== '' ? ' ('.$contact['text'].')' : '').(filled($contact['value']['note'] ?? null) ? ': '.$contact['value']['note'] : ''),
            $theirs,
        ));

        return <<<MD
            ---
            name: {$name}
            description: {$description}
            ---

            Escribe como la persona, no como un asistente. No inventes datos que no estén en el hilo.

            Cómo escribe:
            {$writing}

            Con quién, y en qué idioma:
            {$list}
            MD;
    }

    /**
     * @param  list<array{value: array<string, mixed>, text: string, subject: string}>  $workstreams
     * @param  list<string>  $jira
     */
    private function review(string $summary, array $workstreams, array $jira): string
    {
        $fronts = implode("\n", array_map(fn (array $fact): string => '- '.$this->name($fact).': '.(string) ($fact['value']['what'] ?? $fact['text']), $workstreams));
        $updates = implode("\n", array_map(fn (string $update): string => '- '.$update, $jira));

        return <<<MD
            ---
            name: review
            description: Repasa en qué está la persona y qué conviene mirar ahora. Úsalo cuando pida una revisión del trabajo, un resumen del día o qué tiene abierto.
            ---

            Parte de esto, y dilo corto. El detalle ya está en now.md. Lo que sigue abierto está en sixth-sense, no aquí.

            {$summary}

            Frentes:
            {$fronts}

            Jira:
            {$updates}
            MD;
    }
}
