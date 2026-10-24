import { Link } from '@inertiajs/react';

import SettingsLayout from '@/layouts/settings-layout';
import ArchiveNav from '@/pages/settings/archive-nav';

/**
 * Shown when no GitHub repository is the work archive yet.
 */
export default function Archive() {
    return (
        <SettingsLayout title="Archivo" description="El repositorio donde viven los agentes y el resto de documentos de trabajo.">
            <ArchiveNav />

            <div className="rounded-2xl border border-line bg-surface px-[22px] py-5">
                <p className="text-[14px]">Todavía no hay un archivo.</p>
                <p className="mt-1.5 max-w-[480px] text-[13.5px] text-muted">
                    Conecta GitHub y elige un repositorio como archivo. A partir de ahí puedes ver sus agentes, cambiarlos o crear otros.
                </p>
                <Link href="/settings/connections" className="mt-4 inline-flex rounded-full bg-ink px-4 py-1.5 text-[13px] font-medium text-bg hover:opacity-90">
                    Ir a conexiones
                </Link>
            </div>
        </SettingsLayout>
    );
}
