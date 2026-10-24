<?php

namespace App\Providers;

use App\Ai\DeepSeekTextModel;
use App\Ai\GeminiTextModel;
use App\Ai\TextModel;
use App\Alerts\Judges\FakeMeetingAdvisor;
use App\Alerts\Judges\FakeRelevanceJudge;
use App\Alerts\Judges\LlmMeetingAdvisor;
use App\Alerts\Judges\LlmRelevanceJudge;
use App\Alerts\MeetingAdvisor;
use App\Alerts\RelevanceJudge;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\LlmReplyPlanner;
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
use App\Scenes\DeepSeekSceneText;
use App\Scenes\SceneText;
use App\Scheduling\Assessors\FakeTaskAssessor;
use App\Scheduling\Assessors\LlmTaskAssessor;
use App\Scheduling\TaskAssessor;
use App\Voice\Providers\FakeVoiceProvider;
use App\Voice\Providers\GeminiVoiceProvider;
use App\Voice\VoiceProvider;
use App\Voice\VoiceSamples;
use App\Wingman\Closing\FakeFollowUpPlanner;
use App\Wingman\Closing\FollowUpPlanner;
use App\Wingman\Closing\LlmFollowUpPlanner;
use App\Wingman\Copilot\Detector;
use App\Wingman\Copilot\FakeDetector;
use App\Wingman\Copilot\LlmDetector;
use App\Wingman\Notes\FakeNoteTaker;
use App\Wingman\Notes\LlmNoteTaker;
use App\Wingman\Notes\NoteTaker;
use App\WorkContext\ActivityBrief;
use App\WorkContext\ContextSummarizer;
use App\WorkContext\Summarizers\FakeContextSummarizer;
use App\WorkContext\Summarizers\LlmContextSummarizer;
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

        $this->app->singleton(SceneText::class, DeepSeekSceneText::class);

        $this->app->bind(RelevanceJudge::class, fn (): RelevanceJudge => match (true) {
            $this->usesTextModel(config('alerts.judge')) => new LlmRelevanceJudge($this->textModel('light')),
            config('alerts.judge') === 'fake' => new FakeRelevanceJudge,
            default => throw new InvalidArgumentException('Unsupported alerts judge ['.config('alerts.judge').'].'),
        });

        // The reply engine is its own piece: it can become another agent without touching the alerts.
        $this->app->bind(ReplyPlanner::class, fn (): ReplyPlanner => match (true) {
            $this->usesTextModel(config('alerts.planner')) => new LlmReplyPlanner($this->textModel('capable')),
            config('alerts.planner') === 'fake' => new FakeReplyPlanner,
            default => throw new InvalidArgumentException('Unsupported reply planner ['.config('alerts.planner').'].'),
        });

        // The scheduler's judgement is its own piece too: urgency and length of each task.
        $this->app->bind(TaskAssessor::class, fn (): TaskAssessor => match (true) {
            $this->usesTextModel(config('agenda.assessor')) => new LlmTaskAssessor($this->textModel('capable')),
            config('agenda.assessor') === 'fake' => new FakeTaskAssessor,
            default => throw new InvalidArgumentException('Unsupported task assessor ['.config('agenda.assessor').'].'),
        });

        $this->app->bind(MeetingAdvisor::class, fn (): MeetingAdvisor => match (true) {
            $this->usesTextModel(config('alerts.judge')) => new LlmMeetingAdvisor($this->textModel('light')),
            config('alerts.judge') === 'fake' => new FakeMeetingAdvisor,
            default => throw new InvalidArgumentException('Unsupported alerts judge ['.config('alerts.judge').'].'),
        });

        // A week of activity is a long read: the capable model, with more time.
        $this->app->bind(ContextSummarizer::class, fn (): ContextSummarizer => match (true) {
            $this->usesTextModel(config('work_context.summarizer')) => new LlmContextSummarizer(
                $this->textModel('capable', (int) config('work_context.timeout')),
                $this->app->make(ActivityBrief::class),
                $this->app->make(IssueTracker::class),
            ),
            config('work_context.summarizer') === 'fake' => new FakeContextSummarizer,
            default => throw new InvalidArgumentException('Unsupported work context summarizer ['.config('work_context.summarizer').'].'),
        });

        $this->app->singleton(VoiceProvider::class, fn (): VoiceProvider => match (config('voice.default')) {
            'gemini' => new GeminiVoiceProvider(config('voice.providers.gemini'), config('voice.session')),
            'fake' => new FakeVoiceProvider,
            default => throw new InvalidArgumentException('Unsupported voice provider ['.config('voice.default').'].'),
        });

        $this->app->bind(NoteTaker::class, fn (): NoteTaker => match (true) {
            $this->usesTextModel(config('wingman.notes.taker')) => new LlmNoteTaker($this->textModel('capable', model: config('wingman.notes.model'))),
            config('wingman.notes.taker') === 'fake' => new FakeNoteTaker,
            default => throw new InvalidArgumentException('Unsupported Wingman note-taker ['.config('wingman.notes.taker').'].'),
        });

        $this->app->bind(Detector::class, fn (): Detector => match (true) {
            $this->usesTextModel(config('wingman.agent.detector')) => new LlmDetector($this->textModel('capable', model: config('wingman.agent.model'))),
            config('wingman.agent.detector') === 'fake' => new FakeDetector,
            default => throw new InvalidArgumentException('Unsupported Wingman detector ['.config('wingman.agent.detector').'].'),
        });

        $this->app->bind(FollowUpPlanner::class, fn (): FollowUpPlanner => match (true) {
            $this->usesTextModel(config('wingman.follow_ups.planner')) => new LlmFollowUpPlanner($this->textModel('capable', model: config('wingman.follow_ups.model'))),
            config('wingman.follow_ups.planner') === 'fake' => new FakeFollowUpPlanner,
            default => throw new InvalidArgumentException('Unsupported Wingman follow-up planner ['.config('wingman.follow_ups.planner').'].'),
        });

        $this->app->singleton(VoiceSamples::class, fn (): VoiceSamples => new VoiceSamples(config('voice.providers.gemini')));
    }

    /**
     * "gemini" remains an alias of the real model, so an existing env still works.
     * Which HTTP provider answers is AI_TEXT_PROVIDER, not this driver.
     */
    private function usesTextModel(mixed $driver): bool
    {
        return in_array($driver, ['llm', 'gemini'], true);
    }

    /**
     * The light model answers every new message. The capable one thinks a proposal through.
     */
    private function textModel(string $tier, ?int $timeout = null, ?string $model = null): TextModel
    {
        $provider = config('ai.text');
        $config = config("ai.providers.{$provider}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unsupported text provider [{$provider}].");
        }

        $options = [
            'api_key' => $config['api_key'],
            'base_url' => $config['base_url'],
            'model' => filled($model) ? $model : ($tier === 'light' ? $config['light_model'] : $config['model']),
            'fallback_model' => $config['fallback_model'],
            'timeout' => $timeout ?? $config['timeout'],
        ];

        if ($provider === 'deepseek') {
            return new DeepSeekTextModel([
                ...$options,
                'max_tokens' => $tier === 'light' ? $config['light_max_tokens'] : $config['max_tokens'],
                'thinking' => $tier === 'light' ? 'disabled' : $config['thinking'],
            ]);
        }

        if ($provider === 'gemini') {
            return new GeminiTextModel([...$options, 'thinking_level' => $config['thinking_level']]);
        }

        throw new InvalidArgumentException("Unsupported text provider [{$provider}].");
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
