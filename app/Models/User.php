<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Alerts\AlertSource;
use App\Connections\Capability;
use App\Settings\DisplaySize;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'display_size', 'voice'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * New users start with the usual display size.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'display_size' => 'normal',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'display_size' => DisplaySize::class,
        ];
    }

    /**
     * External accounts this user has connected, any number per provider.
     *
     * @return HasMany<ConnectedAccount, $this>
     */
    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    /**
     * Every account on which sixth-sense may use this capability. Reads combine
     * all of them (for example, the work and the personal calendar).
     *
     * @return Collection<int, ConnectedAccount>
     */
    public function accountsWith(Capability $capability): Collection
    {
        return $this->connectedAccounts()->withCapability($capability)->oldest()->get();
    }

    /**
     * Changes the assistant proposed, waiting for or after confirmation.
     *
     * @return HasMany<PendingAction, $this>
     */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class);
    }

    /**
     * @return HasMany<AlertSetting, $this>
     */
    public function alertSettings(): HasMany
    {
        return $this->hasMany(AlertSetting::class);
    }

    /**
     * Whether the user wants proactive alerts from this source. Off until they switch it on.
     */
    public function wantsAlertsFrom(AlertSource $source): bool
    {
        return (bool) $this->alertSettings()->where('source', $source)->value('enabled');
    }

    /**
     * What the user is working on lately, when they want the assistant to know.
     *
     * @return HasOne<WorkContext, $this>
     */
    public function workContext(): HasOne
    {
        return $this->hasOne(WorkContext::class);
    }

    /**
     * What the scheduler found time for.
     *
     * @return HasMany<AgendaTask, $this>
     */
    public function agendaTasks(): HasMany
    {
        return $this->hasMany(AgendaTask::class);
    }

    /**
     * Alerts raised for this user, newest first.
     *
     * @return HasMany<InboxItem, $this>
     */
    public function inboxItems(): HasMany
    {
        return $this->hasMany(InboxItem::class)->latest('received_at');
    }

    /**
     * @return HasMany<MeetingReminder, $this>
     */
    public function meetingReminders(): HasMany
    {
        return $this->hasMany(MeetingReminder::class);
    }
}
