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

import { useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import {
  ChevronUp,
  ChevronDown,
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
  Group as GroupIcon,
  Rows3,
  CircleHelp,
} from 'lucide-react'
import { move, removeAt, replaceAt, toKey, uniqueKey } from '../utils'
import { conditionTargets } from '../conditions'
import { Button, Input, Field, Select, Switch, Badge, Dialog, cn } from '../../ui'
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
  group: GroupIcon,
  repeater: Rows3,
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
export function FieldsEditor({ fields, fieldTypes, onChange, nested = false }) {
  const [picking, setPicking] = useState(false)

  // a field created from the picker opens straight away: you chose a type, and
  // naming it is the very next thing you were going to do
  const [opened, setOpened] = useState(null)

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
          total={fields.length}
          siblings={fields}
          siblingKeys={fields.filter((_, i) => i !== index).map((f) => f.key)}
          fieldTypes={fieldTypes}
          startOpen={opened === field.key}
          onOpened={() => setOpened(null)}
          onChange={(next) => onChange(replaceAt(fields, index, next))}
          onMove={(to) => onChange(move(fields, index, to))}
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
          fieldTypes={fieldTypes}
          onPick={addField}
          onClose={() => setPicking(false)}
        />
      ) : null}
    </div>
  )
}

/**
 * When a field appears on the entry form.
 *
 * Off by default and stated in one sentence when on, because the setting reads
 * as a sentence — "show this field when Contactable is filled" — and a row of
 * three unlabelled selects does not.
 *
 * @param {Object} props
 * @return {JSX.Element} The settings.
 */
function ConditionSettings({ field, siblings, onChange }) {
  const condition = field.config?.condition || { field: '', operator: 'filled', value: '' }
  const targets = conditionTargets(siblings, field.key)
  const on = Boolean(condition.field)

  const needsValue = ['equals', 'not_equals'].includes(condition.operator)

  if (targets.length === 0) {
    return null
  }

  return (
    <div className="flex flex-col gap-3 border-t border-border pt-3">
      <Switch
        label={__('Only show this field sometimes', 'schemapress')}
        help={__(
          'A hidden field keeps whatever was already in it, and still delivers it.',
          'schemapress',
        )}
        checked={on}
        onChange={(next) =>
          onChange(
            next
              ? { field: targets[0].key, operator: 'filled', value: '' }
              : { field: '', operator: 'filled', value: '' },
          )
        }
      />

      {on ? (
        <div className="flex flex-wrap items-end gap-2">
          <span className="pb-2 text-[13px] text-muted-foreground">
            {__('Show when', 'schemapress')}
          </span>

          <Field label={__('Field', 'schemapress')} className="min-w-40 flex-1">
            {(id) => (
              <Select
                id={id}
                value={condition.field}
                options={targets.map((target) => ({
                  value: target.key,
                  label: target.label || target.key,
                }))}
                onChange={(next) => onChange({ ...condition, field: next })}
              />
            )}
          </Field>

          <Field label={__('Is', 'schemapress')} className="min-w-36">
            {(id) => (
              <Select
                id={id}
                value={condition.operator}
                options={[
                  { value: 'filled', label: __('filled in', 'schemapress') },
                  { value: 'empty', label: __('empty', 'schemapress') },
                  { value: 'equals', label: __('exactly', 'schemapress') },
                  { value: 'not_equals', label: __('anything but', 'schemapress') },
                ]}
                onChange={(next) => onChange({ ...condition, operator: next })}
              />
            )}
          </Field>

          {needsValue ? (
            <Field label={__('Value', 'schemapress')} className="min-w-36 flex-1">
              {(id) => (
                <Input
                  id={id}
                  value={condition.value || ''}
                  onChange={(event) => onChange({ ...condition, value: event.target.value })}
                />
              )}
            </Field>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

/**
 * The grid of field types shown when adding one.
 *
 * @param {Object} props
 * @return {JSX.Element} The picker.
 */
function FieldTypePicker({ fieldTypes, onPick, onClose }) {
  return (
    <Dialog
      open
      size="md"
      onOpenChange={(next) => !next && onClose()}
      title={__('Add a field', 'schemapress')}
      description={__('What kind of thing does this hold?', 'schemapress')}
    >
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
        {fieldTypes.map((type) => {
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
  total,
  siblings,
  siblingKeys,
  fieldTypes,
  startOpen,
  onOpened,
  onChange,
  onMove,
  onRemove,
}) {
  const [open, setOpen] = useState(Boolean(startOpen))

  // a key is the address of every value already stored under it, so renaming
  // one orphans that content. the key tracks the label only while it is still
  // the untouched placeholder a new field is created with; after that it stays
  // put unless it is edited deliberately.
  const [derived, setDerived] = useState(() => /^field(_\d+)?$/.test(field.key))

  const type = fieldTypes.find((candidate) => candidate.type === field.type)
  const nests = Boolean(type?.children)
  const Icon = iconFor(field.type)
  const summary = summarize(field)

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
      className={cn(
        'overflow-hidden rounded-lg border bg-background transition-colors',
        open ? 'border-ring/40' : 'border-border hover:border-ring/30',
      )}
    >
      <div className="group flex items-center gap-3 p-3">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
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
            <span>{type?.label || field.type}</span>
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
            variant="ghost"
            aria-label={__('Move up', 'schemapress')}
            disabled={index === 0}
            onClick={() => onMove(index - 1)}
          >
            <ChevronUp />
          </Button>
          <Button
            size="icon-sm"
            variant="ghost"
            aria-label={__('Move down', 'schemapress')}
            disabled={index === total - 1}
            onClick={() => onMove(index + 1)}
          >
            <ChevronDown />
          </Button>
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
              open && 'rotate-90',
            )}
          />
        </span>
      </div>

      {open ? (
        <div className="flex flex-col gap-4 border-t border-border bg-muted/20 p-4">
          <div className="grid gap-3 sm:grid-cols-3">
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

            <Field
              label={__('Key', 'schemapress')}
              help={
                derived
                  ? __('Used in the delivered JSON', 'schemapress')
                  : __('Changing this orphans content already saved under it', 'schemapress')
              }
            >
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

            <Field label={__('Type', 'schemapress')}>
              {(id) => (
                <Select
                  id={id}
                  value={field.type}
                  options={fieldTypes.map((candidate) => ({
                    value: candidate.type,
                    label: candidate.label,
                  }))}
                  onChange={changeType}
                />
              )}
            </Field>
          </div>

          <div className="flex items-end gap-4">
            <Field label={__('Help text', 'schemapress')} className="flex-1">
              {(id) => (
                <Input
                  id={id}
                  value={field.help || ''}
                  placeholder={__('Shown under the control on the entry form', 'schemapress')}
                  onChange={(event) => update({ help: event.target.value })}
                />
              )}
            </Field>

            <div className="pb-2">
              <Switch
                label={__('Required', 'schemapress')}
                checked={Boolean(field.required)}
                onChange={(required) => update({ required })}
              />
            </div>
          </div>

          <FieldConfig field={field} onChange={(config) => update({ config })} />

          <ConditionSettings
            field={field}
            siblings={siblings}
            onChange={(condition) => update({ config: { ...field.config, condition } })}
          />

          {nests ? (
            <div className="rounded-lg border-l-2 border-ring/30 bg-background p-3 pl-4">
              <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                {__('Sub fields', 'schemapress')}
              </p>
              <FieldsEditor
                nested
                fields={field.fields || []}
                fieldTypes={fieldTypes}
                onChange={(fields) => update({ fields })}
              />
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
