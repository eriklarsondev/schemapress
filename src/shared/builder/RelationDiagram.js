/**
 * What a relation is, drawn.
 *
 * "Multiple: on, direction: inverse" is three settings; "one Author has many
 * Articles, and either end can read the other" is the thing being decided, and
 * the second one is a picture. The diagram is drawn from the configuration
 * rather than beside it, so it cannot describe a relation the schema does not
 * have.
 *
 * It reads in words — `one → many` — rather than in the `1`/`N` a database
 * diagram uses. The person reading it is deciding what their content IS, and
 * "many" is the word they would have used for it anyway.
 *
 * The line is always read from the field's own collection outwards, which is
 * what keeps the marks and the name underneath agreeing: standing on Article
 * this is many-to-one, and standing on Author the very same relationship is
 * one-to-many. Both are the ordinary names for it; which one you say depends on
 * where you are standing, and the diagram says where that is.
 */

import { __, sprintf } from '@wordpress/i18n'
import { ArrowRight, ArrowLeftRight, Layers } from 'lucide-react'
import { cn } from '../../ui'

/**
 * Whether a field reads entries back rather than storing them.
 *
 * Mirrors Relations::isInverse. It is a question about the FIELD and not about
 * its type — one relation type faces both ways.
 *
 * @param {Object} field
 * @return {boolean} True when nothing is stored here.
 */
export function isInverse(field) {
  return field?.type === 'relation' && field?.config?.direction === 'inverse'
}

/**
 * How many entries sit at each end of the line.
 *
 * Facing outwards: the near end is how many entries of THIS collection may hold
 * the same one — many, unless the field is unique, which is the whole of what
 * unique means here — and the far end is how many this field holds.
 *
 * Facing back, both answers belong to the relation being read: this collection
 * holds as many as that one is exclusive about, and the far end is however many
 * point here.
 *
 * @param {Object} field
 * @param {Object} through the relation it reads back, when it faces that way
 * @return {{near: string, far: string}} The two marks.
 */
export function marksFor(field, through) {
  const one = __('one', 'schemapress')
  const many = __('many', 'schemapress')

  if (isInverse(field)) {
    return {
      near: through?.unique ? one : through?.multiple ? many : one,
      far: many,
    }
  }

  return {
    near: field.unique ? one : many,
    far: field.config?.multiple ? many : one,
  }
}

/**
 * Which of the named kinds a configuration is.
 *
 * Worth saying even though nothing is keyed on it: somebody arriving from
 * another content API is looking for the words they already know, and the
 * absence of an inverse is the one thing the picture carries that those words
 * do not — so where there is none, the name says so instead.
 *
 * @param {Object}  field
 * @param {boolean} inverse whether the far collection reads it back
 * @param {Object}  through the relation this field reads back, if it does
 * @return {string} What the kind is called.
 */
export function kindOf(field, inverse, through) {
  if (isInverse(field)) {
    if (through?.unique) {
      return __('one-to-one', 'schemapress')
    }

    return through?.multiple ? __('many-to-many', 'schemapress') : __('one-to-many', 'schemapress')
  }

  const many = Boolean(field.config?.multiple)

  // no field reading it back, so the pair has no second name — these are what
  // a content API calls a relation you can only walk one way
  if (!inverse) {
    return many ? __('many-way', 'schemapress') : __('one-way', 'schemapress')
  }

  if (field.unique) {
    return __('one-to-one', 'schemapress')
  }

  return many ? __('many-to-many', 'schemapress') : __('many-to-one', 'schemapress')
}

/**
 * The relation in a sentence, in the collections' own words.
 *
 * @param {Object} props
 * @return {string} The sentence.
 */
function sentenceFor({ from, to, field, inverse, through }) {
  const near = from || __('This collection', 'schemapress')

  // the readable plural, never the machine one: `team_members` is the address
  // a URL uses and not a word to put in a sentence
  const plural = to.pluralLabel || to.label

  if (isInverse(field)) {
    return through
      ? sprintf(
          /* translators: 1: this collection's name, 2: the other collection's plural name, 3: a relation field's name */
          __(
            'Each %1$s lists the %2$s whose %3$s points at it. Nothing is stored here.',
            'schemapress'
          ),
          near,
          plural,
          through.label
        )
      : sprintf(
          /* translators: 1: this collection's name, 2: the other collection's plural name */
          __('Each %1$s lists the %2$s pointing at it. Nothing is stored here.', 'schemapress'),
          near,
          plural
        )
  }

  const forward = field.config?.multiple
    ? sprintf(
        /* translators: 1: this collection's name, 2: the target's plural name */
        __('Each %1$s holds several %2$s.', 'schemapress'),
        near,
        plural
      )
    : field.unique
    ? sprintf(
        /* translators: 1: this collection's name, 2: the target's name */
        __('Each %1$s holds one %2$s, and no other may hold the same one.', 'schemapress'),
        near,
        to.label
      )
    : sprintf(
        /* translators: 1: this collection's name, 2: the target's name */
        __('Each %1$s holds one %2$s, and many may hold the same one.', 'schemapress'),
        near,
        to.label
      )

  const back = inverse
    ? sprintf(
        /* translators: %s: the target collection's name */
        __('%s reads them back.', 'schemapress'),
        to.label
      )
    : sprintf(
        /* translators: %s: the target collection's name */
        __('%s has no field reading them back.', 'schemapress'),
        to.label
      )

  return `${forward} ${back}`
}

/**
 * One end of the connector: a collection, as a box.
 *
 * @param {Object} props
 * @return {JSX.Element} The box.
 */
function End({ label, muted }) {
  return (
    <span
      className={cn(
        'flex min-w-0 flex-1 items-center gap-2 rounded-md border px-2.5 py-2',
        muted
          ? 'border-dashed border-border bg-transparent text-muted-foreground'
          : 'border-border bg-background'
      )}
    >
      <Layers aria-hidden="true" className="size-3.5 shrink-0 text-muted-foreground" />
      <span className="min-w-0 truncate text-[13px] font-medium">{label}</span>
    </span>
  )
}

/**
 * A cardinality mark — the "one" or the "many" at one end of the line.
 *
 * @param {Object} props
 * @return {JSX.Element} The mark.
 */
function Mark({ value }) {
  return (
    <span
      // a mark is read against the line it sits on, so it keeps the page's
      // background rather than sitting in a tinted pill that would read as
      // another box in a row of boxes
      className="rounded border border-border bg-background px-1.5 text-[11px] font-semibold leading-[1.5] text-foreground"
    >
      {value}
    </span>
  )
}

/**
 * The marks and the arrow between them — the shape of a relation, on its own.
 *
 * @param {Object} props
 * @return {JSX.Element} The connector.
 */
function Wire({ near, far, both, back }) {
  const Arrow = both ? ArrowLeftRight : ArrowRight

  return (
    <span className="flex items-center gap-1">
      <Mark value={near} />
      <Arrow
        aria-hidden="true"
        className={cn(
          'size-4',
          both ? 'text-foreground' : 'text-muted-foreground',
          // a relation that only reads is drawn as the arrow coming back,
          // which is the one glance that says this end stores nothing
          back && !both && 'rotate-180'
        )}
      />
      <Mark value={far} />
    </span>
  )
}

/**
 * The shapes one relation field can take, as the picture each one makes.
 *
 * Four, which is every relation a single field can be: it holds one, it holds
 * one nobody else may hold, it holds a list, or it holds nothing and reads back
 * whatever points here. The six names a content API usually lists are these
 * four with and without a field at the other end — and that is a decision taken
 * on the other collection, drawn below rather than offered here as a card that
 * would write somewhere you are not looking.
 *
 * @param {Object} props
 * @return {JSX.Element} The picker.
 */
export function RelationShapes({ field, exclusive, inverse, onPick }) {
  const one = __('one', 'schemapress')
  const many = __('many', 'schemapress')

  const back = isInverse(field)
  const multiple = Boolean(field.config?.multiple)
  const unique = Boolean(field.unique)

  const shapes = [
    {
      key: 'one',
      marks: [many, one],
      label: __('Holds one', 'schemapress'),
      hint: __('Several may hold the same one.', 'schemapress'),
      on: !back && !multiple && !unique,
      pick: { direction: 'owns', multiple: false, unique: false },
    },
    {
      key: 'exclusive',
      marks: [one, one],
      label: __('Holds one, exclusively', 'schemapress'),
      hint: __('No two may hold the same one.', 'schemapress'),
      on: !back && !multiple && unique,
      pick: { direction: 'owns', multiple: false, unique: true },
      only: exclusive,
    },
    {
      key: 'many',
      marks: [many, many],
      label: __('Holds several', 'schemapress'),
      hint: __('A list, in the order you choose.', 'schemapress'),
      on: !back && multiple,
      pick: { direction: 'owns', multiple: true, unique: false },
    },
    {
      key: 'inverse',
      marks: [one, many],
      label: __('Reads back', 'schemapress'),
      hint: __('Stores nothing. Lists what points here.', 'schemapress'),
      on: back,
      pick: { direction: 'inverse', multiple: false, unique: false },
      only: inverse,
      back: true,
    },
  ].filter((shape) => shape.only !== false)

  return (
    // one row, as many columns as there are shapes to offer — a fixed four
    // would leave the two a repeater row gets stretched over half a row of
    // nothing. The count is spelled out rather than built, because Tailwind
    // reads these class names out of the source and never sees an
    // interpolated one.
    //
    // Two columns below `sm`: four cards across a phone is four columns of
    // one word each
    <div
      className={cn(
        'grid grid-cols-2 gap-2',
        shapes.length === 2 && 'sm:grid-cols-2',
        shapes.length === 3 && 'sm:grid-cols-3',
        shapes.length === 4 && 'sm:grid-cols-4'
      )}
    >
      {shapes.map((shape) => (
        <button
          key={shape.key}
          type="button"
          aria-pressed={shape.on}
          onClick={() => onPick(shape.pick)}
          className={cn(
            // the cards share a row, so they share a height — the grid gives
            // them that, and `justify-start` keeps the picture at the top of
            // each rather than floating in the middle of the tall ones
            'flex flex-col items-start justify-start gap-1 rounded-lg border p-2 text-left transition-colors',
            shape.on
              ? 'border-ring/50 bg-accent/40'
              : 'border-border hover:border-ring/30 hover:bg-accent/20'
          )}
        >
          <Wire near={shape.marks[0]} far={shape.marks[1]} back={shape.back} />

          {/* a quarter of a row is not wide enough for "Holds one,
              exclusively" on one line, so it wraps — tightly, or two lines of
              label read as two separate things */}
          <span className="text-[13px] font-medium leading-tight">{shape.label}</span>
          <span className="text-[11px] leading-tight text-muted-foreground">{shape.hint}</span>
        </button>
      ))}
    </div>
  )
}

/**
 * The relation, drawn and then named.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The diagram, or null before a target is chosen.
 */
export function RelationDiagram({ field, from, to, inverse, through }) {
  if (!to) {
    return null
  }

  const back = isInverse(field)
  const ends = marksFor(field, through)

  return (
    <div className="flex flex-col gap-2 rounded-md border border-border bg-muted/30 p-3">
      <div className="flex items-center gap-2">
        <End label={from || __('This collection', 'schemapress')} muted={!from} />

        <span className="flex shrink-0 flex-col items-center gap-0.5">
          {/* one arrow or two is the whole of what an inverse looks like: a
              relation you can only walk one way, or one either end can read.
              Facing back, the pair always exists — this field IS the other
              half of it */}
          <Wire near={ends.near} far={ends.far} both={back || inverse} />

          <span className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
            {kindOf(field, inverse, through)}
          </span>
        </span>

        <End label={to.label} />
      </div>

      <p className="text-[12px] text-muted-foreground">
        {sentenceFor({ from, to, field, inverse, through })}
      </p>
    </div>
  )
}
