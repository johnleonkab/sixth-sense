<?php

namespace App\Providers;

use App\Alerts\Judges\FakeMeetingAdvisor;
use App\Alerts\Judges\FakeRelevanceJudge;
use App\Alerts\Judges\GeminiMeetingAdvisor;
use App\Alerts\Judges\GeminiRelevanceJudge;
use App\Alerts\MeetingAdvisor;
use App\Alerts\RelevanceJudge;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\GeminiReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Calendar\FakeCalendarWriter;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\FakeDocuments;
use App\Connectors\Google\GmailMailbox;
use App\Connectors\Google\GmailOutbox;
use App\Connectors\Google\GoogleCalendarReader;
use App\Connectors\Google\GoogleCalendarWriter;
use App\Connectors\Google\GoogleDocs;
use App\Connectors\Issues\FakeIssueTracker;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Jira\JiraIssueTracker;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\FakeOutbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\Outbox;
use App\Connectors\Notion\NotionWiki;
use App\Connectors\Slack\SlackChats;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Scheduling\Assessors\FakeTaskAssessor;
use App\Scheduling\Assessors\GeminiTaskAssessor;
use App\Scheduling\TaskAssessor;
use App\Voice\Providers\FakeVoiceProvider;
use App\Voice\Providers\GeminiVoiceProvider;
use App\Voice\VoiceProvider;
use App\Voice\VoiceSamples;
use App\Wingman\Notes\FakeNoteTaker;
use App\Wingman\Notes\GeminiNoteTaker;
use App\Wingman\Notes\NoteTaker;
use App\WorkContext\ContextSummarizer;
use App\WorkContext\Summarizers\FakeContextSummarizer;
use App\WorkContext\Summarizers\GeminiContextSummarizer;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CalendarReader::class, fn (): CalendarReader => config('connectors.demo')
            ? new FakeCalendarReader
            : $this->app->make(GoogleCalendarReader::class));

        $this->app->singleton(CalendarWriter::class, fn (): CalendarWriter => config('connectors.demo')
            ? new FakeCalendarWriter
            : $this->app->make(GoogleCalendarWriter::class));

        // The fake keeps pages, edits and comments in memory: one instance per request.
        $this->app->singleton(Wiki::class, fn (): Wiki => config('connectors.demo')
            ? new FakeWiki
            : $this->app->make(NotionWiki::class));

        $this->app->singleton(IssueTracker::class, fn (): IssueTracker => config('connectors.demo')
            ? new FakeIssueTracker
            : $this->app->make(JiraIssueTracker::class));

        $this->app->singleton(Chats::class, fn (): Chats => config('connectors.demo')
            ? new FakeChats
            : $this->app->make(SlackChats::class));

        $this->app->bind(Mailbox::class, fn (): Mailbox => config('connectors.demo')
            ? new FakeMailbox
            : $this->app->make(GmailMailbox::class));

        $this->app->singleton(Outbox::class, fn (): Outbox => config('connectors.demo')
            ? new FakeOutbox
            : $this->app->make(GmailOutbox::class));

        // The fake keeps edits in memory, so it must be the same instance for the whole request.
        $this->app->singleton(Documents::class, fn (): Documents => config('connectors.demo')
            ? new FakeDocuments
            : $this->app->make(GoogleDocs::class));

        $this->app->bind(RelevanceJudge::class, fn (): RelevanceJudge => match (config('alerts.judge')) {
            'gemini' => new GeminiRelevanceJudge(self::lightGemini()),
            'fake' => new FakeRelevanceJudge,
            default => throw new InvalidArgumentException('Unsupported alerts judge ['.config('alerts.judge').'].'),
        });

        // The reply engine is its own piece: it can become another agent without touching the alerts.
        $this->app->bind(ReplyPlanner::class, fn (): ReplyPlanner => match (config('alerts.planner')) {
            'gemini' => new GeminiReplyPlanner([...config('alerts.judges.gemini'), 'fallback_model' => config('alerts.judges.gemini.judge_model')]),
            'fake' => new FakeReplyPlanner,
            default => throw new InvalidArgumentException('Unsupported reply planner ['.config('alerts.planner').'].'),
        });

        // The scheduler's judgement is its own piece too: urgency and length of each task.
        $this->app->bind(TaskAssessor::class, fn (): TaskAssessor => match (config('agenda.assessor')) {
            'gemini' => new GeminiTaskAssessor([...config('alerts.judges.gemini'), 'fallback_model' => config('alerts.judges.gemini.judge_model')]),
            'fake' => new FakeTaskAssessor,
            default => throw new InvalidArgumentException('Unsupported task assessor ['.config('agenda.assessor').'].'),
        });

        $this->app->bind(MeetingAdvisor::class, fn (): MeetingAdvisor => match (config('alerts.judge')) {
            'gemini' => new GeminiMeetingAdvisor(self::lightGemini()),
            'fake' => new FakeMeetingAdvisor,
            default => throw new InvalidArgumentException('Unsupported alerts judge ['.config('alerts.judge').'].'),
        });

        // A week of activity is a long read: the capable model, with more time.
        $this->app->bind(ContextSummarizer::class, fn (): ContextSummarizer => match (config('work_context.summarizer')) {
            'gemini' => new GeminiContextSummarizer([
                ...config('alerts.judges.gemini'),
                'fallback_model' => config('alerts.judges.gemini.judge_model'),
                'timeout' => config('work_context.timeout'),
            ]),
            'fake' => new FakeContextSummarizer,
            default => throw new InvalidArgumentException('Unsupported work context summarizer ['.config('work_context.summarizer').'].'),
        });

        $this->app->singleton(VoiceProvider::class, fn (): VoiceProvider => match (config('voice.default')) {
            'gemini' => new GeminiVoiceProvider(config('voice.providers.gemini'), config('voice.session')),
            'fake' => new FakeVoiceProvider,
            default => throw new InvalidArgumentException('Unsupported voice provider ['.config('voice.default').'].'),
        });

        $this->app->bind(NoteTaker::class, fn (): NoteTaker => match (config('wingman.notes.taker')) {
            'gemini' => new GeminiNoteTaker([...config('alerts.judges.gemini'), 'model' => config('wingman.notes.model'), 'fallback_model' => config('alerts.judges.gemini.judge_model')]),
            'fake' => new FakeNoteTaker,
            default => throw new InvalidArgumentException('Unsupported Wingman note-taker ['.config('wingman.notes.taker').'].'),
        });

        $this->app->singleton(VoiceSamples::class, fn (): VoiceSamples => new VoiceSamples(config('voice.providers.gemini')));
    }

    /**
     * Gemini settings for the calls made on every new message: the light model.
     *
     * @return array<string, mixed>
     */
    private static function lightGemini(): array
    {
        $config = config('alerts.judges.gemini');

        return [...$config, 'model' => $config['judge_model']];
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DevCommands::artisan('schedule:work', 'scheduler');

        // Reverb may already run inside Docker, which publishes its port: then `composer dev` does not start another.
        DevCommands::except('queue', 'server', ...($this->reverbAlreadyListening() ? ['reverb'] : []));
    }

    /**
     * Whether something already listens on Reverb's port. Only checked when
     * `php artisan dev` starts, never on web requests.
     */
    private function reverbAlreadyListening(): bool
    {
        if (! $this->app->runningInConsole() || ($_SERVER['argv'][1] ?? null) !== 'dev') {
            return false;
        }

        $socket = @fsockopen('127.0.0.1', (int) config('reverb.servers.reverb.port'), $errno, $error, 0.3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
