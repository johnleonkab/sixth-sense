<?php

namespace App\Sense;

/**
 * The files every work repository starts with. The same list is what an
 * existing repository is checked against: nothing here is decided by a model.
 */
class Scaffold
{
    /**
     * @return array<string, string> Path to file contents.
     */
    public static function files(): array
    {
        return [
            'CLAUDE.md' => <<<'MD'
                # Cómo se trabaja en este archivo

                Este repositorio sigue la estructura de Claude Code: funciona igual abierto en Claude Code que en sixth-sense.

                - `.claude/agents/{nombre}.md` es un agente: cabecera con `name` y `description` (cuándo usarlo), y debajo sus instrucciones. Con `skills:` precarga skills.
                - `.claude/skills/{nombre}/SKILL.md` es una skill: `description` dice cuándo usarla. Los demás archivos de su carpeta se leen solo cuando hacen falta.
                - Cada documento con tipo lleva una cabecera YAML entre `---` y obedece `types/{tipo}.yml`.

                El agente `keeper` mantiene este archivo: crea, actualiza y borra. Nada se escribe sin que la persona diga que sí.

                `now.md` es el estado vivo. `map.md` dice dónde está cada cosa. `vertical` en el proyecto dice si es finance, product, delivery u operations.
                MD,
            'now.md' => "# Ahora\n\nResumen de lo que está en marcha.\n\n## Frentes\n\n## Personas\n\n## Cabos sueltos\n",
            'profile.md' => "# Perfil\n\nQuién usa este archivo y cómo quiere que se le hable.\n",
            'people.md' => "# Personas\n\nQuienes importan en el trabajo, y dónde aparecen.\n",
            'map.md' => <<<'MD'
                # Mapa

                - `CLAUDE.md` cómo se trabaja aquí. Se carga siempre.
                - `.claude/agents/` agentes. `keeper` mantiene el archivo.
                - `.claude/skills/` skills que usan los agentes.
                - `types/` reglas de cada tipo de documento.
                - `projects/` un proyecto por carpeta.
                - `inbox/` notas que todavía no tienen sitio.
                - `archive/` lo que ya no está vivo.
                MD,
            'types/project.yml' => <<<'YML'
                type: project
                where: projects/{project}
                name: project.md
                required:
                  author: Quién lo lleva
                  why: Para qué es el proyecto
                  outcome: Qué se considera terminado
                optional:
                  vertical: finance, product, delivery u operations
                YML,
            'types/prd.yml' => <<<'YML'
                type: prd
                where: projects/{project}/docs
                name: "{date}-{slug}.md"
                required:
                  author: Quién lo pide
                  why: Por qué hace falta
                  outcome: Qué se espera al terminar
                optional:
                  status: draft, decided o dropped
                  until: Fecha límite
                YML,
            'types/decision.yml' => <<<'YML'
                type: decision
                where: projects/{project}
                name: decisions.md
                required:
                  author: Quién decide
                  why: Qué se está decidiendo
                  outcome: Qué queda decidido
                YML,
            'types/list.yml' => <<<'YML'
                type: list
                where: projects/{project}
                name: open.md
                required:
                  author: Quién la lleva
                YML,
            'types/log.yml' => <<<'YML'
                type: log
                where: projects/{project}
                name: log.md
                required:
                  author: Quién lo escribe
                YML,
            'types/note.yml' => <<<'YML'
                type: note
                where: inbox
                name: "{date}-{slug}.md"
                required:
                  author: Quién lo anota
                YML,
            'projects/_template/project.md' => <<<'MD'
                ---
                type: project
                author: Pendiente
                why: Pendiente
                outcome: Pendiente
                vertical: product
                ---

                # Proyecto

                Qué es y en qué vertical vive.
                MD,
            'projects/_template/docs/2026-01-01-brief.md' => <<<'MD'
                ---
                type: prd
                author: Pendiente
                why: Pendiente
                outcome: Pendiente
                status: draft
                ---

                # Brief
                MD,
            'projects/_template/decisions.md' => <<<'MD'
                ---
                type: decision
                author: Pendiente
                why: Pendiente
                outcome: Pendiente
                ---

                # Decisiones
                MD,
            'projects/_template/open.md' => <<<'MD'
                ---
                type: list
                author: Pendiente
                ---

                # Abierto
                MD,
            'projects/_template/log.md' => <<<'MD'
                ---
                type: log
                author: Pendiente
                ---

                # Diario
                MD,
            '.claude/agents/keeper.md' => <<<'MD'
                ---
                name: keeper
                description: Mantiene el archivo de trabajo. Úsalo cuando haya que guardar, cambiar o borrar algo del archivo, o crear un agente o una skill.
                skills:
                  - archive-documents
                ---

                Mantienes el archivo de trabajo. Para escribir o actualizar usa propose_sense_file. Para borrar, propose_sense_delete.

                Un agente nuevo es `.claude/agents/{nombre}.md` con `name` y `description`, y sus instrucciones debajo. Si necesita material de consulta, crea una skill en `.claude/skills/{nombre}/SKILL.md` y añádela a su `skills:`.
                Después de crear o cambiar un agente, una skill o CLAUDE.md, llama a sync_sense_agents.
                MD,
            '.claude/skills/archive-documents/SKILL.md' => <<<'MD'
                ---
                name: archive-documents
                description: Reglas para crear documentos con tipo en el archivo. Úsala antes de crear o mover un documento de projects/ o inbox/.
                ---

                Lee `types/` antes de crear un documento y cumple `where`, `name` y `required`.

                - `{project}` es la carpeta del proyecto.
                - `{date}` es AAAA-MM-DD.
                - `{slug}` es el nombre en minúsculas y guiones.

                Un proyecto nuevo copia `projects/_template`.
                No inventes un tipo: si no existe en `types/`, proponlo primero como `types/{tipo}.yml` con type, where, name y required.
                MD,
            'inbox/.gitkeep' => '',
            'archive/.gitkeep' => '',
        ];
    }

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        return array_keys(self::files());
    }

    /**
     * @return list<string>
     */
    public static function typePaths(): array
    {
        return array_values(array_filter(self::paths(), fn (string $path): bool => str_starts_with($path, 'types/')));
    }
}
