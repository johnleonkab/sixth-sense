import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/button';
import SettingsLayout from '@/layouts/settings-layout';

type Lesson = {
    id: number;
    body: string;
    layer: string | null;
    layer_label: string;
    about: string | null;
    uses: number;
    created_at: string;
};

type LessonsProps = {
    lessons: Lesson[];
    inertia: {
        rate: number | null;
        sends: number;
        sample: { id: number; actor: string | null; title: string | null; text: string; at: string }[];
    };
};

const dayFormat = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' });

/** One lesson: read as it is, corrected in place, or deleted. */
function LessonRow({ lesson }: { lesson: Lesson }) {
    const [editing, setEditing] = useState(false);
    const [body, setBody] = useState(lesson.body);

    const save = () =>
        router.put(`/settings/lessons/${lesson.id}`, { body }, { preserveScroll: true, onSuccess: () => setEditing(false) });

    return (
        <li className="grid gap-1.5 border-b border-line py-3 last:border-b-0">
            {editing ? (
                <textarea
                    aria-label="Lección"
                    value={body}
                    maxLength={240}
                    onChange={(event) => setBody(event.target.value)}
                    rows={2}
                    className="w-full resize-y rounded-md border border-line bg-surface px-2.5 py-2 text-[13.5px] leading-[1.5] outline-none focus:border-accent"
                />
            ) : (
                <p className="text-[13.5px] leading-[1.5]">{lesson.body}</p>
            )}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted">
                <span className="rounded-full bg-soft px-2 py-[2px] text-[11px] font-medium text-ink/80">{lesson.layer_label}</span>
                {lesson.about && <span>Sobre {lesson.about}</span>}
                <span>{dayFormat.format(new Date(lesson.created_at))}</span>
                <span>{lesson.uses === 1 ? 'Usada 1 vez' : `Usada ${lesson.uses} veces`}</span>
                <span className="ml-auto flex gap-1.5">
                    {editing ? (
                        <>
                            <Button variant="primary" onClick={save} disabled={body.trim() === ''}>
                                Guardar
                            </Button>
                            <Button
                                variant="tertiary"
                                onClick={() => {
                                    setEditing(false);
                                    setBody(lesson.body);
                                }}
                            >
                                Cancelar
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button variant="tertiary" onClick={() => setEditing(true)}>
                                Corregir
                            </Button>
                            <Button variant="tertiary" destructive onClick={() => router.delete(`/settings/lessons/${lesson.id}`, { preserveScroll: true })}>
                                Borrar
                            </Button>
                        </>
                    )}
                </span>
            </div>
        </li>
    );
}

export default function Lessons({ lessons, inertia }: LessonsProps) {
    return (
        <SettingsLayout
            title="Lecciones"
            description="Lo que sixth-sense ha aprendido de tus correcciones: cuando modificas, descartas con motivo, eliges personas o cambias una duración. Solo añaden contexto; nunca cambian el «sí»."
        >
            {inertia.sample.length > 0 && (
                <section className="mb-6 rounded-2xl border border-line bg-surface px-5 py-4">
                    <h2 className="text-[14px] font-medium">Revisa estos envíos</h2>
                    <p className="mt-1 text-[12.5px] text-muted">
                        Esta semana has enviado tal cual el {Math.round((inertia.rate ?? 0) * 100)} % de {inertia.sends} propuestas. Comprueba que eran buenas y no solo cómodas de aceptar.
                    </p>
                    <ul className="mt-3 grid gap-2.5">
                        {inertia.sample.map((sent) => (
                            <li key={sent.id} className="rounded-lg bg-soft px-3 py-2.5">
                                <p className="text-[12px] text-muted">
                                    {sent.actor} · {sent.title}
                                </p>
                                <p className="mt-1 text-[13px] leading-[1.5] whitespace-pre-wrap">{sent.text}</p>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {lessons.length === 0 ? (
                <p className="text-[13.5px] text-muted">Todavía nada. Cada vez que corrijas una propuesta, lo que enseña aparecerá aquí.</p>
            ) : (
                <ul className="rounded-2xl border border-line bg-surface px-5">
                    {lessons.map((lesson) => (
                        <LessonRow key={lesson.id} lesson={lesson} />
                    ))}
                </ul>
            )}
        </SettingsLayout>
    );
}
