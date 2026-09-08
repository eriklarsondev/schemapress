<!-- group: Content API -->
<!-- description: Which field types can be filtered and sorted, why the rest cannot, and keeping the index current. -->

## What can be queried

### What can be filtered and sorted

Values are stored as one JSON document per entry, which is the right shape for reading an
entry and the wrong shape for asking questions across a collection. So every scalar value
is written a second time where the database can see it, and that mirror is what filters and
sorts run against.

**Filterable:** Text, Textarea, Email, URL, Phone, Dropdown, Number, Toggle, Image, File.

**Not filterable:** Rich text (a blob of markup, which nothing sensible can be asked
about), Link and Group (several values in one field), and anything inside a Repeater (many
values per entry, which one row cannot hold).

A filter or sort naming a field that cannot be indexed is **dropped**, and so is one naming
a field that does not exist. The query then runs without it — which means it returns
*more* than you asked for, not fewer.

:::caution A dropped filter widens the result
There is no way to tell a mistyped field name from an unrelated query parameter, so a
filter that names nothing is ignored rather than refused. If a filter seems to have no
effect, check the field's machine key on the Schema tab — the label is not the key.
:::

Sorting by a field that most entries left empty still returns all of them. Empty values are
indexed as empty rather than skipped, so nothing drops out of a sorted list.

### Keeping the index current

The mirror is rebuilt automatically whenever an entry is published, and whenever a
collection's fields change — renaming a field rebuilds every entry in that collection.

:::tip Backfilling existing entries
If you have entries that predate this API, open the collection's **Schema** tab and save it
once. That rebuilds the index for the whole collection, and is the only migration step
there is.
:::
