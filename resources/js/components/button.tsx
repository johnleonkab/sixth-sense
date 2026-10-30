import { forwardRef } from 'react';
import type { AnchorHTMLAttributes, ButtonHTMLAttributes, HTMLAttributes, ReactNode } from 'react';

/**
 * The one button of the app, with OpenUI's API (variant, size, destructive,
 * icons; Buttons to group them) and this app's tokens: one accent, pills,
 * no shadows. Composed views use it through the OpenUI Button and Buttons
 * blocks, so what the assistant composes looks like the rest.
 */
export type ButtonVariant = 'primary' | 'secondary' | 'tertiary';
export type ButtonSize = 'extra-small' | 'small' | 'medium' | 'large';

type Look = { variant?: ButtonVariant; size?: ButtonSize; destructive?: boolean; iconLeft?: ReactNode; iconRight?: ReactNode };

const sizes: Record<ButtonSize, string> = {
    'extra-small': 'gap-1 px-2.5 py-0.5 text-[12px]',
    small: 'gap-1.5 px-3 py-1 text-[12.5px]',
    medium: 'gap-1.5 px-3.5 py-1.5 text-[13px]',
    large: 'gap-2 px-5 py-2 text-[14px] max-md:min-h-11',
};

const variants: Record<ButtonVariant, string> = {
    primary: 'bg-ink font-medium text-bg hover:opacity-90',
    secondary: 'border border-line bg-surface text-ink hover:bg-soft',
    tertiary: 'text-muted hover:bg-soft hover:text-ink',
};

const destructiveVariants: Record<ButtonVariant, string> = {
    primary: 'bg-crit font-medium text-bg hover:opacity-90',
    secondary: 'border border-line bg-surface text-crit hover:border-crit',
    tertiary: 'text-crit hover:bg-soft',
};

export function buttonClass({ variant = 'secondary', size = 'medium', destructive = false }: Look = {}): string {
    return [
        'inline-flex items-center justify-center rounded-full whitespace-nowrap transition-[background-color,border-color,color,opacity] duration-150',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:pointer-events-none disabled:opacity-40',
        '[&_svg]:size-[1.1em] [&_svg]:flex-none',
        sizes[size],
        (destructive ? destructiveVariants : variants)[variant],
    ].join(' ');
}

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & Look;

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    { variant, size, destructive, iconLeft, iconRight, className = '', type = 'button', children, ...rest },
    ref,
) {
    return (
        <button ref={ref} type={type} className={`${buttonClass({ variant, size, destructive })} ${className}`} {...rest}>
            {iconLeft}
            {children}
            {iconRight}
        </button>
    );
});

/** A link that looks like a button: "Abrir en Slack ↗". */
export function ButtonLink({ variant, size, destructive, iconLeft, iconRight, className = '', children, ...rest }: AnchorHTMLAttributes<HTMLAnchorElement> & Look) {
    return (
        <a className={`${buttonClass({ variant, size, destructive })} ${className}`} {...rest}>
            {iconLeft}
            {children}
            {iconRight}
        </a>
    );
}

/**
 * Buttons that go together, in a row that wraps (or a column). The main one
 * goes first.
 */
export function Buttons({ variant = 'horizontal', className = '', ...rest }: HTMLAttributes<HTMLDivElement> & { variant?: 'horizontal' | 'vertical' }) {
    return (
        <div
            role="group"
            className={`flex gap-2 ${variant === 'vertical' ? 'flex-col items-stretch' : 'flex-wrap items-center'} ${className}`}
            {...rest}
        />
    );
}
