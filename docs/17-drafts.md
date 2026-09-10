<!-- group: Modeling content -->
<!-- description: Two copies of every entry, what each one is for, and what the trash does with them. -->

## Drafts, publishing and the trash

An entry holds **two copies** of its values, and the distinction between them is the point:

| | |
| --- | --- |
| **Published** | What the front end is serving right now |
| **Draft** | What somebody is working on |

Saving writes the draft. The published copy does not move until you publish, so editing a
live entry never takes it off the site half-finished.

### The three states

| Badge | Means |
| --- | --- |
| **Draft** | Never published. The front end cannot see it at all |
| **Published** | Live, and the draft agrees with it |
| **Edited** | Live, with saved changes that are not |

An **Edited** entry says how far it has diverged — "3 changes ahead of published". That is
a count of *changes*, not of saves: pressing save twice on the same text is one change, and
saving text identical to what is published is not a change at all.

### The three actions

**Publish** fast-forwards. The draft becomes the published copy and the count resets.

**Unpublish** takes the entry off the site and keeps the work. Its filters come down with
it, so nothing can query an entry the API then refuses to show.

**Discard** throws the draft away and returns to what is published.

### Turning drafts off

A collection can be set to publish on save, in its **Settings**. Some collections want a
working copy — a page of copy someone drafts over a week — and some are a list of facts
where an extra step before anything appears is only friction.

:::caution Turning drafts off publishes every draft
There is then one copy of each entry, and it is the one the site is serving. Anything
currently unpublished becomes published. Turning it back on does not undo that.
:::

### The trash

Deleting an entry moves it to the **trash**, where it stays until you restore it, erase it,
or WordPress empties the trash on its own schedule (30 days by default).

The **Trash** tab of a collection lists what is in there and when each entry went. Restoring
brings an entry back to the state it was in — live entries come back live, with their
filters working again.

Erasing permanently is offered only from the trash, and only to somebody who can change the
shape of content. An entry has already been somewhere recoverable first.

:::note Trashed entries are never served
Not by the API, not by `SchemaPress::collection()`, not by search. Their index rows are
cleared the moment they are trashed, so nothing can match one.
:::

### Two people at once

If somebody else saves an entry while you have it open, your save is **refused** rather
than applied — a 409, with a message saying so and your work still in the form.

**Keep mine and continue** takes their version as the baseline and leaves what you typed
alone, so pressing save again goes through and saves your version. Nothing on screen is
lost by pressing it; what it costs is that their change is the one you are now writing over,
knowingly.

The same protection covers a collection's **Schema** tab, where the stakes are higher: that
save takes the whole field list, so two people editing it in two tabs used to mean the
second save silently deleted whatever the first had added.
