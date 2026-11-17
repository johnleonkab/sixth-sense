import type { PlanGroup } from '@/intents/types';

const statusLabel: Record<string, string> = {
    done: 'Hecho',
    running: 'En marcha',
    needs_approval: 'Por aprobar',
    waiting: 'Esperando',
    blocked: 'Bloqueado',
    skipped: 'Omitido',
};

function heading(group: PlanGroup): string | null {
    if (group.steps.length < 2) {
        return null;
    }

    return group.after.length > 0 ? 'Después, a la vez' : 'A la vez';
}

/** The checklist inside a card. Parallel steps share one heading. */
export default function PlanChecklist({ plan }: { plan: PlanGroup[] }) {
    if (plan.length === 0) {
        return <p className="mt-1 text-[13px] text-muted">El plan aparecerá aquí.</p>;
    }

    return (
        <ol className="mt-2 flex flex-col gap-3">
            {plan.map((group, index) => {
                const title = heading(group);

                return (
                    <li key={`${group.after.join('-')}-${index}`} className="flex flex-col gap-1">
                        {title && <p className="text-[12px] text-muted">{title}</p>}
                        <ul className="flex flex-col gap-2">
                            {group.steps.map((step) => (
                                <li key={step.id} className="text-[14px] text-ink">
                                    <span>{step.text}</span>
                                    <span className="mt-0.5 block text-[12px] text-muted">
                                        {statusLabel[step.status] ?? step.status}
                                        {step.channel && ` · ${step.channel}`}
                                        {step.waiting_on && ` · Esperas a ${step.waiting_on.name}`}
                                    </span>
                                    {step.preview && <span className="mt-0.5 block text-[13px] text-ink">{step.preview}</span>}
                                </li>
                            ))}
                        </ul>
                    </li>
                );
            })}
        </ol>
    );
}
