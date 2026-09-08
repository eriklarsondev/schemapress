<!-- group: Modelling content -->
<!-- description: The rules a collection can put on its own values, and where each one is enforced. -->

## Validation

A field can carry rules about what it will accept. They are set on the **Schema** tab, next
to the field they belong to, and they are enforced on **every** write — the entry form, the
REST route behind it, and anything else that calls `Entries::save()`.

That last part is the important one. These rules used to live only in the browser: the Save
button greyed out and nothing behind it checked. An importer, a migration script or a
direct call to the admin route stored whatever it was given.

| Rule | Set on | Means |
| --- | --- | --- |
| **Required** | any field | Must be answered before the entry can be saved |
| **Must be unique** | Text, Textarea, Email, URL, Phone, Number, Date, Time | No two entries in the collection may hold the same value |
| **Max length** | Text, Textarea, Email, URL, Phone | At most this many characters |
| **Min** / **Max** | Number | Within these bounds, inclusive |
| **Min rows** / **Max rows** | Repeater | How many rows the list may hold |

### What counts as answered

An **unticked toggle is answered.** It is off, which is a value; treating it as missing
would turn a required toggle into a box you cannot save without ticking, and that is a
different feature.

**Zero is a number**, not an absence — and a minimum of `0` is a real bound, which is the
one a quantity field is most likely to want.

A **link** is answered once it points somewhere. A label with no url is an empty control.

A **required repeater** needs at least one row. Required fields *inside* a row are checked
for every row, and the message says which one: `Links 2 → Label is required.`

### Hidden fields are not asked for

A field hidden by its condition is not on screen, so it cannot be missing. A required field
inside a condition that is not met is skipped entirely.

This is the rule that has to match on both sides. If the form hid a field and the server
still demanded it, the entry could never be saved and nothing on screen would explain why.

### Uniqueness

Uniqueness is asked of the whole collection, drafts included — two drafts claiming the same
value are a collision waiting for the moment they are both published, not a collision that
has not happened.

**Empty values never collide.** Three entries that have not filled in a reference number
yet are not three entries with the same reference number.

An entry is never a duplicate of itself, which is what makes a unique field editable.

:::caution Uniqueness is top-level only
A field inside a Repeater or a Component cannot be unique. The mirror that answers the
question holds one value per field per entry, and a repeater has many — the same reason
those fields cannot be filtered. See **Indexing** under Content API.
:::

### When a rule is broken

The write is refused and **nothing is stored** — a rejected save that had already created
the post would leave an empty entry in the collection as the price of a typo.

```json
{
  "code": "schemapress_invalid_entry",
  "message": "Email is required. Name must be 5 characters or fewer.",
  "data": {
    "status": 400,
    "fields": [
      { "key": "email", "label": "Email", "message": "Email is required." },
      { "key": "name", "label": "Name", "message": "Name must be 5 characters or fewer." }
    ]
  }
}
```

`message` is the sentence to show. `fields` repeats it broken up, so a client that wants to
mark up the offending controls can find them by key.

:::note This is the admin transport, not the content API
The content API is read-only, so it never validates anything. These refusals come from the
routes the builder writes through.
:::
