# Scheduled task prompt

mailbox-mcp has no push/IDLE support by design (see the roadmap in the
main README) — a Claude scheduled task polls the mailbox instead. This
task drafts replies and marks mail as handled, but it must **never**
send anything on its own: sending always requires the operator's
explicit, in-conversation approval.

## Recommended schedule

Hourly, during hours mail actually needs attention, e.g. **07:00–22:00,
every hour**. Running it overnight gains nothing (nobody is there to
approve a send) and just adds noise to the audit log.

## The prompt

Use this as the scheduled task's prompt (adjust the account name if you
run more than one mailbox and want a separate task per account):

> Check for new mail that needs a reply.
>
> 1. Call `search_emails` with `exclude_processed: true` and `since` set
>    to 2 hours ago, to find unprocessed mail from that window.
> 2. Skip newsletters, automated notifications, and anything that
>    clearly needs no reply.
> 3. For each message that does need a reply:
>    - Call `get_reply_guidelines` for the account first and follow it.
>    - Call `create_draft` with `in_reply_to_uid` set to that message's
>      uid, so the reply threads correctly.
> 4. Call `mark_processed` on every message you looked at in step 1,
>    whether or not you drafted a reply, so the next run doesn't see it
>    again.
> 5. Finish with a short summary: for each message handled, the sender,
>    the topic, and whether a draft was created (yes/no).
>
> **Never call `send_draft` in this scheduled run, under any
> circumstances.** Drafting is the only action this task is allowed to
> take; sending requires the operator's explicit approval in a live
> conversation.

## Reviewing and sending drafts

The operator reviews drafts the scheduled task created either:

- directly in their mail client's Drafts folder, editing and sending
  from there as usual (mailbox-mcp never touches a draft it didn't
  create unless asked to), or
- by asking Claude, in a normal chat, to show a specific draft (e.g.
  "show me the draft you created for the message from Jane"), then
  approving it explicitly before Claude calls `send_draft` for that
  exact `draft_uid`.

`send_draft`'s own tool description reinforces this: it tells the model
to call it only after the user has explicitly approved sending that
specific draft in the current conversation, and never in an automated
or scheduled run.
