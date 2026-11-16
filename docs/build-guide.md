# Build guide — comms pipeline v2

Audience: the coding model that builds [execution-plan.md](execution-plan.md) from M1 onwards.
Written 2026-11-16 by the architect who wrote the plan. Work on branch `comms-v2`.

The plan says **what** and **why**. This guide says **in which order, in which files, with which
tests**. When they disagree, the plan wins; when the plan disagrees with
[linking-intents-and-surface-proposal.md](linking-intents-and-surface-proposal.md) section 1, the
proposal wins. Stop and ask the user before deviating from any of them.

---

## 1. How to work

1. **One ticket per commit.** Read the ticket, read the files it names, write the tests first,
   then the code. Do not start the next ticket with a red suite.
2. **Before writing code, read:** `CLAUDE.md`; the plan section the ticket cites; the agent or stage
   definition in [agent-definitions.md](agent-definitions.md); sibling files for conventions.
3. **Run:** the ticket's tests with `php artisan test --compact --filter=...`; then the whole suite
   (`php artisan test --compact`, ~2 minutes, must stay green); then
   `vendor/bin/pint --dirty --format agent`.
4. **Migrations** must also pass on MySQL (section 4). Run the MySQL check for every ticket that adds
   or changes a migration.
5. **Commit** in English, one sentence saying what changes for the user or the system, ending with
   the attribution line the session gives you. Never push or deploy; the user does.
6. **Do not:** add packages or database extensions; edit a released prompt file (add `v2.md` and bump
   `config/comms.php`); change the decision code in `app/Comms/Linking` without the user's yes (it is
   the contract, with tests); add shadow modes or flags beyond the three in `comms.linking`; call a
   provider in tests (use the fakes); let any agent have tools.

## 2. What is already built (do not rebuild)

| Piece | Where | Notes |
|---|---|---|
| Tables and columns of plan 4.1/4.2 | `database/migrations/2026_11_16_0109*` | No Eloquent models yet; create each with the ticket that first uses it, under `App\Models\Comms`. |
| Event types | `App\Comms\Events\EventType` (`WindowSettled` … `UserViewed`) | |
| Config | `config/comms.php`: tasks `segment`, `link`, `extract`, `critic`, `dossier_write`, `reconcile`, `topic_build`, `ask_pattern`, `identity_resolve`; keys `linking`, `settle`, `window`, `agreement`, `examples`, `backfill`, `reconcile`, `embeddings`, `budgets.per_window` | `extract` is the "match v2" of the design docs. The old `match` task stays for the v1 matcher. |
| Linker vote → placement | `App\Comms\Linking\LinkVote`, `LinkDecision`, `LinkOutcome`, `LinkCertainty`, `LinkChoice` | Tested in `tests/Unit/Comms/LinkingDecisionsTest.php`. |
| Extractor runs → agreement | `ExtractionAgreement`, `AgreedOperation`, `AgreementStatus` | Takes a similarity closure; you inject embeddings cosine. |
| Critic answer → refuted or not | `CriticVerdict` | |
| Router table | `SegmentRouter`, `RouteDecision` | |
| Quote check | `Quote::in()`, `Quote::inAny()` | `IntentStateMachine` already uses it. Use it everywhere a quote is checked. |
| Agent schemas | `App\Comms\Linking\Schemas` | Pass to `ModelRouter::ask()`. |
| Prompts v1 | `resources/prompts/comms/{segment,link,extract,critic,dossier_write,reconcile,topic_build}/v1.md` | Each starts by listing the **input sections** your input builder must produce, in that order and with those headings. |
| Home group rule | `App\Comms\Surface\BallHolder`, `BallGroup` (with Spanish labels) | You load the facts; it decides. |
| Architecture tests | `tests/Unit/CommsArchTest.php` | Add a rule when a ticket says so. |

Missing prompts you write (follow the style of the existing ones; English; content-as-data
sentence; input sections first): `ask_pattern/v1.md`, `identity_resolve/v1.md`.

## 3. Shared conventions for the new code

- **Namespaces:** `App\Comms\Windows` (settle buffer, assembler), `App\Comms\Linking` (segmenter,
  retriever, link stage, extract stage, critic stage, pipeline v2), `App\Comms\Topics` (topic
  builder, dossier writer, ask patterns), `App\Comms\Backfill`, `App\Comms\Nightly`,
  `App\Comms\Surface`. Models in `App\Models\Comms`.
- **Input builders** render exactly the sections the prompt lists. Every piece of source content is
  wrapped: `<source id="m{message_id}" author="person:{id}" at="{ISO time}">text</source>`. Ids in
  prompts are strings (`m481`, `person:12`, `481` for intents, `workstream:{slug}` for topics);
  convert back in code and reject ids that were not in the input.
- **Every agent call** goes through `ModelRouter` (never `TextModel` directly) and every output is
  read through the classes in section 2. Agents propose, stages decide, the state machine applies.
- **Events:** every state change is an event appended with `EventLog::append()`; projections are
  rebuilt from events. A new projection table is added to `Projections::clear()` and
  `capture()`, and gets a replay test.
- **People ids** in prompts are `person:{comms_people.id}`; the user is `person:{self id}`.
- **Time:** store UTC; compute deadlines and "today" in `users.timezone`.

## 4. MySQL check

Production is MySQL 8; local and tests are SQLite. SQLite accepts things MySQL rejects. For every
migration ticket:

```bash
docker run -d --name sixth-sense-mysql-check -e MYSQL_ROOT_PASSWORD=secret \
  -e MYSQL_DATABASE=sixth_sense_testing -e MYSQL_USER=sixth_sense -e MYSQL_PASSWORD=secret \
  -p 3307:3306 mysql:8.0 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci \
  --log-bin-trust-function-creators=1
# wait until `docker logs sixth-sense-mysql-check` says "ready for connections ... port: 3306"
export M="DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=sixth_sense_testing DB_USERNAME=sixth_sense DB_PASSWORD=secret DB_URL="
env $M php artisan migrate:fresh --force && env $M php artisan migrate:rollback --step=1 --force && env $M php artisan migrate --force
env $M php artisan test --compact tests/Feature/Comms
docker rm -f sixth-sense-mysql-check
```

Known: 5 knowledge tests fail on MySQL only because InnoDB full text does not see rows inside the
test transaction (ticket T1.6 fixes it). Anything else failing on MySQL is yours.

## 5. Traps already found (each cost time once)

1. **Index names over 64 characters** fail on MySQL only. Name long composite indexes explicitly.
2. **Full-text indexes** do not exist on SQLite: wrap `fullText()` in a driver check
   (see `2026_11_16_010901_create_comms_linking_tables.php`); search falls back to `LIKE` on SQLite.
3. **MySQL reorders JSON keys.** Never assert JSON columns with `toBe()` on associative arrays; use
   `toEqual()`. Never hash a JSON column read back from the database.
4. **Rows with the same timestamp** come back in different orders per driver. Always add a second
   `orderBy('id')`.
5. **Stale models in tests:** `update()` on a model whose in-memory value already equals the new
   value writes nothing. `refresh()` before resetting state.
6. **`Http::retry()` throws on failed responses** unless `throw: false`. Every connector expects a
   response back.
7. **The event log is append-only** (database triggers). Only `processed_at` can be updated; nothing
   can be deleted. Corrections are new events.
8. **Subjects keep names as written** (`person:marta ruiz`). Compare people through `Str::slug()`.

---

## 6. Tickets

Format: **Id — title** · depends on · plan section. Then *Build*, *Tests*, *Done when*.

### M1 — Foundations (rest)

**T1.1 — Keyed decisions and N runs** · — · plan M1, impl. plan 3.4
*Build.* In `ModelRouter::ask()` add optional `?int $runIndex = null` and `?int $windowId = null`.
Today decisions are appended with `externalId: null`. Give them a key:
`{task}:` . `sha1(json_encode([$eventIds, $version, $runIndex, sha1($input)]))`. Before calling a
provider, look up an existing `model.decided` event for the user with that `external_id`; if found,
return its stored output (a crash retry or a replay costs nothing). Write `run_index` and
`window_id` into `ai_usages`. Add `runs(User $user, string $task, string $input, array $schema, array $eventIds, ?int $windowId, Closure $inputForRun): list<ModelAnswer>`
that reads `config("comms.tasks.{$task}.runs", 1)` and calls `ask()` once per run with
`$inputForRun($runIndex)` (so the caller shuffles candidates per run) and that run index.
Remove `match` from `escalate_on_confidence` only when T4.1 lands, not now (v1 still uses it).
*Tests.* Same input twice → one provider call, same answer. Three runs → three `model.decided`
events with distinct keys and `run_index` 0, 1, 2 in `ai_usages`. Existing Phase0 tests stay green.
*Done when* the above pass.

**T1.2 — Embeddings** · — · plan 1.1, M1
*Build.* `App\Comms\Models\Embeddings` with `vector(string $text): ?array` and
`static cosine(array $a, array $b): float`. Gemini endpoint:
`POST {gemini base_url}/v1beta/models/{model}:embedContent` with header `x-goog-api-key` and body
`{"content": {"parts": [{"text": "..."}]}}`; the vector is `embedding.values`. Truncate input to
8 000 characters. Cache in `comms_embeddings` (`hash = sha1(text)`, `model`), model
`CommsEmbedding`. On any failure return `null` (never throw). Bind a `FakeEmbeddings` in tests that
returns a deterministic vector from the words of the text (bag of hashed words), so equal texts are
1.0 and unrelated texts are low.
Then in `IntentStateMachine` replace the `similar_text` duplicate check with cosine
(`config('comms.candidates.duplicate_cosine', 0.92)`), falling back to `similar_text` when either
vector is null. Same in `ContextEngine::search` re-ranking.
*Tests.* Cache hit makes no HTTP call; failure returns null and the duplicate check still works with
the fallback; two near-identical `what` sentences are converted from `open` to `attach`.

**T1.3 — Triage never drops human mail (D1)** · — · plan M1
*Build.* Read `TriageRules` and `Intents\Pipeline::askTriage/flag`. Confirm the model result can
only add `urgent` and `sensitive`; if any path turns a model answer into `noise` for a human author,
remove it. CC-only becomes flag `cc_only` on the message and is passed to the matcher.
*Tests.* A CC-only email from a person with an ask reaches matching with `cc_only = true`; a
newsletter (`List-Unsubscribe`) is still dropped by rule; a model triage answer cannot drop.

**T1.4 — Projections know the new tables** · T1.1 · impl. plan 3.3
*Build.* Create models `CommsWindow`, `CommsSegment`, `CommsLinkDecision`, `CommsTopicSearch`,
`CommsTopicAlias`, `CommsUrgentRule`, `CommsGoldenLabel`, `CommsReconcileChange`, `CommsCardView`
(fillable, casts for json/booleans/dates, `user()` relation; follow `CommsWatcher`). Add each to
`Projections::clear()` in dependency order (children first) and to `capture()`.
*Tests.* `comms:replay` on the Phase0 fixture still replays twice to identical rows.

**T1.5 — Arch rule for agents** · — · agent-definitions 5
*Build.* In `tests/Unit/CommsArchTest.php`: nothing in `App\Comms\Linking`, `App\Comms\Topics`,
`App\Comms\Nightly` uses `App\Comms\Gate`, `App\Actions` or any `App\Voice\Tools`.

**T1.6 — Full-text tests on MySQL** · — · section 4
*Build.* A test trait `Tests\Support\CommitsFullText` that, on MySQL only, makes the test not wrap
in a transaction and truncates the tables it used after each test (`knowledge_documents` and
anything the test created). Use it in the 5 failing knowledge/recipient tests.
*Done when* `tests/Feature/Knowledge` and `RecipientsTest` pass on the MySQL check.

### M1a — Settings the user controls

**T1a.1 — Urgent rules** · T1.4 · plan 7a
*Build.* `App\Comms\Windows\UrgentRules`: `matches(User, CommsMessage): bool` — true if the author is
a person rule, the channel is a channel rule, or the text contains a keyword rule (case and accent
insensitive, whole word), respecting `sources`. Rules change only through `user.configured` events
with `{kind: "urgent_rule", op: "set"|"remove", rule: {kind, ref, sources, active}}`; the existing
archive/projector path applies them to `comms_urgent_rules`. Seed on first read, for a user with no
rules, one rule `{kind: "person_weight", ref: "35"}` meaning "DM from someone with weight ≥ 35"
(add that kind to the enum of kinds).
*Tests.* Each rule kind matches and does not match; remove works; replay rebuilds the rules.

**T1a.2 — "Canales de Slack" screen** · — · plan 7a
*Build.* Under the existing Contexto settings section (`resources/js/pages/settings/context.tsx`
and its controller), a tab listing the user's Slack channels: name, members, messages in the last
30 days (from `comms_messages`), and "ya incluido" when in the default scope (channels the user
posted in, see `Ingestion\Scope`). Checkbox per channel; saving appends `user.configured` with
`{kind: "scope", slack_channel_ids: [...]}` and the projector writes `comms_scopes.slack_channel_ids`
(the column already exists and `Scope::allowsSlack()` already reads it). Channel list from the
connector's read-only list call, cached 10 minutes. Spanish copy, existing components, light/dark,
mobile.
*Tests.* Saving appends the event and a message in a newly ticked channel with no mention is
ingested; unticking stops new ones.

**T1a.3 — "Qué es urgente" screen** · T1a.1 · plan 7a
*Build.* Same section, a tab with three lists (people picker over `comms_people`, keywords, channels)
and an "activo" toggle per rule. Keyword input shows how many messages of the last 30 days would
have matched before saving (endpoint returning a count and three examples).
*Tests.* Save/remove append events; the preview counts correctly on fixture messages.

### M2 — Conversation windows and segmenter

**T2.1 — Settle buffer** · T1.4, T1a.1 · proposal 4 step 2, agent-definitions 4.1
*Build.* `App\Comms\Windows\SettleBuffer::add(CommsMessage)`: find the open `comms_windows` row for
`(user, source, group_ref)`; `group_ref` = thread ref for threads, channel ref otherwise; `kind` =
`dm` | `thread` | `channel`. Append the message id to `message_ids`, update `last_message_at`. If
`UrgentRules::matches()`, settle now. `SettleBuffer::due(User, Carbon $now)`: windows whose quiet
time (`settle.quiet_minutes`, or `quiet_minutes_direct` for dm/thread) passed since
`last_message_at`, or open longer than `settle.max_minutes`. Settling appends `window.settled` with
`external_id = "window:{id}"` and `normalized = {window_id, message_ids, lookback_ids}` and sets the
row `settled`. `comms:tick` calls `due()` for every user with an open window. A message is new in
exactly one window. An edit within `edit.reprocess_hours` of a message in a processed window opens
a new window with that message.
Only runs when `comms.linking.v2` is true (or in the evaluation, M5).
*Tests.* 8 DMs in 3 minutes → one window; quiet 5 minutes in a channel → settles; max 20 minutes
→ settles even with traffic; urgent message settles at once; replay rebuilds windows.

**T2.2 — Window assembler** · T2.1 · proposal 4 step 2
*Build.* `WindowAssembler::for(CommsWindow)`: the new messages plus up to
`window.lookback_messages` earlier messages of the same group within `window.lookback_hours`,
ordered by `occurred_at, id`, each flagged new or not; the authors' `comms_people` rows.

**T2.3 — Segmenter** · T1.1, T2.2 · agent-definitions 3.1
*Build.* `App\Comms\Linking\Segmenter::run(CommsWindow): list<CommsSegment>`. Input per
`segment/v1.md`. Call `ModelRouter::ask('segment', ..., Schemas::segment(), eventIds, windowId)`.
Validate: every new message id appears in some segment, ids exist in the window; otherwise treat as
invalid (the router already retries and falls back). After a final failure create one segment with
the whole window, `restated = ''`, `unsegmented = true`. Discard segments with no new message.
Write `comms_segments` rows with `decision_event_id`; set `comms_messages.window_id`.
*Tests (fake model).* The three segmenter fixtures in agent-definitions 6; a dropped new message
makes the output invalid; failure gives one unsegmented segment.

### M2b — Backfill to 30 days

**T2b.1 — Backfill command** · T2.1 · proposal 3
*Build.* `comms:backfill` default `--days` from `config('comms.backfill.days')` (30). Keep the
existing ingestion in `occurred_at` order with `normalized.backfill = true`. Add
`--passes=identity,labels,topics,patterns,sweep` (default all, in that order) and append
`backfill.completed` per pass with counts. Backfilled items never create `now` notifications.
*Tests.* Idempotent re-run; passes run in order; no `now` notification.

**T2b.2 — Identity pass (B1)** · T2b.1 · agent-definitions 3.7
*Build.* Code first (exact identity, then email) as `IdentityResolver` does; leftovers with the same
slug of display name or similar handle go to `identity_resolve` (write `identity_resolve/v1.md`;
output `{pairs: [{a, b, verdict: same|different|cannot_tell, evidence}]}`). Store `same` pairs as
proposals; the Personas screen shows "¿Son la misma persona?" with evidence; accept appends the
existing merge `user.corrected`. Display name alone never merges.

**T2b.3 — Label miner (B4)** · T2b.1 · agent-definitions 4.5
*Build.* `App\Comms\Backfill\LabelMiner`: for each incoming human message, `acted` if within
`backfill.label_reply_days` the user sent a message in the same thread/channel or to the author on
any channel, or an action event (ticket moved, event created, file shared) references it;
`ignored` otherwise. Write `comms_golden_labels` (`kind = weak_label`) with evidence event ids.
No model. Also runs nightly for new data (`comms:labels`).

### M3a — Topics

**T3a.1 — Topic builder (B2)** · T2b.1 · agent-definitions 3.7
*Build.* `App\Comms\Topics\TopicBuilder`: batches of conversations by channel and ISO week (cap each
batch by characters, ~60 000), calling `topic_build`, carrying KNOWN TOPICS from earlier batches;
then one merge call over all batch topics. Write each topic as a `context.inferred` event with fact
ops: `workstream:{slug}` attributes `name`, `summary`, `state`, `people`, `channels`, and one
`term:{slug}` fact per term with `refers_to`; `status = proposed`.

**T3a.2 — Topic search projections** · T3a.1, T1.2 · plan 4.1
*Build.* In `ContextProjector`, when a `workstream` or `term` fact changes, rebuild that topic's
`comms_topic_search` row (document = name + summary + terms + people names; `embedding_hash` via
`Embeddings`) and its `comms_topic_aliases` rows (`alias_normalized` = lowercase, accents and
punctuation removed, spaces collapsed).

**T3a.3 — "Temas" screen** · T3a.1 · plan 7a
Confirm, rename, add/remove nickname, merge, reject, each a `user.configured` fact op. A rejected
topic is never rebuilt by a later harvest (precedence already handles `rejected`).

### M3 — Candidate retriever and linker

**T3.1 — Hard links** · T2.3 · proposal 4 step 4
`HardLinks::for(CommsSegment): list<string>` candidate keys from: a thread ref already linked to an
intent; Jira keys (`[A-Z][A-Z0-9]+-\d+`), Notion page ids, PR numbers and URLs in the text matched
against messages linked to intents; email `In-Reply-To`/`References`.

**T3.2 — Topic search** · T3a.2 · proposal 4 step 4
`TopicSearch::for(string $restated, list<string> $terms): list<string>` topic subjects, merged by
reciprocal rank fusion (k = 60) from: aliases (exact/prefix on normalized words, then `levenshtein`
≤ 2 over the user's aliases), full text (`whereFullText` on MySQL boolean prefix mode like
`KnowledgeBase::matching`; `LIKE` on SQLite), and embedding cosine over topic documents.

**T3.3 — Candidate retriever v2** · T3.1, T3.2 · agent-definitions 4.2
`App\Comms\Linking\CandidateRetriever::for(CommsSegment): Candidates` with `topics` (≤ 10),
`intents` (≤ 10: existing scoring from `Intents\CandidateRetriever` with cosine, plus every open
intent of the top 3 topics) and `ranking` (list of keys `intent:{id}` / `topic:{subject}`, hard
links first). Do not delete the v1 retriever.

**T3.4 — Link stage** · T1.1, T3.3 · agent-definitions 3.2
`App\Comms\Linking\LinkStage::run(CommsSegment): LinkOutcome`. Build the input per `link/v1.md`;
shuffle CANDIDATE TOPICS and CANDIDATE INTENTS per run with a seeded shuffle
(`mt_srand(crc32("{segment_id}:{run}"))`, restore after) and keep everything else identical and
first (cache-friendly order). `ModelRouter::runs('link', ...)`; each output →
`LinkVote::fromOutput()`; reject votes whose ids were not candidates (make them invalid);
`LinkDecision::decide($votes, $candidates->ranking)`. Append `link.decided` with the outcome and run
event ids; write `comms_link_decisions`.
*Tests.* The six linker fixtures in agent-definitions 6, with the fake model returning stored outputs
per run; same segment twice → same shuffles (determinism).

**T3.5 — Retrieval metric** · T3.3 · proposal 4 step 4
`comms:eval --task=retrieve`: for golden segments with an expected topic/intent, share in top 10.

### M4 — Extractor, checks, critic, router

**T4.1 — Extract stage** · T3.4 · agent-definitions 3.3
`ExtractStage::run(CommsSegment, LinkOutcome): list<AgreedOperation>`. Input per `extract/v1.md`
(open intents and those settled in the last 14 days of `topicSubject`; when the target is an intent
without a topic, that intent alone). `ModelRouter::runs('extract', ...)`; invalid runs → empty list;
`ExtractionAgreement` with a closure over `Embeddings::cosine` (fallback `similar_text`/100) and
`config('comms.agreement.what_similarity')`. Now remove `match` from `escalate_on_confidence`.

**T4.2 — Checks** · T4.1 · agent-definitions 4.3
Before the critic: `Quote::inAny(segment raw texts, quote)`; every person id exists (or is `unclear`);
deadline parses as a date not before the newest message; transition valid (reuse the state machine's
judge rules; do not duplicate them — extract a `canApply()` method if needed). Failures are rejected
and logged in `state.applied`.

**T4.3 — Critic stage** · T4.2 · agent-definitions 3.4
For `SegmentRouter::criticChecks()` operations only: input per `critic/v1.md` with **raw** messages
(not `restated`) and the dossier; `CriticVerdict::read($output, [...raw texts, dossier])`. Log
`ignored` refutations in the `state.applied` event.

**T4.4 — Apply** · T4.3 · agent-definitions 4.4
`App\Comms\Linking\PipelineV2::process(window.settled event)`: segments → link → extract → checks →
critic → `SegmentRouter::route()` → `IntentStateMachine`. Extend the state machine to store `kind`,
`steps`, `unclear_fields` (null fields from UnclearFields), `possible_ask` (= `uncertainOperation`
on `open`), `topic_subject`, and on links `segment_id`, `uncertain` (= `uncertainLink`),
`alternative`, `link_event_id`. A possible ask never triggers the playbook router. `signal` writes
`comms_signals.topic_subject`. `ProcessCommsEvent` sends `window.settled` here when
`comms.linking.v2` is on, and sends messages to `SettleBuffer` instead of the v1 matcher.
*Tests.* Every golden sequence of plan 8.2 marked M2–M4, as fixtures in `tests/Fixtures/Comms/`
replayed in `tests/Feature/Comms/V2/LinkingTest.php`.

### M5 — Backfill evaluation (gate)

**T5.1 — Evaluation run** · M4, T2b.3 · proposal 3
`comms:backfill-eval {user}`: clones the user's in-scope messages into a new evaluation user (same
people), runs passes B1–B4 on days 1–21 and the v2 pipeline on days 22–30, and writes
`storage/comms/evals/{date}.json` plus a `comms_eval_runs` row: retrieval hit rates, share uncertain,
precision/recall of "needs the user" against `acted`/`ignored`, critic refutation rate, tokens and
cost per window, p50/p95 window latency. Option `--critic=deepseek` for the comparison in plan 1.1.
**Stop here.** Show the user the report; tuning and the go-ahead are theirs.

### M6 — Switch

**T6.1** Set `COMMS_LINKING_V2=true` on Forge is the user's step. Build `comms:rebuild-v2 {user}`:
clears intent projections, runs the backfill passes and the v2 pipeline over 30 days, marks
`from_backfill`, lists old open intents not reproduced as one stale batch. No `now` notifications.

### M7 — Surface

**T7.1 — Home board** · M4 · proposal 7.2
`App\Comms\Surface\HomeBoard::for(User)`: loads intents with counts of pending proposals, open
questions, executed actions, active runs, waiting watchers (one query each, grouped by intent id; no
N+1) and calls `BallHolder::group()`. Returns counters, lists (importance desc, deadline asc, id),
possible-asks and uncertain-links counts, stale batch.

**T7.2 — Intent card** · T7.1 · proposal 7.3
Card payload (collapsed and open), `user.viewed` on open into `comms_card_views`, "changed since" when
`changed_at > seen_at`, evidence across channels, unclear fields as questions.

**T7.3 — Endpoints and live updates** · T7.2 · plan M7
Routes listed in plan M7; Reverb events `IntentCardChanged`, `HomeCountsChanged` on the user's
private channel.

**T7.4 — React** · T7.3 · plan M7
`resources/js/intents/`: `home-board.tsx`, `intent-card.tsx`, `evidence-thread.tsx`,
`uncertain-link-row.tsx`, `possible-ask-row.tsx`, `topic-view.tsx`. Behind `comms.linking.surface_v2`.
Follow `docs/prototype.html` and the existing `resources/js/alerts/` components. Activate the
`inertia-react-development` and `tailwindcss-development` skills first.

### M8 — Dossier, ask patterns, examples

**T8.1 — Dossier writer** · T7.4 · plan M8, agent-definitions 3.5
`App\Comms\Topics\DossierWriter` runs `dossier_write` once after a window that touched a topic.
Input is the previous dossier, the applied operations, and the segment, in the order
`resources/prompts/comms/dossier_write/v1.md` lists. Do not edit that prompt. Output becomes
`context.*` events (workstream summary and state, `term` facts). `changed` is one sentence and is
written to `comms_intents.changed_summary` and `changed_at` on every intent the window touched.
Sensitive content never enters `summary`.
*Tests.* Deadline moved → `changed` names the move. A sensitive sentence in the window is absent
from `summary`.

**T8.2 — Ask patterns** · T8.1 · plan M8, agent-definitions 3.7
`App\Comms\Topics\AskPatternMiner`, the B3 pass. On backfill and monthly, for each person with at
least `comms.backfill.ask_pattern_min` labelled messages, ask `ask_pattern` and store at most five
`rule` facts (`subject` `person:{id}`, `origin` observed). A person under the minimum gets none.
Write `resources/prompts/comms/ask_pattern/v1.md` (it does not exist yet). Wire the pass into
`Passes::ORDER` where `patterns` is still a no-op. Do not change the order of the other passes.
*Tests.* 19 labels → no model call and no facts. 20 labels → at most five facts.

**T8.3 — Examples in the prompts** · T8.2 · plan M8
Up to `comms.examples.max` corrections that share the segment's people or topic, rendered into the
`link` and `extract` inputs (extract is match v2). Add `v2.md` for those two prompts and bump
`config/comms.php`. Do not edit `v1.md`. A "not for me" correction is one of the examples the
critic's input can see.
*Tests.* A correction about Marta appears in the next Marta segment and is absent from a segment
about someone else.

### M9 — Nightly reconciler

Use the existing flag `comms.linking.reconciler`. Do not add a fourth flag. While it is off, the
night writes the change list and applies nothing. The user turns the flag on after a week of lists.

**T9.1 — Reconciler** · T8.1 · plan M9, agent-definitions 3.6
`App\Comms\Nightly\Reconciler`. Nightly at `comms.reconcile.time`, once per topic touched that day.
Input per `resources/prompts/comms/reconcile/v1.md`: dossier, the day's linked messages in order,
open intents and those settled that day, each link with certain/uncertain and its alternative. Do
not edit that prompt. One `reconcile` run. Store the ops. Do not apply them here.
*Tests.* The four reconciler rows of agent-definitions section 6: answered elsewhere → `settle`;
opened twice → `merge`; uncertain link contradicted later → `relink`; uncertain link supported →
`confirm_link`.

**T9.2 — Applier** · T9.1 · plan M9
`App\Comms\Nightly\ReconcileApplier`. With the flag on, auto-apply `confirm_link`, `relink` of
uncertain links, and `settle` that passes checks and the critic. Propose merges, splits, topic
merges, and `relink` of a certain link. Record each applied or proposed change with its quote.
Undo of an applied change restores the previous link.
*Tests.* Plan 8.2: "Later message shows uncertain link was wrong" (applied, in the change list,
undo restores it). "Ask answered on another channel by a third person" (settled, in the list).
"Same need opened twice in a day" (merge proposed, applied only on accept). Every uncertain link
of that fixture day ends confirmed or moved.

**T9.3 — History fetch** · T9.2 · plan M9, agent-definitions 4.6
`App\Comms\Nightly\HistoryFetch`. For an `unresolved_ref` or a new topic, search the connected
sources beyond `comms.backfill.days` with the segment's terms. Ingest hits as backfill (no `now`
notification) and retry that segment once. Tests use the connector fakes, not the providers.
*Tests.* Plan 8.2: "Reference older than 30 days" is ingested and the segment links.

**T9.4 — Change list** · T9.2 · plan M9, proposal 9
Morning digest: every change with its quote and three answers, "igual" / "no es igual" /
"deshacer". Each answer is a `user.corrected` event and enters the golden set.
*Tests.* Answering "deshacer" restores the link. "no es igual" does not apply the proposal.

**T9.5 — Cambios de la noche** · T9.4 · plan 7a
Settings screen under Contexto. The same list, with "igual" / "no es igual" / "deshacer" per line,
and per class a switch "aplicar solo" stored as a policy fact. Saving appends `user.configured`.
*Tests.* The screen saves, the event exists, and a class switched on is what T9.2 applies alone.

**T9.6 — Weekly eval** · T9.4 · plan M9, agent-definitions 4.7
`comms:eval --weekly` replays the golden set and writes a `comms_eval_runs` row of kind `weekly`:
retrieval, attach precision, share uncertain, share of uncertain links later moved, precision and
recall of "needs the user", critic refutation rate and how often the user agreed, corrections per
100 in-scope human messages. `comms:eval --prompt-change` exits non-zero when any metric is worse
than the previous run, and does not bump a prompt.
*Tests.* A fixture run with one worse metric fails `--prompt-change`. An equal run passes.

### M10 — Plan, confirmations, actions

**T10.1 — Plan checklist** · T7.2 · plan M10, proposal 7.5
`App\Comms\Surface\PlanChecklist`. Steps from the intent's `steps` plus the playbook run, proposals,
and watchers. Each step has a status (done, running, needs approval, waiting, blocked, skipped),
the channel or tool, and a preview of what will be sent or changed. Steps that share `after` are
one parallel group. A condition is a sentence at the start of the step ("Si Marta confirma: …").
A step waiting on a person names them and since when.

**T10.2 — Three confirmations** · T10.1 · plan M10, proposal 7.6
One card each, answerable in one tap: approve (Approve · Edit · Guide · Reject, parallel steps
together), disambiguate (confirm, the alternative, "Somewhere else"), confirm an outcome (Done ·
Still open). Clear closes still close alone with undo.

**T10.3 — Uncertain source** · T10.2 · plan M10, agent-definitions 4.4
A proposal whose sources include an uncertain link stores `uncertain_source_message_ids`. The gate
asks even when the action class is `allow`. Moving that message regenerates the draft.
*Tests.* Plan 8.2: "Draft built on an uncertain link". An `allow` class on an uncertain source
still asks.

**T10.4 — A change before send** · T10.3 · plan M10
An `update` after approval and before execution returns the proposal to Needs you with what
changed. An executed step is not redone. If an executed step is now wrong, a question says so.
*Tests.* Plan 8.2: "Deadline changes after approval, before send".

**T10.5 — Actions** · T10.4 · plan M10, proposal 7.7
Guide by voice appends `user.guided` and redrafts. Not for me stays the T7.3 settle and is an
example the critic sees (T8.3). Delegate changes the responder and proposes the message to that
person; the card moves to Waiting on others. Snooze appends `user.snoozed` and hides the card
until then; the deadline still counts. "Always do this" appends `user.configured` with policy
`allow` for that action class and context. A hard deny still applies. Undo uses the undo the
executed action recorded.

**T10.6 — Voice** · T10.5 · plan M10
"sí" confirms the open proposal, as today. Any other sentence while a proposal is focused is
guidance: redraft and show the new version. It does not send.

**T10.7 — React** · T10.6 · plan M10
`resources/js/intents/`: `plan-checklist.tsx`, `confirmation-card.tsx` (three variants),
`uncertain-source-notice.tsx`, the action menu, the snooze picker. Bind voice guidance in the
existing voice dock. Behind `comms.linking.surface_v2`. Activate `inertia-react-development` and
`tailwindcss-development` first. Verify the changed flow in the browser.
*Tests.* Surface use cases 3, 4, 5, 8 and 10 of proposal 7.8 on fixture data. `npm run build`.

**T10.8 — Políticas** · T10.5 · plan 7a
Settings screen under Contexto. Per action class: preguntar / hacer solo / nunca. Hard denies are
greyed out with the reason. "Hazlo siempre sin preguntar" on a card writes the same policy.
Saving appends `user.configured`.
*Tests.* The screen saves, the event exists, and the gate reads the new value on the next proposal.

### M11 — Remaining playbooks

One slice per commit. Do not start a slice while the suite is red. Order T11.2–T11.10 by
`comms:report --triggers` once two weeks of v2 traffic exist. Until those counts exist, use the
order below. Each slice: the playbook, golden sequences including one injection case, and a write
tool only if the gate lacks that action class. Cases are covered when the sequence passes on v2.

**T11.1 — Re-run what already exists** · T4.4 · plan M11, plan section 10
Replay the phase 5, 6 and 7.1 golden sequences on windows and segments. Fix what broke. Cases:
1, 4, 9, 19, 23, 37, 60; 3, 8, 12, 46, 61, 11, 52, 57, 13, 14, 51, 53, 54; 15, 22, 41, 44.

**T11.2 — dependency_risk** · T11.1 · implementation plan 7.2
Cases 16, 24, 29, 50, 59. Table `comms_intent_links` (`depends_on`, `merged_into`, `split_from`,
`spawned`). Upstream still open past the downstream deadline minus one business day proposes one
heads-up. Upstream settling notifies downstream. A moved or cancelled meeting shifts deadlines
that named it, or asks. A person leaving lists their open responder intents and proposes a
replacement.

**T11.3 — watch_and_follow_up** · T11.2 · implementation plan 7.3
Cases 38, 39. A ticket in progress or review with no change for 3 business days (2 in the current
cycle) is checked for a waiting review, a blocker, or an assignee away before any proposal. A
blocker assigned to the user becomes an intent with importance at least 60.

**T11.4 — clarify_then_answer** · T11.3 · implementation plan 7.4
Cases 2, 34, 47. A vague call request asks the person, reads the answer, then proposes a meeting
or a direct answer. A feature request is checked against product, backlog and roadmap. The reply
never contains a date (`commits_date` stays a hard deny). An estimate is a range. A date the user
commits to becomes an intent and a watcher.

**T11.5 — contradiction** · T11.4 · implementation plan 7.5
Cases 17, 35, 36, 40, 56. Show both statements with refs and propose a question to the area owner.
A promise to a client is `now`. Conflicting priorities show both asks and what each displaces.

**T11.6 — incident** · T11.5 · implementation plan 7.6
Cases 21, 42, 45. Urgent plus production, or a message in an incident channel: notify now, search
recent tickets and merges, propose a holding reply. A pre-release bug names the release. A revert
proposes telling whoever was told it was live.

**T11.7 — signal_to_digest and pattern** · T11.6 · implementation plan 7.7
Cases 18, 20, 33, 48, 49. Signals fold into the digest. The pattern job clusters repeated signals.
No write tool unless the gate lacks the action class.

**T11.8 — recurring_draft** · T11.7 · implementation plan 7.8
Cases 30, 43. A repeated outgoing shape proposes the next draft. The user still confirms the send.

**T11.9 — redirect and do_what_was_promised** · T11.8 · implementation plan 7.9
Cases 7, 25, 55. A message for someone else proposes the redirect. A promise the user made
proposes doing the thing, with the quote.

**T11.10 — away, onboard_person, approval_request** · T11.9 · implementation plan 7.10
Cases 31, 58, 62. Away proposes who covers. A new person proposes the onboarding messages. An
approval request becomes a confirmation card (T10.2).

**T11.11 — Remove v1** · T11.10 · plan M11
**Stop and ask.** Removing `App\Comms\Intents\MatchStage` and `App\Comms\Cutover\HomeFeed` drops
code that old log entries reference. Replay of pre-switch history then needs `--rejudge`. Do not
delete them without the user's yes.

**T11.12 — Legacy alert docs** · T11.11 · plan M11
Read the `human-interaction-*` documents. Map each still-relevant pillar to a v2 behaviour, or
retire it in the doc. Turn off the legacy Slack and Gmail alert path.
*Done when the whole effort is done.* Plan section 10 is all green, and `comms:eval --weekly`
reports no metric worse than the M5 baseline.

---

## 7. Definition of done for the whole effort

Plan section 10 is green: every case has a passing golden sequence on v2, the legacy alert path for
Slack and Gmail is off, and `comms:eval --weekly` reports no metric worse than the M5 baseline.
