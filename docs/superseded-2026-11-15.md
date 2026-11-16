# Superseded text — 2026-11-15

Blocks removed from `architecture.md` and `implementation-plan.md` because they contradict the
decisions in [linking-intents-and-surface-proposal.md](linking-intents-and-surface-proposal.md)
section 1 (D1 to D10). Each block is the original text, word for word, with where it was and
what replaced it. Nothing here is current design. Do not build from this file.

To restore a block, paste it back at the place named and remove the replacement.

## From `architecture.md`

### 1. Design principles, principle 2

Contradicts: D1, D4. Now: Human-written messages are never dropped and always reach the large tier.

````text
2. **Cheap first, expensive only when needed.** Most messages are noise or simple updates. They should
   never reach a large model. Escalate by need, not by default.
````

### 3.4 Triage, rules bullet

Contradicts: D1. Now: CC-only no longer drops; it is a hint for the matcher.

````text
- **Rules first:** known automated senders, newsletters, CC-only, own messages to self, bots.
  Some automated messages are allow-listed as deliveries or asks (case 28).
````

### 3.4 Triage, model bullet

Contradicts: D1. Now: The small model flags; it does not label noise.

````text
- **Model classifies** what is left into: noise / possible intent / possible signal / urgent / sensitive.
````

### 3.4 Triage, goal

Contradicts: D1. Now: The goal is no longer to drop most traffic.

````text
Goal: drop most traffic here. Measure the drop rate and the miss rate.
````

### 3.5 Intent matching & extraction (whole body)

Contradicts: D2, D3, D4, D5, D7. Now: Replaced by the proposal's section 4 pipeline.

````text
For anything triage did not drop. Two steps:

1. **Candidate retrieval (no model reasoning):** open intents and recently settled intents that share
   participants, thread ids, linked tickets or pages, or are close in embedding space.
   Keep it to a short list (around 10).
2. **Match and extract (mid-size model, structured output):** given the message and the candidates,
   return a list of operations:
   - `attach(intent_id)` — continues an open intent
   - `open(new intent)` — with what, requester, responder, deadline if stated
   - `settle(intent_id, reason)`
   - `reopen(intent_id)`
   - `update(intent_id, fields)` — new deadline, new responder (delegation), etc.
   - `signal(about intent_id or owned area)`
   - `noise`
   Each operation carries a confidence and the quote from the message that justifies it.

Low confidence on a match → attach to the most likely intent, flag it as uncertain, show the
correction option in the user surface. Merges and splits are user actions that become events.
````

### 3.15 User surface, intent view bullet

Contradicts: D10. Now: Home is grouped by who has the ball.

````text
- **Intent view:** open intents grouped as "waiting on me" / "I'm waiting on" / "stale".
  Corrections: re-match, merge, split, close, change importance.
````

### 5.1 Tiers, rows Small and Mid

Contradicts: D1, D4. Now: Matching and extraction moved to the large tier; triage no longer drops. (Corrected rows are in place; the Large row should also list linking, intent extraction, the critic and the nightly reconciler.)

````text
| **Small / fast** | triage, routing ambiguous cases, simple watcher conditions, short summaries | high volume, low stakes |
| **Mid** | intent matching and extraction, action point extraction, context summaries for the user, most drafts | the core loop, needs reliability in structured output |
````

### 5.2 Harness rules, escalation bullet

Contradicts: D4. Now: Confidence no longer gates linking or extraction.

````text
- **Escalation by confidence.** A small or mid model that returns low confidence hands the same task
  to the next tier, with its own output as a hint.
````

### 6.1 Incoming message, steps 2 and 3

Contradicts: D1, D2, D5. Now: Steps 2 and 3 follow the window pipeline.

````text
2. Triage: drop noise, flag sensitive / urgent.
3. Retrieve candidate intents → match & extract operations.
````

## From `implementation-plan.md`

### 0. Changes from the previous version, row 7

Contradicts: D6. Now: Backfill is 30 days and builds memory.

````text
| 7 | No backfill. On day one every open conversation would look new, or be missed. | Phase 1 imports the last 14 days, processed in original order, with notifications suppressed. |
````

### 0. Changes from the previous version, row 11

Contradicts: D4. Now: Three runs replace escalate-then-floor for matching.

````text
| 11 | "Low confidence escalates" and "below floor is uncertain" had no order. | Escalate first. If the top tier is still below the floor, attach as uncertain. |
````

### 3.4 Models, config line for `match`

Contradicts: D4. Now: `match` is large tier, three runs; five new tasks added (2026-11-15).

````text
    'match'           => ['tier' => 'mid',   'fallback' => 'large', 'prompt' => 'v1'],
````

### 3.4 Models, call rule 7

Contradicts: D4. Now: `match` removed from confidence escalation.

````text
7. Valid but `confidence < floor` for `triage`, `route`, `watcher_check`, `match` → call the fallback tier once with the first output as a hint. Keep whichever has the higher confidence.
````

### 3.5 Defaults, `confidence_floor`

Contradicts: D4. Now: The floor no longer decides uncertain links.

````text
| `confidence_floor` | 0.70 | Escalation and uncertain links |
````

### 3.5 Defaults, `backfill.days`

Contradicts: D6. Now: 30 days.

````text
| `backfill.days` | 14 | Phase 1 |
````

### 3.6 Operations, uncertain rule

Contradicts: D4, D8. Now: Disagreement, not the floor, marks a link uncertain; the reconciler may move uncertain links.

````text
Confidence below the floor after escalation → `attach` to the best candidate with `uncertain = true` on the link, and a correction surfaces. The machine never merges intents by itself. A merge is a `user.corrected` event.
````

### Phase 1, backfill bullet

Contradicts: D6. Now: 30 days, plus the five backfill passes.

````text
- **Backfill** (`php artisan comms:backfill {user} --days=14`): fetches in-scope messages for the window, sorts by `occurred_at`, appends in that order with `normalized.backfill = true`. When later phases run, backfilled events go through triage and matching normally but create no `now` notifications; their open intents appear in the list with a "from before activation" marker.
````

### Phase 2, triage rules 5 and 6

Contradicts: D1. Now: No CC-only rule, no noise label.

````text
  5. Email where the user is only in CC and their name or alias is not in the body → `cc_only` (not an intent; may become a signal in phase 5).
  6. Everything else → `triage` task: `{label: noise|possible_intent|possible_signal, urgent: bool, sensitive: bool, confidence}`.
````

### Phase 2, Match bullet

Contradicts: D2, D4, D5, D7. Now: Per-window pipeline with segmenter, linker, extractor, critic.

````text
- **Match** (`MatchStage`): sends the message, the last 5 messages of its thread, and each candidate's `what`, participants, deadline, state and last change. Returns operations (3.6). The prompt includes the user's identity, today's date in the user's time zone, and the rule that one message can carry several operations.
````

### Phase 2, intent view bullet

Contradicts: D10. Now: Four groups by who has the ball.

````text
- **Intent view** on the home canvas: three groups, "Esperan algo de mí", "Espero algo de otros", "Sin movimiento". Each row: `what`, who, deadline, last change with a link to the source message, importance as one of three levels. Uncertain rows show "¿Es esto?" with re-match and split.
````

### Phase 2 golden sequences, CC-only row

Contradicts: D1. Now: CC-only is judged by the matcher.

````text
| CC-only email, newsletter | No intent |
````

### Phase 2 golden sequences, ambiguous row

Contradicts: D4. Now: Disagreement replaces the 0.55 score.

````text
| Ambiguous match at 0.55 after escalation | Attached, uncertain |
````
