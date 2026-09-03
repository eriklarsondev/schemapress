/**
 * The field tree editor.
 *
 * A field is the most important object on this screen, so it is drawn as one: a
 * card with its type as an icon, its label at reading size, and its machine key
 * beside it. The previous version was a hairline row that collapsed to almost
 * nothing, which made a schema of eight fields look like an empty box with some
 * text in it.
 *
 * Adding a field asks what kind first. Defaulting to Text and making you change
 * it afterwards buries the one decision that actually shapes the data.
 *
 * FieldsEditor and FieldRow are mutually recursive: group and repeater fields
 * nest another FieldsEditor, so a schema can describe arbitrarily deep
 * structures with one component pair.
 */

import { Fragment, useEffect, useState } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import {
  Trash2,
  Plus,
  ChevronRight,
  Type,
  AlignLeft,
  FileText,
  AtSign,
  Globe,
  Phone,
  Hash,
  ToggleRight,
  ListChecks,
  Image as ImageIcon,
  Paperclip,
  Link2,
  Rows3,
  CircleHelp,
  Blocks,
} from 'lucide-react'
import { move, removeAt, replaceAt, toKey, uniqueKey } from '../utils'
import { datasets } from '../settings'
import { Button, Input, Field, Select, Switch, Badge, Dialog, Tabs, TabPanel, cn } from '../../ui'
import { api } from '../api'
import { FieldConfig } from './FieldConfig'

/**
 * An icon per field type. A type is the thing you scan a schema for, and a
 * word in a corner is not scannable at eight fields.
 */
const ICONS = {
  text: Type,
  textarea: AlignLeft,
  wysiwyg: FileText,
  email: AtSign,
  url: Globe,
  phone: Phone,
  number: Hash,
  toggle: ToggleRight,
  select: ListChecks,
  image: ImageIcon,
  file: Paperclip,
  link: Link2,
  group: Blocks,
  repeater: Rows3,
}

/**
 * Types that hold child fields.
 *
 * The registry PHP sends says which types nest, but it only lists types you can
 * PICK — and group, which is what an imported component becomes, is not one of
 * those. Stating it here keeps a component's sub fields editable.
 */
const NESTS = ['group', 'repeater']

/**
 * What an internal type is called on screen. `group` is the storage name; what
 * you actually have in front of you is a component you imported.
 */
const INTERNAL_LABELS = {
  group: __('Component', 'schemapress'),
}

/**
 * The icon for a field type.
 *
 * @param {string} type
 * @return {Function} A lucide icon component.
 */
function iconFor(type) {
  return ICONS[type] || CircleHelp
}

/**
 * A one-line description of what a field is configured to do, for its collapsed
 * card — so the card says something even before it is opened.
 *
 * @param {Object} field
 * @return {string} The summary, or an empty string.
 */
function summarize(field) {
  const config = field.config || {}

  switch (field.type) {
    case 'select': {
      // a dataset-backed dropdown keeps no copy of its options, so counting
      // them would report "no choices yet" about a field with 249 of them
      const dataset = datasets.find((set) => set.slug === config.source)

      if (dataset) {
        return dataset.label.toLowerCase()
      }

      const count = (config.options || []).length

      return count
        ? sprintf(
            /* translators: %d: number of choices */
            __('%d choices', 'schemapress'),
            count,
          )
        : __('no choices yet', 'schemapress')
    }

    case 'repeater': {
      const count = (field.fields || []).length
      const bounds = [
        config.min ? sprintf(__('min %d', 'schemapress'), config.min) : '',
        config.max ? sprintf(__('max %d', 'schemapress'), config.max) : '',
      ].filter(Boolean)

      return [
        sprintf(
          /* translators: %d: number of sub fields */
          __('%d sub fields', 'schemapress'),
          count,
        ),
        ...bounds,
      ].join(' · ')
    }

    case 'group':
      return sprintf(
        /* translators: %d: number of sub fields */
        __('%d sub fields', 'schemapress'),
        (field.fields || []).length,
      )

    default:
      return config.placeholder || ''
  }
}

/**
 * An ordered list of field definitions.
 *
 * @param {Object} props
 * @return {JSX.Element} The list editor.
 */
export function FieldsEditor({ fields, fieldTypes, onChange, nested = false, editing = 0 }) {
  const [picking, setPicking] = useState(false)

  // a field created from the picker opens straight away: you chose a type, and
  // naming it is the very next thing you were going to do
  const [opened, setOpened] = useState(null)

  const [dragging, setDragging] = useState(-1)

  /**
   * Appends a field of a chosen type.
   *
   * @param {Object} type
   * @return {void}
   */
  const addField = (type) => {
    const key = uniqueKey(
      'field',
      fields.map((field) => field.key),
    )

    onChange([
      ...fields,
      {
        key,
        label: __('New Field', 'schemapress'),
        type: type.type,
        help: '',
        required: false,
        config: {},
        ...(type.children ? { fields: [] } : {}),
      },
    ])

    setPicking(false)
    setOpened(key)
  }

  /**
   * Appends a dropdown already pointed at a ready-made list.
   *
   * Named after the list, because "Countries" is what you came to add — and
   * unlike a blank field, this one is complete the moment it exists.
   *
   * @param {Object} dataset
   * @return {void}
   */
  const addDataset = (dataset) => {
    const key = uniqueKey(
      toKey(dataset.label),
      fields.map((field) => field.key),
    )

    onChange([
      ...fields,
      {
        key,
        label: dataset.label,
        type: 'select',
        help: '',
        required: false,
        config: { source: dataset.slug },
      },
    ])

    setPicking(false)
    setOpened(key)
  }

  /**
   * Appends a component as a group, copying its fields in.
   *
   * A copy, not a link: a shared definition would mean editing a component
   * silently reshapes content that already exists elsewhere, and there is no
   * migration story for that. The copy can drift, which is the lesser problem.
   *
   * @param {Object} component
   * @return {void}
   */
  const importComponent = (component) => {
    setPicking(false)

    api
      .component(component.id)
      .then((result) => {
        const key = uniqueKey(
          toKey(result.component.label),
          fields.map((field) => field.key)
        )

        onChange([
          ...fields,
          {
            key,
            label: result.component.label,
            type: 'group',
            help: '',
            required: false,
            config: {},
            fields: result.component.fields,
          },
        ])

        setOpened(key)
      })
      .catch(() => {})
  }

  /**
   * Reorders as a dragged card passes over another, rather than on drop.
   *
   * The list moves under the pointer, so what you see mid-drag is the order
   * you will get — there is nothing to aim at and no insertion line to read.
   *
   * @param {number} over
   * @return {void}
   */
  const dragOver = (over) => {
    if (dragging === -1 || dragging === over) {
      return
    }

    onChange(move(fields, dragging, over))
    setDragging(over)
  }

  return (
    <div className="flex flex-col gap-2">
      {fields.map((field, index) => (
        // keyed by position, not by field.key: a new field's key tracks its
        // label as you type it, so keying on that would remount the row and
        // drop the caret after every keystroke
        <FieldRow
          key={index}
          field={field}
          index={index}
          siblingKeys={fields.filter((_, i) => i !== index).map((f) => f.key)}
          fieldTypes={fieldTypes}
          editing={editing}
          startOpen={opened === field.key}
          dragging={dragging === index}
          onOpened={() => setOpened(null)}
          onChange={(next) => onChange(replaceAt(fields, index, next))}
          onDragStart={() => setDragging(index)}
          onDragOver={() => dragOver(index)}
          onDragEnd={() => setDragging(-1)}
          onRemove={() => onChange(removeAt(fields, index))}
        />
      ))}

      {fields.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border px-4 py-8 text-center">
          <p className="text-[13px] font-medium">
            {nested ? __('No sub fields yet', 'schemapress') : __('No fields yet', 'schemapress')}
          </p>
          <p className="mt-0.5 text-[12px] text-muted-foreground">
            {__('Add one to describe what an entry holds.', 'schemapress')}
          </p>
        </div>
      ) : null}

      <button
        type="button"
        onClick={() => setPicking(true)}
        className="flex items-center justify-center gap-1.5 rounded-lg border border-dashed border-border py-2.5 text-[13px] font-medium text-muted-foreground transition-colors hover:border-ring/40 hover:bg-accent/40 hover:text-foreground"
      >
        <Plus className="size-4" />
        {__('Add field', 'schemapress')}
      </button>

      {picking ? (
        <FieldTypePicker
          nested={nested}
          fieldTypes={fieldTypes}
          editing={editing}
          onPick={addField}
          onPickDataset={addDataset}
          onImport={importComponent}
          onClose={() => setPicking(false)}
        />
      ) : null}
    </div>
  )
}

/**
 * What can be added to a field list: a built-in type, a ready-made dropdown, or
 * one of your own components.
 *
 * Two tabs rather than one long grid, because they are different kinds of
 * answer. A primitive is a decision about one value; a component is a shape
 * somebody already worked out, and picking one is closer to reusing a decision
 * than to making one.
 *
 * Components are fetched when the dialog opens rather than passed down. They
 * change on another screen entirely, and a stale list here would offer to
 * import something that no longer exists.
 *
 * @param {Object} props
 * @return {JSX.Element} The picker.
 */
function FieldTypePicker({ fieldTypes, nested, editing, onPick, onPickDataset, onImport, onClose }) {
  const [tab, setTab] = useState('primitives')
  const [components, setComponents] = useState(null)

  useEffect(() => {
    let live = true

    api
      .components()
      // a component cannot import itself: it would copy its own fields into
      // itself, which is not a shape anybody meant to describe
      .then(
        (result) =>
          live &&
          setComponents((result.components || []).filter((one) => one.id !== editing))
      )
      .catch(() => live && setComponents([]))

    return () => {
      live = false
    }
  }, [])

  const tabs = [
    { value: 'primitives', label: __('Field types', 'schemapress'), icon: Type },
    { value: 'components', label: __('My components', 'schemapress'), icon: Blocks },
  ]

  // a repeater inside a repeater is a list of lists, which nothing downstream
  // renders and which cannot be arranged on the form. it is not offered rather
  // than offered and then quietly broken
  const offered = nested ? fieldTypes.filter((type) => !type.repeatable) : fieldTypes

  return (
    <Dialog
      open
      size="md"
      onOpenChange={(next) => !next && onClose()}
      title={__('Add a field', 'schemapress')}
      description={__('What kind of thing does this hold?', 'schemapress')}
    >
      <Tabs tabs={tabs} value={tab} onValueChange={setTab}>
        <TabPanel value="primitives">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {offered.map((type) => {
              const Icon = iconFor(type.type)

              return (
                <button
                  key={type.type}
                  type="button"
                  onClick={() => onPick(type)}
                  className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-left transition-colors hover:border-ring/40 hover:bg-accent/40"
                >
                  <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                    <Icon className="size-4" />
                  </span>
                  <span className="min-w-0 truncate text-[13px] font-medium">{type.label}</span>
                </button>
              )
            })}
          </div>

          {/* the ready-made lists are here, beside the types, because that is
              where you look for "I need a country field". Buried as a setting
              inside Dropdown they were something you had to already know about */}
          {datasets.length > 0 ? (
            <>
              <p className="mb-2 mt-4 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                {__('Ready-made dropdowns', 'schemapress')}
              </p>

              <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {datasets.map((set) => (
                  <button
                    key={set.slug}
                    type="button"
                    onClick={() => onPickDataset(set)}
                    className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-left transition-colors hover:border-ring/40 hover:bg-accent/40"
                  >
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                      <ListChecks className="size-4" />
                    </span>

                    <span className="min-w-0">
                      <span className="block truncate text-[13px] font-medium">{set.label}</span>
                      <span className="block text-[11px] text-muted-foreground">
                        {sprintf(
                          /* translators: %d: number of choices */
                          __('%d choices', 'schemapress'),
                          set.options.length,
                        )}
                      </span>
                    </span>
                  </button>
                ))}
              </div>
            </>
          ) : null}
        </TabPanel>

        <TabPanel value="components">
          {components === null ? (
            <p className="py-6 text-center text-[13px] text-muted-foreground">
              {__('Loading…', 'schemapress')}
            </p>
          ) : components.length === 0 ? (
            <div className="rounded-lg border border-dashed border-border px-4 py-8 text-center">
              <p className="text-[13px] font-medium">
                {__('No components yet', 'schemapress')}
              </p>
              <p className="mt-0.5 text-[12px] text-muted-foreground">
                {__('Make one from the sidebar to reuse a shape across collections.', 'schemapress')}
              </p>
            </div>
          ) : (
            <div className="flex flex-col gap-2">
              {components.map((component) => (
                <button
                  key={component.id}
                  type="button"
                  disabled={component.fields === 0}
                  onClick={() => onImport(component)}
                  className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-left transition-colors hover:border-ring/40 hover:bg-accent/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                    <Blocks className="size-4" />
                  </span>

                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] font-medium">
                      {component.label}
                    </span>
                    <span className="block truncate text-[12px] text-muted-foreground">
                      {component.description ||
                        sprintf(
                          /* translators: %d: number of fields */
                          _n(
                            '%d field',
                            '%d fields',
                            component.fields,
                            'schemapress'
                          ),
                          component.fields
                        )}
                    </span>
                  </span>

                  <Badge variant="outline">{__('Import', 'schemapress')}</Badge>
                </button>
              ))}
            </div>
          )}

          <p className="mt-3 text-[12px] text-muted-foreground">
            {__(
              'Importing copies the component’s fields in. Editing the component later does not change this collection.',
              'schemapress'
            )}
          </p>
        </TabPanel>
      </Tabs>
    </Dialog>
  )
}

/**
 * One field definition, as a card that opens onto its settings.
 *
 * @param {Object} props
 * @return {JSX.Element} The field editor.
 */
function FieldRow({
  field,
  index,
  siblingKeys,
  fieldTypes,
  editing,
  startOpen,
  dragging,
  onOpened,
  onChange,
  onDragStart,
  onDragOver,
  onDragEnd,
  onRemove,
}) {
  const [open, setOpen] = useState(Boolean(startOpen))

  // a key is the address of every value already stored under it, so renaming
  // one orphans that content. the key tracks the label only while it is still
  // the untouched placeholder a new field is created with; after that it stays
  // put unless it is edited deliberately.
  const [derived, setDerived] = useState(() => /^field(_\d+)?$/.test(field.key))

  const type = fieldTypes.find((candidate) => candidate.type === field.type)

  // a type the registry does not offer is an INTERNAL one — group, which is
  // what an imported component becomes. it is still perfectly valid to store,
  // it just is not something you pick, and so not something you can switch to
  // or away from either
  const switchable = Boolean(type)
  const nests = Boolean(type?.children) || NESTS.includes(field.type)
  const Icon = iconFor(field.type)
  const summary = summarize(field)

  // a repeater is set up in a dialog rather than inline: naming a list and
  // describing one of its rows are two jobs, and an inline panel presented them
  // as one long form with a whole field editor buried at the bottom of it
  const stepped = field.type === 'repeater'

  // a dropdown drawing on a ready-made list is that list; it is not a type you
  // can swap out from under itself
  const preset = Boolean(field.config?.source)

  /**
   * Merges a partial change into the field.
   *
   * @param {Object} patch
   * @return {void}
   */
  const update = (patch) => onChange({ ...field, ...patch })

  /**
   * Retypes a field, seeding a child list when moving to a nesting type and
   * clearing config that no longer applies.
   *
   * @param {string} next
   * @return {void}
   */
  const changeType = (next) => {
    const definition = fieldTypes.find((candidate) => candidate.type === next)

    update({
      type: next,
      config: {},
      fields: definition?.children ? field.fields || [] : undefined,
    })
  }

  return (
    <div
      // the whole closed card is the handle — grab it anywhere. an OPEN card
      // is not draggable: it is full of inputs, and a draggable ancestor stops
      // you selecting the text inside them
      draggable={!open}
      onDragStart={(event) => {
        event.stopPropagation()
        event.dataTransfer.effectAllowed = 'move'
        // Firefox refuses to start a drag without payload
        event.dataTransfer.setData('text/plain', field.key || String(index))
        onDragStart()
      }}
      onDragOver={(event) => {
        event.preventDefault()
        // a nested field list is inside this one; without this its drags would
        // reorder the parent as well
        event.stopPropagation()
        event.dataTransfer.dropEffect = 'move'
        onDragOver()
      }}
      onDrop={(event) => {
        event.preventDefault()
        event.stopPropagation()
        onDragEnd()
      }}
      onDragEnd={onDragEnd}
      className={cn(
        'overflow-hidden rounded-lg border transition-colors',
        // a component sits darker than a plain field: it is a shape that came
        // from somewhere else, and a list of eight fields with one component in
        // it should say so before you read a word
        switchable ? 'bg-background' : 'bg-muted/50',
        open ? 'border-ring/40' : 'cursor-grab border-border hover:border-ring/30',
        // the card being dragged becomes the slot it will land in: an outlined
        // gap the size of the card, so the destination is a shape on screen
        // rather than something to infer from where the cursor happens to be
        dragging &&
          'cursor-grabbing border-dashed border-ring/60 bg-accent/40 [&_*]:invisible',
      )}
    >
      {/* open, the row is a title bar over the form rather than another white
          band merging into it: tinted and separated, so the card reads as one
          field being edited instead of a page of loose inputs */}
      <div
        className={cn(
          'group flex items-center gap-3 p-3 transition-colors',
          open && 'border-b border-border bg-muted/60',
        )}
      >
        <span
          className={cn(
            'flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors',
            open ? 'bg-background text-foreground' : switchable ? 'bg-muted' : 'bg-background',
          )}
        >
          <Icon className="size-4" />
        </span>

        <button
          type="button"
          aria-expanded={open}
          onClick={() => {
            setOpen((state) => !state)
            onOpened?.()
          }}
          className="flex min-w-0 flex-1 flex-col items-start gap-0.5 text-left"
        >
          <span className="flex min-w-0 flex-wrap items-center gap-1.5">
            <span className="text-[14px] font-semibold">
              {field.label || __('(unnamed)', 'schemapress')}
            </span>
            <Badge variant="mono">{field.key}</Badge>
            {field.required ? (
              <Badge variant="warning">{__('required', 'schemapress')}</Badge>
            ) : null}
          </span>

          <span className="flex min-w-0 items-center gap-1.5 text-[12px] text-muted-foreground">
            <span>{type?.label || INTERNAL_LABELS[field.type] || field.type}</span>
            {summary ? (
              <>
                <span aria-hidden="true">·</span>
                <span className="truncate">{summary}</span>
              </>
            ) : null}
          </span>
        </button>

        <span className="flex shrink-0 items-center gap-0.5">
          <Button
            size="icon-sm"
            variant="destructive-ghost"
            aria-label={__('Remove field', 'schemapress')}
            onClick={onRemove}
          >
            <Trash2 />
          </Button>

          <ChevronRight
            aria-hidden="true"
            className={cn(
              'ml-1 size-4 text-muted-foreground/40 transition-transform',
              open && !stepped && 'rotate-90',
            )}
          />
        </span>
      </div>

      {open && !stepped ? (
        <div className="flex flex-col gap-4 bg-background p-4">
          <div className={cn('grid gap-3', switchable ? 'sm:grid-cols-3' : 'sm:grid-cols-2')}>
            <Field label={__('Label', 'schemapress')}>
              {(id) => (
                <Input
                  id={id}
                  value={field.label}
                  onChange={(event) =>
                    update(
                      derived
                        ? {
                            label: event.target.value,
                            key: uniqueKey(toKey(event.target.value), siblingKeys),
                          }
                        : { label: event.target.value },
                    )
                  }
                />
              )}
            </Field>

            <Field label={__('Key', 'schemapress')}>
              {(id) => (
                <Input
                  id={id}
                  className="font-mono text-[12px]"
                  value={field.key}
                  onChange={(event) => {
                    setDerived(false)
                    update({ key: uniqueKey(toKey(event.target.value), siblingKeys) })
                  }}
                />
              )}
            </Field>

            {/* an imported component is a group, and group is not a type you
                can pick — so there is nothing to offer here and the select
                rendered empty. it is not switchable either: turning a component
                into a text field would throw its sub fields away */}
            {switchable ? (
              <Field label={__('Type', 'schemapress')}>
                {(id) => (
                  <Select
                    id={id}
                    // a dropdown pointed at a ready-made list cannot be
                    // something else: changing the type clears the config, and
                    // the list it names is the config
                    disabled={preset}
                    value={field.type}
                    options={fieldTypes.map((candidate) => ({
                      value: candidate.type,
                      label: candidate.label,
                    }))}
                    onChange={changeType}
                  />
                )}
              </Field>
            ) : null}
          </div>

          {/* only what the data IS lives here. help text, placeholder, whether
              it is required and when it is shown are all things the FORM does
              with the field, and they are set on the Form tab */}
          <FieldConfig field={field} onChange={(config) => update({ config })} />

          {nests ? (
            <div className="rounded-md border border-border bg-muted/30 p-3">
              <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                {__('Sub fields', 'schemapress')}
              </p>
              <FieldsEditor
                nested
                editing={editing}
                fields={field.fields || []}
                fieldTypes={fieldTypes}
                onChange={(fields) => update({ fields })}
              />
            </div>
          ) : null}
        </div>
      ) : null}

      {open && stepped ? (
        <RepeaterDialog
          field={field}
          siblingKeys={siblingKeys}
          fieldTypes={fieldTypes}
          editing={editing}
          onClose={() => setOpen(false)}
          onSave={(next) => {
            onChange(next)
            setOpen(false)
          }}
        />
      ) : null}
    </div>
  )
}

/**
 * Setting up a repeater, one decision at a time.
 *
 * A repeater is two questions that got asked at once: what IS this list, and
 * what is one row of it made of. Inline they arrived together — a name, three
 * numeric bounds, and a whole nested field editor in the same panel — and the
 * second question is the bigger one by far.
 *
 * So: name it, then build a row. The step you are on is the only thing on
 * screen, and nothing is written until the end, so backing out of a half-built
 * repeater leaves the schema as it was.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
function RepeaterDialog({ field, siblingKeys, fieldTypes, editing, onClose, onSave }) {
  const [draft, setDraft] = useState(field)
  const [step, setStep] = useState(0)

  // the key tracks the label only while it is still the placeholder a new field
  // was created with, exactly as it does on an ordinary field card
  const [derived, setDerived] = useState(() => /^field(_\d+)?$/.test(field.key))

  const rows = draft.fields || []

  /**
   * Merges a change into the local copy.
   *
   * @param {Object} changes
   * @return {void}
   */
  const update = (changes) =>
    setDraft((current) => ({
      ...current,
      ...changes,
      config: { ...current.config, ...changes.config },
    }))

  // named after what you DO on each step. the pair used to be "The list" and
  // "One row", which described the repeater's structure rather than the task,
  // and left you working out which half you were on
  const steps = [
    {
      label: __('Repeater', 'schemapress'),
      hint: __('What the list is called, and how many rows it may hold.', 'schemapress'),
      icon: Rows3,
    },
    {
      label: __('Schema', 'schemapress'),
      hint: __('The fields that repeat — one set of them per row.', 'schemapress'),
      icon: ListChecks,
    },
  ]

  return (
    <Dialog
      open
      size="lg"
      onOpenChange={(next) => !next && onClose()}
      title={draft.label || __('Repeater', 'schemapress')}
      description={steps[step].hint}
      badge={<Badge variant="outline">{__('Repeater', 'schemapress')}</Badge>}
      footer={
        <>
          <span className="mr-auto text-[12px] text-muted-foreground">
            {sprintf(
              /* translators: 1: current step, 2: total steps */
              __('Step %1$d of %2$d', 'schemapress'),
              step + 1,
              steps.length,
            )}
          </span>

          {step > 0 ? (
            <Button variant="outline" onClick={() => setStep(step - 1)}>
              {__('Back', 'schemapress')}
            </Button>
          ) : (
            <Button variant="outline" onClick={onClose}>
              {__('Cancel', 'schemapress')}
            </Button>
          )}

          {step < steps.length - 1 ? (
            <Button disabled={draft.label.trim() === ''} onClick={() => setStep(step + 1)}>
              {__('Next', 'schemapress')}
            </Button>
          ) : (
            <Button onClick={() => onSave(draft)}>{__('Done', 'schemapress')}</Button>
          )}
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Steps steps={steps} current={step} onGo={setStep} />

        {step === 0 ? (
          <div className="flex flex-col gap-4">
            <div className="grid gap-3 sm:grid-cols-2">
              <Field
                label={__('Label', 'schemapress')}
                help={__('What the list is called on the form.', 'schemapress')}
              >
                {(id) => (
                  <Input
                    id={id}
                    value={draft.label}
                    onChange={(event) =>
                      update(
                        derived
                          ? {
                              label: event.target.value,
                              key: uniqueKey(toKey(event.target.value), siblingKeys),
                            }
                          : { label: event.target.value },
                      )
                    }
                  />
                )}
              </Field>

              <Field label={__('Key', 'schemapress')}>
                {(id) => (
                  <Input
                    id={id}
                    className="font-mono text-[12px]"
                    value={draft.key}
                    onChange={(event) => {
                      setDerived(false)
                      update({ key: uniqueKey(toKey(event.target.value), siblingKeys) })
                    }}
                  />
                )}
              </Field>
            </div>

            <FieldConfig field={draft} onChange={(config) => update({ config })} />
          </div>
        ) : (
          <div className="flex flex-col gap-2">
            <p className="text-[12px] text-muted-foreground">
              {sprintf(
                /* translators: %s: the repeater's label */
                __(
                  'Add the fields for a single row. Whoever fills in %s gets one set of these per row, and can add as many rows as they need.',
                  'schemapress',
                ),
                (draft.label || '').toLowerCase(),
              )}
            </p>

            <FieldsEditor
              nested
              editing={editing}
              fields={rows}
              fieldTypes={fieldTypes}
              onChange={(next) => update({ fields: next })}
            />
          </div>
        )}
      </div>
    </Dialog>
  )
}

/**
 * The step indicator, and a way back to a step you have already been through.
 *
 * @param {Object} props
 * @return {JSX.Element} The indicator.
 */
function Steps({ steps, current, onGo }) {
  return (
    <div className="flex items-start justify-center py-1">
      {steps.map((step, index) => {
        const done = index < current
        const active = index === current
        const Icon = step.icon

        return (
          <Fragment key={step.label}>
            <button
              type="button"
              // forward only by the footer button, which is what validates.
              // going back to something you have already filled in needs no
              // permission
              disabled={index > current}
              onClick={() => onGo(index)}
              className="flex w-24 shrink-0 flex-col items-center gap-1.5"
            >
              <span
                className={cn(
                  'flex size-10 items-center justify-center rounded-full border-2 bg-background transition-colors',
                  done || active
                    ? 'border-primary text-primary'
                    : 'border-border text-muted-foreground/60',
                )}
              >
                {Icon ? <Icon className="size-4" /> : <span>{index + 1}</span>}
              </span>

              <span
                className={cn(
                  'text-center text-[12px] leading-tight transition-colors',
                  active ? 'font-medium text-foreground' : 'text-muted-foreground',
                )}
              >
                {step.label}
              </span>
            </button>

            {/* the rule joins the CIRCLES, so it is offset to their middle
                rather than centred on the column — the labels underneath make
                the two different heights */}
            {/* a fixed connector, not a stretching one: two steps spread over
                a wide dialog read as two unrelated things at opposite ends */}
            {index < steps.length - 1 ? (
              <span
                aria-hidden="true"
                className={cn(
                  '-mx-3 mt-5 h-0.5 w-12 transition-colors',
                  done ? 'bg-primary' : 'bg-border',
                )}
              />
            ) : null}
          </Fragment>
        )
      })}
    </div>
  )
}
