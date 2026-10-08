import { router } from '@inertiajs/react';

import SettingsLayout from '@/layouts/settings-layout';

type DisplaySize = {
    key: 'compact' | 'normal' | 'large';
    name: string;
    description: string;
    scale: number;
};

/** A miniature canvas at the size it would be shown. */
function Preview({ scale }: { scale: number }) {
    return (
        <div aria-hidden className="grid h-[92px] place-items-center overflow-hidden rounded-xl bg-soft">
            <div style={{ zoom: scale }} className="w-[150px] rounded-lg border border-line bg-surface px-2.5 py-2">
                <div className="flex items-center gap-1.5">
                    <span className="grid size-3.5 place-items-center rounded-full border border-line font-mono text-[8px] text-muted">1</span>
                    <span className="text-[10px] font-medium">Hoy, jueves</span>
                </div>
                <div className="mt-1.5 space-y-1">
                    <div className="rounded-[4px] bg-accent-soft px-1.5 py-0.5 text-[8.5px] text-accent">10:00 · Prepago con Marta</div>
                    <div className="rounded-[4px] bg-soft px-1.5 py-0.5 text-[8.5px] text-muted">12:00 · 1:1</div>
                </div>
            </div>
        </div>
    );
}

export default function Display({ sizes, current }: { sizes: DisplaySize[]; current: DisplaySize['key'] }) {
    const choose = (key: DisplaySize['key']) => router.put('/settings/display', { display_size: key }, { preserveScroll: true });

    return (
        <SettingsLayout title="Pantalla" description="Cómo de grande ves lo que la IA pone en pantalla: calendarios, emails, documentos…">
            <fieldset>
                <legend className="mb-3 text-[13px] text-muted">Tamaño de visualización</legend>
                <div className="grid gap-3 sm:grid-cols-3">
                    {sizes.map((size) => (
                        <label
                            key={size.key}
                            className={`cursor-pointer rounded-2xl border bg-surface p-3 transition-colors ${size.key === current ? 'border-accent ring-1 ring-accent' : 'border-line hover:bg-soft'}`}
                        >
                            <input type="radio" name="display_size" value={size.key} checked={size.key === current} onChange={() => choose(size.key)} className="sr-only" />
                            <Preview scale={size.scale} />
                            <span className="mt-3 flex items-baseline justify-between gap-2">
                                <span className="text-[14px] font-medium">{size.name}</span>
                                <span className="font-mono text-[11px] text-faint">{Math.round(size.scale * 100)}%</span>
                            </span>
                            <span className="mt-0.5 block text-[12.5px] text-muted">{size.description}</span>
                        </label>
                    ))}
                </div>
            </fieldset>
        </SettingsLayout>
    );
}
