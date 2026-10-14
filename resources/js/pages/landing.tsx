import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import ServiceIcon, { type Service } from '@/components/service-icon';
import VoiceOrb from '@/components/voice/voice-orb';
import Wordmark from '@/components/wordmark';

type LandingProps = {
    canRegister: boolean;
    repositoryUrl: string;
};

const services: { name: string; icon: Service }[] = [
    { name: 'Google Calendar', icon: 'calendar' },
    { name: 'Gmail', icon: 'gmail' },
    { name: 'Google Docs & Drive', icon: 'drive' },
    { name: 'Slack', icon: 'slack' },
    { name: 'Jira', icon: 'jira' },
    { name: 'Notion', icon: 'notion' },
    { name: 'Google Meet', icon: 'meet' },
];

const steps = [
    {
        title: 'You speak',
        body: '“What do I have today?” “Open Marta’s email.” “Move it to Thursday and invite Pablo.” Voice first; the keyboard is just a fallback.',
    },
    {
        title: 'It shows what you’re talking about',
        body: 'The assistant answers out loud and puts the calendar, the email, the Slack thread or the PDF on screen. Point at it: “open the second one”.',
    },
    {
        title: 'Nothing changes without your “yes”',
        body: 'Reading is free. Every email, message, meeting or edit is shown as a draft first, and you get a few seconds to undo after confirming.',
    },
];

const features = [
    {
        title: 'Alerts that wait for you',
        body: 'Watches Gmail and Slack and only interrupts for what needs your answer, always saying why. A reply engine proposes how to handle each one.',
    },
    {
        title: 'Knows what you’re working on',
        body: 'Optionally, every night it reads your week (read only) and builds a short context: your workstreams, why, and with whom.',
    },
    {
        title: 'Ready before your meetings',
        body: 'Reminds you early when a meeting needs preparation, and just before when it doesn’t.',
    },
    {
        title: 'Every account, together',
        body: 'Connect as many Google, Slack and Notion accounts as you like. Reads combine them all.',
    },
];

const selfHost = (repositoryUrl: string) => `git clone ${repositoryUrl}.git
cd sixth-sense && cp .env.example .env

docker compose build app
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
npm install && npm run build

docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan app:create-user`;

function GitHubIcon({ className = 'size-4' }: { className?: string }) {
    return (
        <svg viewBox="0 0 16 16" aria-hidden className={className} fill="currentColor">
            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
        </svg>
    );
}

function Section({ id, eyebrow, title, children }: { id: string; eyebrow: string; title: string; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className="mx-auto w-full max-w-[1080px] border-t border-line py-16 md:py-24">
            <p className="font-mono text-[12px] tracking-wide text-muted uppercase">{eyebrow}</p>
            <h2 id={id} className="mt-2 max-w-[640px] text-[24px] leading-tight font-medium tracking-[-0.02em] text-balance md:text-[32px]">
                {title}
            </h2>
            <div className="mt-10">{children}</div>
        </section>
    );
}

/** A still of the shared screen: the two voice lines and the canvases they refer to. */
function SharedScreen() {
    return (
        <div aria-hidden className="rounded-[22px] border border-line bg-bg p-3 shadow-[var(--shadow)] md:p-5">
            <div className="grid grid-cols-[minmax(0,1fr)] gap-3 md:grid-cols-2">
                <div className="rounded-2xl border border-line bg-surface p-4">
                    <div className="flex items-center gap-2 text-[13px]">
                        <span className="grid size-5 place-items-center rounded-full border border-line font-mono text-[10px] text-muted">1</span>
                        <span className="font-medium">Thursday</span>
                        <span className="text-muted">· Calendar</span>
                    </div>
                    <ul className="mt-3 space-y-1.5 text-[12.5px]">
                        <li className="flex gap-3 rounded-lg bg-soft px-3 py-2">
                            <span className="font-mono text-muted">09:30</span>Product daily
                        </li>
                        <li className="flex gap-3 rounded-lg bg-accent-soft px-3 py-2 text-accent">
                            <span className="font-mono">12:00</span>Prepayment review with Marta
                        </li>
                        <li className="flex gap-3 rounded-lg bg-soft px-3 py-2">
                            <span className="font-mono text-muted">16:00</span>Grupo Vela contract
                        </li>
                    </ul>
                </div>

                <div className="rounded-2xl border border-line bg-surface p-4">
                    <div className="flex items-center gap-2 text-[13px]">
                        <span className="grid size-5 place-items-center rounded-full border border-line font-mono text-[10px] text-muted">2</span>
                        <span className="font-medium">#product</span>
                        <span className="text-muted">· Slack</span>
                    </div>
                    <div className="mt-3 flex gap-2.5 text-[12.5px]">
                        <span className="grid size-7 flex-none place-items-center rounded-full bg-slack text-[11px] font-semibold text-bg">M</span>
                        <div>
                            <p>
                                <span className="font-medium">Marta Ruiz</span> <span className="text-faint">10:42</span>
                            </p>
                            <p className="mt-0.5 text-muted">Could you review the prepayment flow before 12? The PRD is linked in the thread.</p>
                        </div>
                    </div>
                    <div className="mt-3 rounded-lg border border-dashed border-accent px-3 py-2 text-[12.5px]">
                        <p className="text-[11px] text-accent">Draft · waiting for your “yes”</p>
                        <p className="mt-0.5">On it, I’ll send comments before 12.</p>
                    </div>
                </div>
            </div>

            <div className="mt-4 flex min-w-0 items-center gap-4 px-1 pb-1">
                <VoiceOrb status="speaking" />
                <div className="min-w-0 text-[14px] leading-snug">
                    <p className="truncate text-muted">“Reply to Marta that I’m on it”</p>
                    <p className="truncate">Here’s the reply in the thread. Shall I send it?</p>
                </div>
            </div>
        </div>
    );
}

export default function Landing({ canRegister, repositoryUrl }: LandingProps) {
    return (
        <>
            <Head title="Your workday, spoken" />

            <div lang="en" className="min-h-full px-4 md:px-6">
                <header className="mx-auto flex w-full max-w-[1080px] items-center justify-between gap-3 py-5">
                    <Wordmark className="h-5 sm:h-6" />
                    <nav className="flex min-w-0 items-center gap-1 text-[13.5px]">
                        <a
                            href={repositoryUrl}
                            className="hidden items-center gap-1.5 rounded-lg px-3 py-1.5 text-muted hover:bg-soft hover:text-ink sm:inline-flex"
                        >
                            <GitHubIcon />
                            GitHub
                        </a>
                        <Link href="/login" className="rounded-lg px-3 py-1.5 text-muted hover:bg-soft hover:text-ink">
                            Sign in
                        </Link>
                        {canRegister && (
                            <Link href="/register" className="rounded-lg bg-accent px-3 py-1.5 font-medium whitespace-nowrap text-accent-ink hover:opacity-90">
                                <span className="sm:hidden">Sign up</span>
                                <span className="hidden sm:inline">Create account</span>
                            </Link>
                        )}
                    </nav>
                </header>

                <main>
                    <section className="mx-auto grid w-full max-w-[1080px] grid-cols-[minmax(0,1fr)] gap-12 pt-10 pb-20 md:pt-20 md:pb-28 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:items-center">
                        <div>
                            <a
                                href={repositoryUrl}
                                className="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-[12.5px] text-muted hover:text-ink"
                            >
                                <i aria-hidden className="size-1.5 rounded-full bg-ok" />
                                Open source · MIT licensed
                            </a>
                            <h1 className="mt-5 text-[40px] leading-[1.05] font-medium tracking-[-0.035em] md:text-[56px]">
                                Your workday,
                                <br />
                                spoken.
                            </h1>
                            <p className="mt-5 max-w-[460px] text-[16px] leading-relaxed text-muted">
                                You talk; the AI answers out loud and, at the same time, puts on screen exactly what you’re talking about: your
                                calendar, an email, a Slack thread, a document.
                            </p>
                            <div className="mt-8 flex flex-wrap gap-3">
                                <a
                                    href={repositoryUrl}
                                    className="inline-flex items-center gap-2 rounded-[10px] bg-ink px-4 py-2.5 text-[14px] font-medium text-bg hover:opacity-90"
                                >
                                    <GitHubIcon />
                                    Get the code
                                </a>
                                <Link
                                    href={canRegister ? '/register' : '/login'}
                                    className="inline-flex items-center rounded-[10px] border border-line bg-surface px-4 py-2.5 text-[14px] font-medium hover:bg-soft"
                                >
                                    {canRegister ? 'Try it' : 'Sign in'}
                                </Link>
                            </div>
                        </div>

                        <SharedScreen />
                    </section>

                    <Section id="how" eyebrow="How it works" title="Not a dashboard, not a chatbot with a voice. A shared screen.">
                        <ol className="grid grid-cols-[minmax(0,1fr)] gap-8 md:grid-cols-3">
                            {steps.map((step, index) => (
                                <li key={step.title}>
                                    <span className="font-mono text-[13px] text-accent">0{index + 1}</span>
                                    <h3 className="mt-2 text-[16px] font-medium">{step.title}</h3>
                                    <p className="mt-1.5 text-[14px] leading-relaxed text-muted">{step.body}</p>
                                </li>
                            ))}
                        </ol>
                    </Section>

                    <Section id="features" eyebrow="Proactive, with judgement" title="It speaks up only when it matters, and tells you why.">
                        <div className="grid grid-cols-[minmax(0,1fr)] gap-x-12 gap-y-8 sm:grid-cols-2">
                            {features.map((feature) => (
                                <div key={feature.title}>
                                    <h3 className="text-[16px] font-medium">{feature.title}</h3>
                                    <p className="mt-1.5 text-[14px] leading-relaxed text-muted">{feature.body}</p>
                                </div>
                            ))}
                        </div>

                        <ul className="mt-12 flex flex-wrap gap-2" aria-label="Connected services">
                            {services.map((service) => (
                                <li key={service.name} className="flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1.5 text-[13px]">
                                    <ServiceIcon service={service.icon} size={15} label={false} />
                                    {service.name}
                                </li>
                            ))}
                        </ul>
                    </Section>

                    <Section id="open-source" eyebrow="Open source" title="Yours to read, run and change.">
                        <div className="grid grid-cols-[minmax(0,1fr)] gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                            <div className="space-y-4 text-[14.5px] leading-relaxed text-muted">
                                <p>
                                    Sixth Sense is open source under the <span className="text-ink">MIT license</span>. Every line that reads your email
                                    or talks to Slack is there for you to check.
                                </p>
                                <p>
                                    Run it on your own server with your own API keys: your accounts connect to your instance, OAuth tokens are stored
                                    encrypted, and audio goes straight from your browser to the voice provider, never through the server.
                                </p>
                                <p>Built with Laravel, Inertia, React and Gemini Live. Issues and pull requests are welcome.</p>
                                <a href={repositoryUrl} className="inline-flex items-center gap-2 pt-2 font-medium text-ink underline underline-offset-4">
                                    <GitHubIcon />
                                    {repositoryUrl.replace(/^https?:\/\/(www\.)?github\.com\//, '')}
                                </a>
                            </div>

                            <div className="min-w-0 overflow-hidden rounded-2xl border border-line bg-surface">
                                <p className="border-b border-line px-4 py-2.5 font-mono text-[12px] text-muted">Self-host in a few minutes</p>
                                <pre className="overflow-x-auto px-4 py-4 font-mono text-[12px] leading-relaxed md:text-[12.5px]">
                                    <code>{selfHost(repositoryUrl)}</code>
                                </pre>
                            </div>
                        </div>
                    </Section>
                </main>

                <footer className="mx-auto flex w-full max-w-[1080px] flex-wrap items-center justify-between gap-3 border-t border-line py-8 text-[13px] text-muted">
                    <span>Made with voice, Laravel and a lot of white space.</span>
                    <span className="flex gap-4">
                        <a href={repositoryUrl} className="hover:text-ink">
                            Source
                        </a>
                        <Link href="/login" className="hover:text-ink">
                            Sign in
                        </Link>
                    </span>
                </footer>
            </div>
        </>
    );
}
