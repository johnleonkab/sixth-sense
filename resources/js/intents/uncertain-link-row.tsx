import { useHttp } from '@inertiajs/react';

import { Button } from '@/components/button';
import type { UncertainLink } from '@/intents/types';
import { intentTarget } from '@/intents/types';

/**
 * "¿Es esto?" Confirm keeps the link. Move sends it to the other intent.
 * The row leaves at once and comes back if the request fails.
 */
export default function UncertainLinkRow({
    link,
    targets,
    onOptimistic,
    onRollback,
    onSettled,
}: {
    link: UncertainLink;
    targets: { id: number; what: string }[];
    onOptimistic: () => void;
    onRollback: () => void;
    onSettled: () => void;
}) {
    const alternativeId = intentTarget(link.alternative);
    const alternative = targets.find((target) => target.id === alternativeId) ?? null;
    const others = targets.filter((target) => target.id !== link.intent_id);
    const request = useHttp({ target_id: alternative?.id ?? others[0]?.id ?? 0 });

    const fail = () => onRollback();

    const confirm = () => {
        onOptimistic();
        void request.post(`/comms/links/${link.id}/confirm`, {
            onSuccess: () => onSettled(),
            onError: fail,
            onHttpException: fail,
            onNetworkError: fail,
        });
    };

    const move = () => {
        if (!request.data.target_id) {
            return;
        }

        onOptimistic();
        void request.post(`/comms/links/${link.id}/move`, {
            onSuccess: () => onSettled(),
            onError: fail,
            onHttpException: fail,
            onNetworkError: fail,
        });
    };

    return (
        <article className="rounded-2xl border border-line bg-surface px-4 py-3">
            <p className="text-[14px] text-ink">¿Es esto? {link.what}</p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <Button type="button" size="small" variant="primary" onClick={confirm}>
                    Sí, es esto
                </Button>
                {alternative && (
                    <Button type="button" size="small" onClick={() => request.setData('target_id', alternative.id)} aria-pressed={request.data.target_id === alternative.id}>
                        {alternative.what}
                    </Button>
                )}
                {others.length > 0 && (
                    <select
                        aria-label={`Mover «${link.what}»`}
                        className="max-w-full rounded-full border border-line bg-surface px-3 py-1 text-[13px]"
                        value={request.data.target_id}
                        onChange={(event) => request.setData('target_id', Number(event.target.value))}
                    >
                        {others.map((target) => (
                            <option key={target.id} value={target.id}>
                                {target.what}
                            </option>
                        ))}
                    </select>
                )}
                <Button type="button" size="small" onClick={move} disabled={!request.data.target_id}>
                    Mover
                </Button>
            </div>
        </article>
    );
}
