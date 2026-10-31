<?php

namespace App\Console\Commands;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Triage;
use App\Events\InboxItemUpdated;
use App\Jobs\PlanAlertReply;
use App\Models\InboxItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:replan {--user= : Solo los avisos de esta persona (id)} {--id=* : Solo estos avisos} {--days=3 : Recibidos en los últimos N días} {--limit=20 : Como mucho N avisos} {--dry : Solo lista los que replanificaría}')]
#[Description('Plan pending alerts again with the current strategy: classifier, research, calls and tasks, sources and recipient')]
class ReplanAlerts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $alerts = InboxItem::query()
            ->where('status', AlertStatus::Pending)
            ->where('triage', Triage::Important)
            ->whereIn('source', [AlertSource::Gmail, AlertSource::Slack, AlertSource::Jira])
            ->when($this->option('user'), fn ($query, $user) => $query->where('user_id', (int) $user))
            ->when($this->option('id'), fn ($query, $ids) => $query->whereKey(array_map('intval', $ids)))
            ->when(! $this->option('id'), fn ($query) => $query->where('received_at', '>=', now()->subDays((int) $this->option('days'))))
            ->latest('received_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($this->option('dry')) {
            $this->table(['Id', 'Fuente', 'De', 'Título'], $alerts->map(fn (InboxItem $alert): array => [$alert->id, $alert->source->label(), $alert->actor_name, mb_strimwidth((string) $alert->title, 0, 60, '…')]));

            return self::SUCCESS;
        }

        // The proposal shows as being prepared again; the new one replaces it when ready.
        $alerts->each(function (InboxItem $alert): void {
            $alert->update(['planning' => true]);
            InboxItemUpdated::dispatch($alert);
            PlanAlertReply::dispatch($alert);
        });

        $this->components->info("{$alerts->count()} avisos en cola para replanificar.");

        return self::SUCCESS;
    }
}
