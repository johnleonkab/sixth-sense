import { router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

import { buttonClass } from '@/components/button';
import SettingsLayout from '@/layouts/settings-layout';

type Person = { id: number; name: string; relation: string; weight: number; ref: string };
type Area = { id: number; kind: string; ref: string; name: string; keywords: string[] };
type Preference = { timezone: string; locale: string; work_start: string; work_end: string; work_days: number[] };
type Option = { value: string; label: string; weight?: number };

const fieldClass = 'w-full rounded-xl border border-line bg-surface px-3 py-2 text-[14px] text-ink outline-none focus:border-accent';

const days = [
    { value: 1, label: 'Lunes' },
    { value: 2, label: 'Martes' },
    { value: 3, label: 'Miércoles' },
    { value: 4, label: 'Jueves' },
    { value: 5, label: 'Viernes' },
    { value: 6, label: 'Sábado' },
    { value: 7, label: 'Domingo' },
];

function PersonRow({ person, relations }: { person: Person; relations: Option[] }) {
    const [relation, setRelation] = useState(person.relation);
    const [weight, setWeight] = useState(String(person.weight));

    return (
        <li className="grid items-center gap-2 border-t border-line py-3 sm:grid-cols-[minmax(0,1fr)_180px_88px_auto]">
            <span className="truncate text-[14px]">{person.name}</span>
            <select aria-label={`Relación de ${person.name}`} value={relation} onChange={(event) => setRelation(event.target.value)} className={fieldClass}>
                {relations.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <input
                aria-label={`Peso de ${person.name}`}
                inputMode="numeric"
                value={weight}
                onChange={(event) => setWeight(event.target.value)}
                className={fieldClass}
            />
            <button
                type="button"
                className={buttonClass({ size: 'small' })}
                onClick={() => router.put(`/settings/people/${person.id}`, { relation, importance_weight: weight === '' ? null : Number(weight) }, { preserveScroll: true })}
            >
                Guardar
            </button>
        </li>
    );
}

export default function People({
    people,
    areas,
    preference,
    relations,
    kinds,
    timezones,
}: {
    people: Person[];
    areas: Area[];
    preference: Preference;
    relations: Option[];
    kinds: Option[];
    timezones: string[];
}) {
    const saveHours = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);

        router.put(
            '/settings/people/preference',
            {
                timezone: data.get('timezone'),
                locale: data.get('locale'),
                work_start: data.get('work_start'),
                work_end: data.get('work_end'),
                work_days: data.getAll('work_days').map(Number),
            },
            { preserveScroll: true },
        );
    };

    const saveArea = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        const data = new FormData(form);

        router.post(
            '/settings/people/areas',
            {
                kind: data.get('kind'),
                ref: data.get('ref'),
                name: data.get('name'),
                keywords: data.get('keywords'),
            },
            { preserveScroll: true, onSuccess: () => form.reset() },
        );
    };

    return (
        <SettingsLayout title="Personas" description="Quién importa, qué llevas tú y a qué hora trabajas. Cada cambio queda registrado.">
            <form onSubmit={saveHours} className="mb-10 space-y-4">
                <h2 className="text-[15px] font-medium">Horario</h2>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block text-[13px] text-muted">
                        Zona horaria
                        <select name="timezone" defaultValue={preference.timezone} className={`${fieldClass} mt-1`}>
                            {timezones.map((zone) => (
                                <option key={zone}>{zone}</option>
                            ))}
                        </select>
                    </label>
                    <label className="block text-[13px] text-muted">
                        Idioma
                        <select name="locale" defaultValue={preference.locale} className={`${fieldClass} mt-1`}>
                            <option value="es">Español</option>
                            <option value="en">English</option>
                        </select>
                    </label>
                    <label className="block text-[13px] text-muted">
                        Empiezo
                        <input name="work_start" type="time" defaultValue={preference.work_start} required className={`${fieldClass} mt-1`} />
                    </label>
                    <label className="block text-[13px] text-muted">
                        Termino
                        <input name="work_end" type="time" defaultValue={preference.work_end} required className={`${fieldClass} mt-1`} />
                    </label>
                </div>
                <fieldset>
                    <legend className="text-[13px] text-muted">Días</legend>
                    <div className="mt-2 flex flex-wrap gap-3">
                        {days.map((day) => (
                            <label key={day.value} className="flex items-center gap-1.5 text-[13px]">
                                <input type="checkbox" name="work_days" value={day.value} defaultChecked={preference.work_days.includes(day.value)} className="accent-[var(--accent)]" />
                                {day.label}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <button type="submit" className={buttonClass({ variant: 'primary', size: 'small' })}>
                    Guardar horario
                </button>
            </form>

            <section className="mb-10" aria-labelledby="people-heading">
                <h2 id="people-heading" className="text-[15px] font-medium">
                    Personas
                </h2>
                {people.length === 0 ? (
                    <p className="mt-3 text-[14px] text-muted">Todavía no hay nadie. Aparecerán cuando lleguen mensajes.</p>
                ) : (
                    <ul className="mt-2">
                        {people.map((person) => (
                            <PersonRow key={person.id} person={person} relations={relations} />
                        ))}
                    </ul>
                )}
            </section>

            <section aria-labelledby="areas-heading">
                <h2 id="areas-heading" className="text-[15px] font-medium">
                    Lo que llevas
                </h2>
                <ul className="mt-3 space-y-2">
                    {areas.map((area) => (
                        <li key={area.id} className="flex items-center justify-between gap-3 text-[14px]">
                            <span>
                                {area.name} <span className="text-muted">{area.ref}</span>
                            </span>
                            <button type="button" className={buttonClass({ variant: 'tertiary', size: 'extra-small', destructive: true })} onClick={() => router.delete(`/settings/people/areas/${area.id}`, { preserveScroll: true })}>
                                Quitar
                            </button>
                        </li>
                    ))}
                </ul>
                <form onSubmit={saveArea} className="mt-4 grid gap-3 sm:grid-cols-2">
                    <label className="block text-[13px] text-muted">
                        Tipo
                        <select name="kind" className={`${fieldClass} mt-1`} defaultValue={kinds[0]?.value}>
                            {kinds.map((kind) => (
                                <option key={kind.value} value={kind.value}>
                                    {kind.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="block text-[13px] text-muted">
                        Nombre
                        <input name="name" required className={`${fieldClass} mt-1`} />
                    </label>
                    <label className="block text-[13px] text-muted">
                        Referencia
                        <input name="ref" required className={`${fieldClass} mt-1`} />
                    </label>
                    <label className="block text-[13px] text-muted">
                        Palabras, separadas por comas
                        <input name="keywords" className={`${fieldClass} mt-1`} />
                    </label>
                    <button type="submit" className={buttonClass({ size: 'small' })}>
                        Añadir
                    </button>
                </form>
            </section>
        </SettingsLayout>
    );
}
