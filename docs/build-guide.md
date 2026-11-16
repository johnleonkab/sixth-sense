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

### M8 to M11

Build them from the plan sections M8 to M11, one ticket per bullet, in the same format, with the
golden sequences of plan 8.2 for that delivery. Write the ticket list into this file first and
commit it before starting.

---

## 7. Definition of done for the whole effort

Plan section 10 is green: every case has a passing golden sequence on v2, the legacy alert path for
Slack and Gmail is off, and `comms:eval --weekly` reports no metric worse than the M5 baseline.
