import { Form, Head, Link } from '@inertiajs/react';

import Wordmark from '@/components/wordmark';

const inputClass =
    'w-full rounded-[10px] border border-line bg-surface px-3.5 py-2.5 text-[14px] text-ink placeholder:text-faint ' +
    'transition-colors outline-none focus:border-accent aria-invalid:border-crit';

type FieldProps = {
    name: string;
    label: string;
    type: string;
    autoComplete: string;
    error?: string;
    autoFocus?: boolean;
};

function Field({ name, label, type, autoComplete, error, autoFocus = false }: FieldProps) {
    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={name} className="text-[13px] font-medium">
                {label}
            </label>
            <input
                id={name}
                type={type}
                name={name}
                autoComplete={autoComplete}
                autoFocus={autoFocus}
                required
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${name}-error` : undefined}
                className={inputClass}
            />
            {error && (
                <p id={`${name}-error`} className="text-[12.5px] text-crit">
                    {error}
                </p>
            )}
        </div>
    );
}

export default function Register() {
    return (
        <>
            <Head title="Crear cuenta" />

            <main className="grid min-h-full place-items-center px-4 py-12">
                <div className="w-full max-w-[360px]">
                    <div className="mb-8 text-[15px]">
                        <Wordmark className="h-8" />
                        <p className="mt-3 text-muted">Tu jornada, hablando.</p>
                    </div>

                    <Form
                        action="/register"
                        method="post"
                        resetOnError={['password', 'password_confirmation']}
                        className="rounded-2xl border border-line bg-surface p-6"
                    >
                        {({ errors, processing }) => (
                            <div className="flex flex-col gap-5">
                                <Field name="name" label="Nombre" type="text" autoComplete="name" error={errors.name} autoFocus />
                                <Field name="email" label="Email" type="email" autoComplete="username" error={errors.email} />
                                <Field name="password" label="Contraseña" type="password" autoComplete="new-password" error={errors.password} />
                                <Field
                                    name="password_confirmation"
                                    label="Repite la contraseña"
                                    type="password"
                                    autoComplete="new-password"
                                    error={errors.password_confirmation}
                                />

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="mt-1 rounded-[10px] bg-accent px-4 py-2.5 font-medium text-accent-ink transition-opacity hover:opacity-90 disabled:opacity-60"
                                >
                                    {processing ? 'Creando cuenta…' : 'Crear cuenta'}
                                </button>
                            </div>
                        )}
                    </Form>

                    <p className="mt-5 text-center text-[13px] text-muted">
                        ¿Ya tienes cuenta?{' '}
                        <Link href="/login" className="font-medium text-ink underline underline-offset-2">
                            Entra
                        </Link>
                    </p>
                </div>
            </main>
        </>
    );
}
