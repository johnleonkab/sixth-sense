import { Form, Head, Link } from '@inertiajs/react';

import Wordmark from '@/components/wordmark';

const inputClass =
    'w-full rounded-[10px] border border-line bg-surface px-3.5 py-2.5 text-[14px] text-ink placeholder:text-faint ' +
    'transition-colors outline-none focus:border-accent aria-invalid:border-crit';

export default function Login({ canRegister = false }: { canRegister?: boolean }) {
    return (
        <>
            <Head title="Entrar" />

            <main className="grid min-h-full place-items-center px-4 py-12">
                <div className="w-full max-w-[360px]">
                    <div className="mb-8 text-[15px]">
                        <Wordmark to="/" className="h-8" />
                        <p className="mt-3 text-muted">Tu jornada, hablando.</p>
                    </div>

                    <Form
                        action="/login"
                        method="post"
                        resetOnError={['password']}
                        className="rounded-2xl border border-line bg-surface p-6"
                    >
                        {({ errors, processing }) => (
                            <div className="flex flex-col gap-5">
                                <div className="flex flex-col gap-1.5">
                                    <label htmlFor="email" className="text-[13px] font-medium">
                                        Email
                                    </label>
                                    <input
                                        id="email"
                                        type="email"
                                        name="email"
                                        autoComplete="username"
                                        autoFocus
                                        required
                                        aria-invalid={errors.email ? true : undefined}
                                        aria-describedby={errors.email ? 'email-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.email && (
                                        <p id="email-error" className="text-[12.5px] text-crit">
                                            {errors.email}
                                        </p>
                                    )}
                                </div>

                                <div className="flex flex-col gap-1.5">
                                    <label htmlFor="password" className="text-[13px] font-medium">
                                        Contraseña
                                    </label>
                                    <input
                                        id="password"
                                        type="password"
                                        name="password"
                                        autoComplete="current-password"
                                        required
                                        aria-invalid={errors.password ? true : undefined}
                                        aria-describedby={errors.password ? 'password-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.password && (
                                        <p id="password-error" className="text-[12.5px] text-crit">
                                            {errors.password}
                                        </p>
                                    )}
                                </div>

                                <label className="flex items-center gap-2.5 text-[13px] text-muted select-none">
                                    <input type="checkbox" name="remember" value="1" className="size-4 accent-accent" />
                                    Mantener la sesión abierta
                                </label>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="mt-1 rounded-[10px] bg-accent px-4 py-2.5 font-medium text-accent-ink transition-opacity hover:opacity-90 disabled:opacity-60"
                                >
                                    {processing ? 'Entrando…' : 'Entrar'}
                                </button>
                            </div>
                        )}
                    </Form>

                    {canRegister && (
                        <p className="mt-5 text-center text-[13px] text-muted">
                            ¿No tienes cuenta?{' '}
                            <Link href="/register" className="font-medium text-ink underline underline-offset-2">
                                Créala
                            </Link>
                        </p>
                    )}
                </div>
            </main>
        </>
    );
}
