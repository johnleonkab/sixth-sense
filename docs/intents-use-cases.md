# Cases

Situations the system will meet, and how it should react to each.
Each case says what starts it, its shape (reactive, proactive or chained), and what the system does.

## Index

- **Questions and asks to the user:** 1, 2, 3, 8, 9, 12, 19, 23, 25, 47, 55
- **Asks the user made:** 4, 5, 6, 26
- **Commitments and follow-through:** 7, 15, 16, 24, 50
- **Meetings and calendar:** 11, 13, 14, 29, 51, 52, 53, 54, 57, 61
- **Decisions and conflicts:** 17, 22, 36, 56
- **Tickets, code and specs:** 37, 38, 39, 40, 41, 46
- **Releases and incidents:** 21, 42, 43, 44, 45
- **Stakeholders, clients and feedback:** 20, 34, 35, 48, 49
- **Awareness and noise:** 10, 18, 27, 28, 33
- **People and team changes:** 31, 58, 59
- **Recurring and administrative:** 30, 60, 62
- **Sensitive:** 32

---

## 1. A developer comments on a Jira ticket
**Starts:** comment on a ticket where the user is reporter, assignee or mentioned. **Shape:** reactive.
- Split the comment into points (one comment often carries several questions).
- For each point, look in the product repo, the ticket's epic, linked Notion specs.
- Points answered with confidence: draft the answer with its source.
- Points not answered: ask the user, with what was found and why it was not enough.
- Propose one reply on the ticket covering every point. On yes, post it.

## 2. A collaborator wants a call about something vague
**Starts:** "can we have a call about the epic?" **Shape:** chained.
- If the subject is not clear, ask the person which one.
- Read it. Ask the person what doubts they have.
- When the doubts arrive: resolve what can be resolved, ask the user the rest.
- Propose two options: set the meeting (with slots and the doubts as agenda), or answer directly
  with everything filled in.
- If the meeting is chosen: send the invite with the doubts as agenda, and watch for the transcript (case 11).

## 3. The boss asks for data the system cannot get
**Starts:** a request for data from a source the system cannot read (BigQuery, Metabase). **Shape:** chained.
- Structure the request: which metrics, which cuts, which period, what it is for. If any of these is
  unclear, propose a clarifying question to the boss first.
- Prepare where the answer will go: a Notion page if it is a lot, a Slack message template if it is little.
- Estimate the effort (15 min to 1h30) and find a slot. Urgent → earliest. Otherwise → the earliest slot
  before the deadline that does not break focus time.
- If no slot fits before the deadline: tell the user, and propose a message to the boss asking for more
  time or saying when it will come.
- Watch the page or template. When it is filled, ask: "share it with your boss?"

## 4. A late reply to something the user forgot
**Starts:** reply to a message the user sent days ago, and the user has not answered yet. **Shape:** reactive.
- Rebuild the context: what the user asked and why, what happened since on other channels,
  related tickets and pages.
- Show a short summary: "You asked X because Y. Since then, Z. They now say W."
- Let the user write or dictate the reply right there. Send it on the channel the reply came from.

## 5. The user asks for something important
**Starts:** an outgoing message with an ask that matters. **Shape:** proactive.
- Create a watcher. The condition is what the user needs, not only a reply: the Google Play
  invitation email counts, even if the person never answers.
- On deadline with nothing arrived: check every channel once more, then propose a follow-up.
- After a second miss, propose a different route (another person, another channel, escalation).

## 6. The user asks for something unimportant
**Starts:** an outgoing low-importance ask. **Shape:** none.
- Track it. If it is answered, case 4 applies. If not, it expires without a word.

## 7. The user promises to add someone to a meeting
**Starts:** "I'll add you to the meeting." **Shape:** reactive.
- Find the meeting. If there are several possible, ask which one.
- Add the person. Tell the user, with undo.
- Offer to let the person know: "Just added you, check your calendar."

## 8. Someone asks the user to send something by a date
**Starts:** "send me X by Friday." **Shape:** chained.
- Look for X in Drive, Notion, Jira, past messages. If it exists, propose sending it now.
- If not: estimate the effort and propose several slots before the date. The user picks one.
- Once the slot is set, propose a reply: with the date as a commitment, or without.
- Watch the slot. After it, ask: "is it done? send it?" If the slot was skipped, warn before the
  deadline, not after.

## 9. Someone asks a question about the product
**Starts:** a product question addressed to the user. **Shape:** reactive.
- Search repos, specs, tickets. Draft the answer with sources.
- If the code and the spec disagree, say so to the user instead of picking one.
- On yes, send.

## 10. The same ask comes back on another channel
**Starts:** a follow-up on Slack of an ask that came by email, from the same person or another.
**Shape:** reactive.
- One intent. Add the message to its conversation.
- Raise importance. Tell the user someone is insisting, and who.
- Reply on the channel where they insisted, unless there is a reason not to.

## 11. A meeting transcript becomes available
**Starts:** a transcript or notes appear in Drive, Gmail or Notion. **Shape:** proactive.
- Extract action points: who, what, by when.
- Look for action points someone else sent during or after the meeting. Compare.
  Contradictions go to the user.
- Create intents: the user's own commitments, others' commitments to the user (watchers),
  decisions that need recording (case 22).
- Propose sending the action points to attendees if nobody has.

## 12. Someone asks the user to review something
**Starts:** "can you review the PRD / design / PR before Thursday?" **Shape:** chained.
- Read it. Prepare a pre-review: summary, what changed against the spec or ticket, likely issues,
  open questions. The user reviews from that, not from scratch.
- If the review needs more than 15 minutes, block time before the deadline (as in case 8).
- Watch for the user's comments. When they are in, propose telling the person it is done.

## 13. A meeting invite arrives
**Starts:** an invitation. **Shape:** reactive.
- Check conflicts. If it overlaps, say what with and propose which to move.
- If there is no agenda and the meeting is not routine, propose asking the organizer what it is about.
- If it relates to open intents, link them. Case 14 will use them.

## 14. A meeting is about to start
**Starts:** 15–30 minutes before a meeting with other people. **Shape:** proactive.
- Brief: open intents with each attendee, in both directions. What the user owes them, what they owe the user.
  Last relevant messages. Tickets they are moving.
- Nothing open with anyone → no brief.

## 15. Something the user promised got done by a third person
**Starts:** a ticket moves to Done, a PR is merged, a page is published, and someone was waiting for it
through the user. **Shape:** reactive.
- Settle the user's side. Propose telling the person who was waiting, with the link.

## 16. One intent depends on another
**Starts:** the user is waiting on A in order to deliver B to someone else. **Shape:** proactive.
- Link them. B cannot be on time if A is late.
- When A is late enough to put B at risk, warn the user, and propose a heads-up to B's requester
  before B's deadline.
- When A arrives, bring B up immediately.

## 17. Two sources contradict each other
**Starts:** two people, or two channels, say different things about the same decision. **Shape:** reactive.
- Tell the user, with both versions and where each came from.
- Propose a question that settles it, to whoever should decide.

## 18. The user is discussed but not asked
**Starts:** the user's product, project or name comes up in a thread where nobody asks them anything.
**Shape:** reactive.
- Into the digest, unless it is a decision that affects something the user owns: then sooner.
- No reply proposed unless the user asks for one.

## 19. A question already has an answer somewhere
**Starts:** someone asks something already answered in Notion, a ticket or an old thread. **Shape:** reactive.
- Propose a reply with the answer and the link.

## 20. Several people ask the same thing
**Starts:** the same question from different people within a few weeks. **Shape:** proactive.
- Tell the user it is a pattern. Propose writing it down once (Notion page, channel announcement)
  and pointing everyone to it.

## 21. A production problem or client incident
**Starts:** support, a client or a colleague reports something broken. **Shape:** reactive, urgent.
- Tell the user now. It does not wait for the digest.
- Look for an existing ticket. Check recent merges and deploys in the repos.
- Propose: link to the existing ticket, or create one with what is known. Propose a holding reply
  to whoever reported it.
- Watch the ticket. Tell the reporter when it is fixed.

## 22. A decision is made in conversation
**Starts:** "ok, let's go with B" in Slack, a comment, or a transcript. **Shape:** chained.
- Settle the intent that asked for the decision.
- Propose recording it where it belongs: the ticket, the epic, the spec. Decisions that live
  only in a Slack thread get lost.

## 23. A developer asks the user to choose
**Starts:** "A or B?" **Shape:** reactive.
- Gather the tradeoffs from code, specs, past decisions. Present the options with what each costs.
- Do not pick. The user picks. Then case 22.

## 24. The user cannot make a deadline
**Starts:** the calendar has no room, a dependency is late, or a slot was skipped. **Shape:** proactive.
- Warn before the deadline. Propose a message moving it, with a new date the calendar can actually hold.
- A renegotiated deadline is better than a missed one.

## 25. The ask is for the wrong person
**Starts:** someone asks the user for something someone else owns (archive and Jira say who). **Shape:** chained.
- Propose a redirect: reply with who owns it, or loop them in.
- Watch that the right person picked it up. If not, tell the user.

## 26. An acknowledgement that is not a delivery
**Starts:** "👍", "ok", "on it" to something the user asked. **Shape:** reactive.
- The intent stays open. The responder has confirmed. The watcher's deadline does not change.
- "Done", a link, or an attachment settles it. A thumbs-up does not.

## 27. The user is CC'd
**Starts:** an email where the user is in CC and not named in the body. **Shape:** reactive.
- Not an intent. Into the digest only if it touches something the user owns.

## 28. Automated messages
**Starts:** notifications, newsletters, system emails. **Shape:** mostly none.
- Noise by default.
- Some are deliveries: an access invitation, a calendar acceptance, a shared document.
  These settle watchers (case 5).
- Some are asks: a Jira ticket assigned to the user, a review requested on GitHub. These open intents.

## 29. A meeting that intents depended on moves or is cancelled
**Starts:** a calendar change to a meeting where something was going to be discussed. **Shape:** reactive.
- Find the intents waiting on that meeting ("we'll see it Thursday"). Move their deadlines.
- If an intent cannot wait for the new date, tell the user and propose settling it another way.

## 30. Recurring reporting
**Starts:** a recurring commitment (weekly update to the boss, sprint review). **Shape:** proactive.
- Before it is due, draft it from what was settled, decided and shipped since the last one.
  The user edits, then it goes out.

## 31. The user is away
**Starts:** out of office on the calendar. **Shape:** proactive.
- During: queue everything. For important asks, propose a note saying when the user is back
  and who else can help.
- On return: one digest. What came in, what was settled without them, what is waiting.

## 32. Sensitive messages
**Starts:** HR, health, legal, personal matters, a very angry client. **Shape:** reactive, restricted.
- Track as usual, but never act on its own, never quote into shared places (tickets, Notion),
  and never draft without being asked.

## 33. Strangers and cold outreach
**Starts:** a sender the archive does not know. **Shape:** reactive.
- Sales, recruiting, newsletters → noise, unless the user has asked to see them.
- An unknown person with a real ask about the user's product (a new colleague, a client contact)
  → intent, with a note that the person is new.

## 34. A stakeholder sends a feature request
**Starts:** someone from sales, support, ops or management asks for a new feature or change. **Shape:** chained.
- Check whether it already exists: in the product (repos), in the backlog (Jira), in the roadmap (Notion).
- If it exists, propose a reply with where it stands. If it is in the backlog, propose linking the request
  to the ticket so demand is counted.
- If it is new: structure it (who asked, which client or segment, what problem, how urgent they say it is)
  and propose creating a backlog item or adding it to the feedback log. Never promise a date.
- Propose a reply that acknowledges it without committing.

## 35. Sales promised something to a client
**Starts:** a message, email or CRM note where someone outside the team committed a feature or date to a client.
**Shape:** reactive, urgent.
- Tell the user now. Find what was promised, to whom, and by when.
- Check it against the roadmap and current tickets: is it planned, is it possible by that date?
- Propose a reply to the person who promised it: confirm, correct, or ask for a call.
  If the promise is already wrong, the earlier this is said the cheaper it is.

## 36. Two stakeholders want conflicting priorities
**Starts:** two people ask for different things to go first, or for the same capacity. **Shape:** proactive.
- Detect it across channels: the asks may never appear in the same thread.
- Show the user both asks side by side: who, what, why, and what each would displace.
- Propose a message to bring them together (a short meeting, or one thread with both), or a message
  to whoever decides priority.

## 37. A ticket is created without enough information
**Starts:** a ticket in the user's project with no acceptance criteria, no reproduction steps, or no context.
**Shape:** reactive.
- Fill what can be filled from the conversation that led to it (the Slack thread, the support email).
- Propose the missing questions as a comment to the reporter, or as edits to the ticket.

## 38. A ticket has been stuck
**Starts:** a ticket in progress or in review with no movement for longer than usual. **Shape:** proactive.
- Check for hidden reasons first: a linked PR waiting for review, a blocker comment, the assignee away.
- Tell the user, with the reason if found. Propose a check-in with the assignee or the reviewer.
- Stuck tickets in the current sprint or cycle go first.

## 39. A ticket is marked as blocked
**Starts:** a ticket moves to Blocked, or a comment says "blocked by". **Shape:** reactive.
- Find what blocks it: another ticket, another team, a decision, a missing design.
- If the blocker is the user (a decision, a spec, an answer), it is now an intent where the user is responder,
  with high importance.
- If the blocker is someone else, propose a message to them and create a watcher.

## 40. What was built does not match what was specified
**Starts:** a PR is merged or a ticket moves to review, and the code differs from the spec or the ticket.
**Shape:** reactive.
- Compare the merged change with the acceptance criteria and the spec.
- Tell the user what differs. Do not decide which one is right: the spec may be the outdated one.
- Propose a comment on the ticket asking about it, or an update to the spec if the user confirms the change.

## 41. The spec is out of date
**Starts:** a decision (case 22), a merge, or a ticket change that makes a Notion spec wrong. **Shape:** proactive.
- Point out the section that no longer holds and what changed it.
- Propose the edit to the page.

## 42. QA finds a bug before a release
**Starts:** a bug ticket or message from QA on something in the upcoming release. **Shape:** reactive.
- Find the release it affects and how close it is.
- Check whether it is new or already known (existing tickets, past threads).
- Tell the user with what is at stake: does it block the release or not? Propose a decision message:
  fix before, ship with a known issue, or pull the feature.

## 43. A release is coming
**Starts:** a release date in Jira, Notion or the calendar, a few days ahead. **Shape:** proactive.
- Check readiness: open tickets in the release, unmerged PRs, unresolved blockers, missing docs.
- Tell the user what is not ready.
- Draft release notes from the tickets and merged PRs, for the user to edit.
- Propose who should be told (support, sales, clients) and draft those messages.

## 44. A feature just shipped
**Starts:** a release goes out, or a feature flag is turned on. **Shape:** proactive.
- Propose telling the people who asked for it (from case 34, linked requests).
- Create a watcher for the first days: support messages, bug reports, client complaints about it.
- If the user tracks metrics the system cannot read, schedule a reminder to check them after a set time.

## 45. A hotfix or rollback
**Starts:** a revert, a hotfix branch, or a message about rolling something back. **Shape:** reactive, urgent.
- Tell the user now: what was rolled back, why, who is affected.
- Find the clients or stakeholders who were told the feature was live, and propose telling them.
- Reopen the related tickets, or propose it.

## 46. A design is ready for handoff
**Starts:** the designer says a design is ready, or links it on a ticket. **Shape:** chained.
- Compare the design with the ticket and spec: missing states, edge cases, flows not covered.
- Prepare the questions for the user to review before it goes to development.
- When the user approves, propose updating the ticket with the link and telling the developers.

## 47. Someone asks for an estimate or a date
**Starts:** "when will X be ready?" **Shape:** reactive.
- Gather the real state: ticket status, open PRs, the sprint or cycle it is in, blockers.
- Propose an answer with a range and what it depends on, not a single date the user did not give.
- If the user commits to a date, it becomes an intent with a watcher (as in case 8).

## 48. A recurring bug keeps coming back from support
**Starts:** several support messages or tickets about the same problem. **Shape:** proactive.
- Group them. Count how many, which clients, since when.
- If there is a ticket, propose adding the count and the clients to it. If there is not, propose creating one.
- Tell the user when the volume crosses what looks like a pattern.

## 49. Customer feedback arrives
**Starts:** feedback from a client: an email, an interview note, a survey answer, a support conversation.
**Shape:** reactive.
- Extract the feedback: what they liked, what hurt, what they asked for.
- Link it to existing tickets, epics or requests (case 34). Propose adding it to the feedback log.
- If it is a complaint that needs an answer, it is an intent. Otherwise, it is a signal.

## 50. Another team depends on the user's team, or the other way round
**Starts:** another team asks for something from the product, or the user's roadmap needs something
from another team. **Shape:** chained.
- Track it as an intent with a clear responder on the other team.
- Link it to the work that depends on it (case 16).
- Before planning or a deadline, check its status and warn if it is at risk.

## 51. Sprint or cycle planning is coming
**Starts:** a planning meeting in the calendar, a few days ahead. **Shape:** proactive.
- Prepare what feeds the plan: carry-over tickets, blocked tickets, urgent requests since the last plan,
  commitments to clients and stakeholders with dates, bugs above a threshold.
- Point out commitments that would be missed if they do not go in.

## 52. A retro or review produces action items
**Starts:** retro notes or a review transcript. **Shape:** proactive.
- Extract the action items, as in case 11.
- Create intents for the user's own items. Watch the others' items.
- Before the next retro, show which items were done and which were not.

## 53. A 1:1 is coming
**Starts:** a recurring 1:1 in the calendar. **Shape:** proactive.
- Brief the user: open intents in both directions with that person, recent wins, recent problems,
  things the user said they would bring up.
- For a 1:1 with the boss: what was delivered since the last one, what is at risk, decisions the user needs.

## 54. A client demo or presentation is coming
**Starts:** a demo or client meeting in the calendar. **Shape:** proactive.
- Gather what is known about the client: open requests, recent support issues, promises made (case 35).
- Check that what will be shown works and is shipped, or flag what is not.
- Brief the user before it.

## 55. Someone asks the user for access or permissions
**Starts:** "can you give me access to X?" **Shape:** reactive.
- Check whether the user can grant it, and whether it is something the system can do (sharing a Notion page,
  a Drive file, a calendar).
- Propose doing it. On yes, do it and tell the person.
- If the user cannot grant it, propose a redirect to who can (case 25).

## 56. A decision is reversed
**Starts:** someone reopens a decision already recorded (case 22). **Shape:** reactive.
- Tell the user, with the original decision, who made it, and why.
- Find what was built or planned on top of it: tickets, specs, promises.
- Propose bringing the people who made the original decision into the conversation.

## 57. A meeting ended without an outcome
**Starts:** a transcript (case 11) with discussion but no decision and no action points. **Shape:** proactive.
- Point it out. Propose a follow-up message with the open questions and who should answer each.

## 58. A new person joins the team or the project
**Starts:** a new member in a channel, a new assignee in Jira, an introduction email. **Shape:** proactive.
- Add them to the archive with what is known: role, team, what they will work on.
- Propose an onboarding message from the user with the key pages, tickets and people.

## 59. Someone leaves or changes role
**Starts:** a goodbye message, a role change announcement, an out-of-office with no return date.
**Shape:** proactive.
- Find every open intent where that person is responder. Tell the user, and propose who should take each.
- Update the archive.

## 60. A compliance, legal or security ask
**Starts:** legal, security or compliance asks about the product (data handling, a contract clause, an audit).
**Shape:** reactive, restricted.
- Gather what the product actually does from the repos and specs, with sources.
- Never send on its own. The user always reviews, because these answers are binding.

## 61. Interview feedback is due
**Starts:** the user interviewed a candidate, and the recruiter or hiring manager is waiting for feedback.
**Shape:** proactive.
- After the interview, remind the user while it is fresh. Propose a structure for the feedback.
- If the deadline nears, follow up with the user, not with the recruiter.

## 62. A vendor or tool needs approval
**Starts:** a renewal, an invoice, or a request to approve a tool. **Shape:** reactive.
- Gather what it is, what it costs, who uses it, when it is due.
- Propose approve, reject, or ask for more info. Never approve on its own.