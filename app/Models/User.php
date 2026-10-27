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

#[Fillable(['name', 'email', 'password', 'display_size', 'voice', 'wingman_enabled', 'wingman_settings', 'wingman_token_hash', 'wingman_token_used_at'])]
#[Hidden(['password', 'remember_token', 'wingman_token_hash', 'gemini_api_key', 'deepseek_api_key'])]
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
        'wingman_enabled' => false,
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
            'wingman_enabled' => 'boolean',
            'wingman_settings' => 'array',
            'wingman_token_used_at' => 'immutable_datetime',
            'gemini_api_key' => 'encrypted',
            'deepseek_api_key' => 'encrypted',
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
     * Compound requests opened for this person.
     *
     * @return HasMany<Scene, $this>
     */
    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
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
     * Agents copied from the work repository.
     *
     * @return HasMany<SenseAgent, $this>
     */
    public function senseAgents(): HasMany
    {
        return $this->hasMany(SenseAgent::class);
    }

    /**
     * The one GitHub repository this person uses as their work archive.
     */
    public function workRepository(): ?GithubRepository
    {
        return GithubRepository::query()
            ->where('is_work', true)
            ->whereIn('connected_account_id', $this->connectedAccounts()->select('id'))
            ->first();
    }

    /**
     * What the reply agent has learned while resolving intents.
     *
     * @return HasMany<IntentLesson, $this>
     */
    public function intentLessons(): HasMany
    {
        return $this->hasMany(IntentLesson::class);
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
     * Browsers registered for system notifications.
     *
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * @return HasMany<MeetingReminder, $this>
     */
    public function meetingReminders(): HasMany
    {
        return $this->hasMany(MeetingReminder::class);
    }

    /**
     * @return HasMany<WingmanMeeting, $this>
     */
    public function wingmanMeetings(): HasMany
    {
        return $this->hasMany(WingmanMeeting::class);
    }
}
