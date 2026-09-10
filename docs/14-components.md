<!-- group: Modeling content -->
<!-- description: A named group of fields, defined once and reused — and why importing one copies it rather than pointing at it. -->

## Components

An address is a street, a city and a postcode. Once you have typed that into three
collections you have three chances to have typed it differently, and a template that reads
`address.city` from one and `city` from another.

A **component** is that shape, named, so it is described in one place.

### Making one

Components live in their own section of the sidebar. A component is a list of fields and
nothing else — it has no entries, and nothing is ever saved *as* one.

Building it is the same screen as building a collection's fields, because it is the same
thing minus the content.

### Using one

On a collection's **Schema** tab, add a field and choose **Import component**. Its fields
arrive as a **Group**, so an address imported into Team Members is reachable as:

```twig
{{ person.address.city }}
```

The group takes the component's name by default. Rename it and the path changes with it —
which is how the same component can appear twice in one collection as `home_address` and
`postal_address`.

### Importing copies, it does not link

This is the decision worth knowing about. When you import a component, its fields are
**copied into the collection**. The collection does not point back at the component, and
changing the component afterwards does not reach content that already exists.

:::caution A copy can drift, and that is the lesser problem
A shared definition would mean editing a component silently reshapes content stored in
collections you were not looking at — renaming a field there orphans values here. There is
no migration story for that yet, so the copy is honest about what it is at the moment you
make it.

The practical consequence: fix a component's typo and you fix it for collections built
*after* that, not before. Deleting a component never takes content down with it.
:::

### Components in an export

Components travel with every export, whichever collections were selected. They are small,
and a collection whose fields came from one is more useful beside it than without it.

On import they are matched by **name** — a component has no machine key, because nothing
stores content against it, so the label is the only identity it has.
