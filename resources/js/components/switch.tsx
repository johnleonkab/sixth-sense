import type { ReactNode } from 'react';

type SwitchProps = {
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
    /** Label, rendered inside the switch so the whole row is clickable. */
    children?: ReactNode;
    className?: string;
};

/**
 * An on/off control, accessible as role="switch".
 */
export default function Switch({ checked, onChange, disabled = false, children, className = '' }: SwitchProps) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`group flex items-center gap-2.5 text-left disabled:cursor-not-allowed ${className}`}
        >
            <span
                aria-hidden
                className="relative h-[18px] w-[30px] flex-none rounded-full bg-line transition-colors group-aria-checked:bg-accent group-disabled:opacity-50"
            >
                <span className="absolute top-[2px] left-[2px] size-[14px] rounded-full bg-surface shadow-sm transition-transform group-aria-checked:translate-x-3" />
            </span>
            {children}
        </button>
    );
}
