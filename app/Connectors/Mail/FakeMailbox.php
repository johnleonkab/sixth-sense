<?php

namespace App\Connectors\Mail;

use App\Connectors\Docs\FakeDocuments;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Sample inbox in the spirit of docs/prototype.html, for tests and demo mode.
 */
class FakeMailbox implements Mailbox
{
    /** @var list<MailSearch> */
    public array $searches = [];

    public function search(User $user, MailSearch $search): MailSearchResult
    {
        $this->searches[] = $search;

        $found = [];

        foreach ($this->messages() as $message) {
            if (! $this->matches($message, $search)) {
                continue;
            }

            if ($search->pattern === null) {
                $found[] = new FoundMessage($message);
            } elseif (($excerpt = $search->pattern->excerptFor($message)) !== null) {
                $found[] = new FoundMessage($message, $excerpt);
            }
        }

        return new MailSearchResult(
            messages: array_slice($found, 0, $search->limit),
            accounts: ['demo@sixth-sense.test'],
            scanned: $search->pattern ? count($this->messages()) : 0,
            mayHaveMore: count($found) > $search->limit,
        );
    }

    public function message(User $user, string $ref): ?MailMessage
    {
        return collect($this->messages())->firstWhere('ref', $ref);
    }

    /** @var list<string> Messages the user has answered, for tests. */
    public array $answered = [];

    public function repliedAfter(User $user, string $ref): bool
    {
        return in_array($ref, $this->answered, true);
    }

    public function attachment(User $user, string $ref, int $index): ?string
    {
        $attachment = $this->message($user, $ref)?->attachments[$index] ?? null;

        // Every sample attachment is a PDF: they all get the same small one.
        return $attachment ? self::samplePdf() : null;
    }

    /**
     * A valid two-page PDF with a line of text on each page.
     */
    public static function samplePdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 7 0 R >> >> >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 6 0 R /Resources << /Font << /F1 7 0 R >> >> >>',
        ];

        foreach (['Flujo de prepago: el cliente ve el importe total en el paso 3.', 'Reembolso parcial en el paso 5, revisado por Riesgos.'] as $line) {
            $stream = "BT /F1 14 Tf 60 760 Td ({$line}) Tj ET";
            $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function matches(MailMessage $message, MailSearch $search): bool
    {
        $contains = fn (?string $haystack, string $needle): bool => Str::contains(Str::lower(Str::ascii((string) $haystack)), Str::lower(Str::ascii($needle)));

        return ($search->from === null || $contains($message->from?->name.' '.$message->from?->email, $search->from))
            && ($search->subject === null || $contains($message->subject, $search->subject))
            && ($search->text === null || $contains($message->subject.' '.$message->body, $search->text))
            && ($search->after === null || $message->date->greaterThanOrEqualTo($search->after))
            && ($search->before === null || $message->date->lessThanOrEqualTo($search->before->endOfDay()))
            && (! $search->unreadOnly || $message->unread)
            && (! $search->hasAttachment || $message->attachments !== []);
    }

    /**
     * @return list<MailMessage>
     */
    private function messages(): array
    {
        $today = CarbonImmutable::today();
        $marta = new MailAddress('Marta Ruiz', 'marta@covermanager.test');
        $pablo = new MailAddress('Pablo Gil', 'pablo@covermanager.test');
        $vela = new MailAddress('Andrés Vela', 'andres@grupovela.test');
        $me = new MailAddress('John León', 'demo@sixth-sense.test');

        return [
            new MailMessage(
                ref: 'demo.1', account: 'demo@sixth-sense.test', from: $marta, to: [$me],
                subject: 'Flujo de prepago v4: necesito tu revisión',
                date: $today->setTime(8, 47), unread: true,
                snippet: 'He subido a Figma la versión nueva del flujo de prepago.',
                body: "Hola John,\n\nHe subido a Figma la versión nueva del flujo de prepago. El cambio grande está en el paso 3: el cliente ve el importe total antes de elegir mesa.\n\nEl PRD está aquí: https://docs.google.com/document/d/".FakeDocuments::PREPAGO_ID."/edit\n\n¿Puedes revisarlo antes de la 1:1 de las 12?\n\nGracias,\nMarta",
                attachments: [['name' => 'prepago-v4.pdf', 'mime_type' => 'application/pdf', 'size' => 482_113]],
            ),
            new MailMessage(
                ref: 'demo.2', account: 'demo@sixth-sense.test', from: $vela, to: [$me], cc: [$pablo],
                subject: 'Contrato Grupo Vela — versión final',
                date: $today->subDay()->setTime(17, 5),
                snippet: 'Os adjunto la versión final del contrato con los cambios de la cláusula de reembolso.',
                body: "Buenas tardes,\n\nOs adjunto la versión final del contrato con los cambios de la cláusula de reembolso parcial que comentamos.\n\nNecesitaríamos la firma antes del viernes.\n\nUn saludo,\nAndrés Vela\nGrupo Vela",
                attachments: [['name' => 'Contrato_Grupo_Vela_final.pdf', 'mime_type' => 'application/pdf', 'size' => 1_204_551]],
            ),
            new MailMessage(
                ref: 'demo.3', account: 'demo@sixth-sense.test', from: $pablo, to: [$me, $marta],
                subject: 'Re: Reembolsos parciales',
                date: $today->subDays(2)->setTime(11, 30),
                snippet: 'Desde Pagos lo vemos bien, siempre que el reembolso parcial siga en el paso 5.',
                body: "Desde Pagos lo vemos bien, siempre que el reembolso parcial siga en el paso 5 del prepago. Si se mueve, hay que volver a pasar la revisión de riesgos.\n\nPablo",
            ),
            new MailMessage(
                ref: 'demo.4', account: 'demo@sixth-sense.test', from: $marta, to: [$me],
                subject: 'Notas de la daily',
                date: $today->subDays(3)->setTime(10, 2),
                snippet: 'Resumen rápido de la daily de hoy.',
                body: "Resumen rápido de la daily de hoy:\n• Lucía cierra la migración de reservas.\n• El jueves hacemos la demo a ventas.\n• Pendiente: decidir el texto del recordatorio de pago.\n\nMarta",
            ),
        ];
    }
}
