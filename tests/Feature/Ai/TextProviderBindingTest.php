<?php

use App\Alerts\Judges\LlmMeetingAdvisor;
use App\Alerts\Judges\LlmRelevanceJudge;
use App\Alerts\MeetingAdvisor;
use App\Alerts\RelevanceJudge;

test('a gemini judge setting still uses the configured text provider', function () {
    config(['alerts.judge' => 'gemini', 'ai.text' => 'deepseek']);

    expect(app(RelevanceJudge::class))->toBeInstanceOf(LlmRelevanceJudge::class)
        ->and(app(MeetingAdvisor::class))->toBeInstanceOf(LlmMeetingAdvisor::class);
});

test('the text provider can be switched back to gemini', function () {
    config(['alerts.judge' => 'llm', 'ai.text' => 'gemini']);

    expect(app(RelevanceJudge::class))->toBeInstanceOf(LlmRelevanceJudge::class);
});
