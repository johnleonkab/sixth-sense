# Communications processing principles

1. Communications can happen through multiple channels, about the same topics: email, Slack, Jira comments, Notion comments, meetings.
2. Most communication exists because someone needs something from someone else. Sometimes that someone is the person using sixth-sense. Sometimes it is the other way around.
3. Some communication needs nothing from anyone, but still changes what the user should know. That matters too.

## Glossary

- **Message:** one thing a person said, to another person or to a group, on one channel (Gmail, Slack, Jira, Notion). Messages the user sends count as much as messages the user receives.
- **Intent:** one reason someone needs something from someone else. "Review the prepaid flow before Friday." "Tell me if Thursday works." An intent is either **open** or **settled**.
- **Conversation:** every message, on any channel, that belongs to one intent. The Slack thread is not the conversation. The email thread is not the conversation. They are places the conversation passed through.
- **Requester:** the person who needs the thing. They are waiting.
- **Responder:** the person it is needed from. They are being waited on. The user can be either one. An intent can have more than one of each.
- **Signal:** a message that opens no intent, but changes the context of an open intent or affects something the user is responsible for. "We're pushing the release to December." "Client X churned." Signals are surfaced, not tracked.

One message can carry two intents ("how's X, and send me the report on Thursday"). It then belongs to two conversations. One intent can show up as a Slack thread, then an email, then a comment on the ticket. That is still one conversation.

When the user promises something ("I'll send it tomorrow"), that is an open intent. The user is the responder.

## What a person actually keeps in mind

A person does not remember the transcript. They remember the open intents, and for each one only this:

- **What** is needed, in one sentence.
- **Who** is waiting, and **on whom**.
- **By when**, if anyone said.
- **The last thing that changed it:** who said what, and where.
- **Whether it is still open.**

Behind that, the AI keeps a link to every message in the conversation. They are not part of what it thinks about. They are what it checks when asked "where did that come from?", and what it reads before drafting a reply.

Settled intents drop out of mind. The AI has to do the same. Being fully aware means every open intent is known in that shape. It does not mean every sentence is in the prompt.

## How a new message is taken in

The same way a person hears a new message. Four questions, in order:

1. **Which open intent is this?** Match on the reason, not on the channel. A reply in email about the prepaid flow is the Slack conversation continued. If two open intents could fit, pick the more likely one, mark the match as uncertain, and let the user correct it. Do not open a second copy of one that is already open. If two intents later turn out to be the same, merge them.
2. **Did it open a new intent?** A new ask, even in the middle of an old thread, is a new intent. The old one stays as it was. A promise the user makes is also a new intent.
3. **Did it settle or reopen one?** An answer, a delivery, a decision, or "never mind" settles that intent. What was said stays as the record of why it closed. If a settled intent comes back with the same need ("one more change to the prepaid flow"), reopen it. If it comes back with a different need on the same topic, that is a new intent.
4. **Is it a signal?** If it opens and settles nothing, check whether it changes an open intent's context, or touches something the user is responsible for. If neither, it is noise.

Then update only the fields above. The rest of the message is evidence, not a new topic.

### Asks made to a group

"Can someone review this?" sent to a channel has no single responder. If the user is in the group, the user is a possible responder. When someone else takes it, the intent is settled for the user, even if nobody says so to them.

### Delegation

When a responder passes the ask on ("looping in Marta, she owns this"), the intent stays the same. Only the responder changes.

## Intents that end without a message

Many intents never get a closing message. They go quiet, get overtaken by events, or get settled in a meeting with no trace.

Open intents are checked on a schedule, not only when a message arrives. An intent is **stale** when its deadline has passed or nothing has happened on it for too long. Stale intents are shown to the user together, in one batch, with one question: still open, or drop it? They are never asked about one at a time.

## When to react

A person speaks up when something changed that matters to them. Not when a message arrived.

React when:

- Someone is now waiting on the user, and was not before.
- Something the user was waiting on arrived, or fell through.
- A deadline on an open intent got close, or moved.
- An open intent was settled by someone else, so the user can drop it.
- A signal changes an open intent, or something the user is responsible for.
- Open intents went stale (in the batch above).

Stay quiet when the message changes no intent the user is part of and is not a signal for them. A person who is not in the ask does not answer it.

## What the AI may do on its own

Reading stays free, and reading leaves no trace: it does not mark emails as read, does not clear Slack unread state, does not react, does not resolve comments. The user's own view of their channels must look as if the AI were not there.

Saying something back, moving a meeting, or writing on the ticket is still a proposal until the user says yes.

## Sources

Gmail, Slack, Jira comments and Notion comments are read directly. Meetings count only when there is something written to read (a transcript, or notes in Notion). An intent settled in a meeting with no written trace is caught by the stale check, not assumed.