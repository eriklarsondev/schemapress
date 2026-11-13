/**
 * Type-specific settings for a field definition.
 *
 * Only the keys the server whitelists for each type are offered — anything
 * else is discarded by SchemaModel::normalizeConfig, which would read as the
 * UI silently losing the setting.
 */

import { useEffect, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Plus, Trash2 } from 'lucide-react'
import { removeAt, replaceAt } from '../utils'
import { datasets } from '../settings'
import { api } from '../api'
import { RelationDiagram, RelationShapes } from './RelationDiagram'
import { Alert, Button, Input, Field, Switch, Heading, Select } from '../../ui'

/**
 * The types a uniqueness rule can be offered for.
 *
 * Narrower than the set the index can reach, and narrower on purpose. A toggle
 * has two values, so a unique one is a collection of at most two entries; a
 * dropdown exists to be chosen more than once. Neither is a rule anybody means
 * to write, and offering it invites a collection that cannot take a second row.
 *
 * The server enforces whatever the definition says, so this list is about what
 * is worth asking, not about what is possible.
 */
const UNIQUE_TYPES = [
  'text',
  'textarea',
  'email',
  'url',
  'phone',
  'number',
  'date',
  'datetime',
  'time',
]

/**
 * Whether a uniqueness rule is worth offering as a switch of its own.
 *
 * Not for a relation, and not because it does not apply — it is the whole of
 * what makes one a one-to-one. It is set by picking a SHAPE there instead, so
 * that the thing being chosen is the relationship rather than two settings that
 * have to be read together to work out which one it is. A second switch saying
 * the same thing in other words is how the two come to disagree on screen.
 *
 * @param {Object} field
 * @return {boolean} True when the rule gets a switch.
 */
function allowsUnique(field) {
  return field.type !== 'relation' && UNIQUE_TYPES.includes(field.type)
}

/**
 * Renders the settings panel for a field's type.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The settings, or null for types with none.
 */
export function FieldConfig({ field, onChange, onField, nested = false, collection = '' }) {
  const config = field.config || {}

  /**
   * Merges a partial change into the config bag.
   *
   * @param {Object} patch
   * @return {void}
   */
  const update = (patch) => onChange({ ...config, ...patch })

  const panel = TypeSettings({ field, config, update, onField, collection, nested })

  // uniqueness is a question about the whole collection — "does another entry
  // already hold this" — and the index it is answered against holds one value
  // per field per entry. inside a repeater row there is no such value, so the
  // rule could not be enforced and is not offered
  const unique = !nested && Boolean(onField) && allowsUnique(field)

  if (!panel && !unique) {
    return null
  }

  return (
    <div className="flex flex-col gap-4">
      {panel}

      {unique ? (
        <Switch
          label={__('Must be unique', 'schemapress')}
          help={__('No two entries in this collection may hold the same value.', 'schemapress')}
          checked={Boolean(field.unique)}
          onChange={(next) => onField({ unique: next })}
        />
      ) : null}
    </div>
  )
}

/**
 * The part of the panel that depends on the field's type.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The settings, or null for types with none.
 */
function TypeSettings({ field, config, update, onField, collection, nested }) {
  switch (field.type) {
    case 'relation':
      return (
        <RelationSettings
          field={field}
          config={config}
          onChange={update}
          onField={onField}
          collection={collection}
          nested={nested}
        />
      )

    case 'text':
    case 'textarea':
    case 'email':
    case 'url':
    case 'phone':
      // no placeholder here: it is text the form shows, not a fact about the
      // data, so it is set on the Form tab with the other presentation
      return (
        <Field label={__('Max length', 'schemapress')} help={__('0 for none', 'schemapress')}>
          {(id) => (
            <Input
              id={id}
              type="number"
              min="0"
              className="sm:max-w-40"
              value={config.maxlength || 0}
              onChange={(event) => update({ maxlength: Number(event.target.value) || 0 })}
            />
          )}
        </Field>
      )

    case 'number':
      return (
        <div className="grid gap-3 sm:grid-cols-3">
          {['min', 'max', 'step'].map((bound) => (
            <Field key={bound} label={bound}>
              {(id) => (
                <Input
                  id={id}
                  type="number"
                  value={config[bound] ?? ''}
                  onChange={(event) =>
                    update({
                      [bound]: event.target.value === '' ? '' : Number(event.target.value),
                    })
                  }
                />
              )}
            </Field>
          ))}
        </div>
      )

    case 'select':
      return <SelectOptions config={config} onChange={update} />

    case 'gallery':
      return (
        <Field
          label={__('Most images', 'schemapress')}
          help={__(
            '0 for unlimited. There is no minimum — “required” already means at least one.',
            'schemapress'
          )}
        >
          {(id) => (
            <Input
              id={id}
              type="number"
              min="0"
              className="sm:max-w-[12rem]"
              value={config.max || 0}
              onChange={(event) => update({ max: Number(event.target.value) || 0 })}
            />
          )}
        </Field>
      )

    case 'json':
      return (
        <Field
          label={__('Editor height', 'schemapress')}
          help={__(
            'In rows. A payload is usually either three lines or three hundred.',
            'schemapress'
          )}
        >
          {(id) => (
            <Input
              id={id}
              type="number"
              min="3"
              className="sm:max-w-[12rem]"
              value={config.rows || 8}
              onChange={(event) => update({ rows: Number(event.target.value) || 8 })}
            />
          )}
        </Field>
      )

    case 'repeater':
      return (
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={__('Min rows', 'schemapress')}>
            {(id) => (
              <Input
                id={id}
                type="number"
                min="0"
                value={config.min || 0}
                onChange={(event) => update({ min: Number(event.target.value) || 0 })}
              />
            )}
          </Field>
          <Field label={__('Max rows', 'schemapress')} help={__('0 for unlimited', 'schemapress')}>
            {(id) => (
              <Input
                id={id}
                type="number"
                min="0"
                value={config.max || 0}
                onChange={(event) => update({ max: Number(event.target.value) || 0 })}
              />
            )}
          </Field>
          <Field
            label={__('Row label field', 'schemapress')}
            help={__('Subfield key shown on collapsed rows', 'schemapress')}
          >
            {(id) => (
              <Input
                id={id}
                className="font-mono text-[12px]"
                value={config.row_label || ''}
                onChange={(event) => update({ row_label: event.target.value })}
              />
            )}
          </Field>
        </div>
      )

    default:
      return null
  }
}

/**
 * The collections a relation can point at, and what already points where.
 *
 * Fetched when a relation field is opened rather than sent with the page, for
 * the reason the component list is: collections are made on another screen, and
 * a list that booted with the page would offer one that has since gone.
 *
 * @return {Array|null} The catalog, or null while it is being read.
 */
function useCollections() {
  const [collections, setCollections] = useState(null)

  useEffect(() => {
    let live = true

    api
      .relations()
      .then((result) => live && setCollections(result.collections || []))
      .catch(() => live && setCollections([]))

    return () => {
      live = false
    }
  }, [])

  return collections
}
/**
 * Settings for a relation: which collection, which way round, and how many.
 *
 * One panel for both directions, because there is one field type: whether this
 * end stores the references or reads back whatever points at it is a shape you
 * pick here, not a different kind of field you had to choose before you knew
 * which collection you meant.
 *
 * @param {Object} props
 * @return {JSX.Element} The settings.
 */
function RelationSettings({ field, config, onChange, onField, collection, nested }) {
  const collections = useCollections()

  const back = config.direction === 'inverse'
  const many = Boolean(config.multiple)

  const target = (collections || []).find((one) => one.key === config.collection)
  const here = (collections || []).find((one) => one.key === collection)

  // the relations in the collection at the other end, for a field reading one
  // of them back. A collection may point here twice — as an author and as an
  // editor — so which one is asked rather than guessed
  const relations = target ? target.relations : []
  const through = relations.find((one) => one.key === config.field)

  // which of those actually point back HERE. the others are still offered — a
  // collection being edited may not have been saved with this field yet — but
  // the ones that line up are said to
  const mirrors = (relation) => collection !== '' && relation.collection === collection

  // whether the far collection has a field reading this relation back. The one
  // part of the kind this field does not decide, which is why the diagram draws
  // it rather than offering it as a card that would write a field into a
  // collection nobody is looking at
  const inverse = Boolean(
    target?.inverses?.some((one) => one.collection === collection && one.field === field.key)
  )

  // uniqueness is a fact about the data, so it lives beside `required` rather
  // than in the config bag — which is why picking a shape sets the two through
  // different doors. Without onField there is nobody to set it on, and inside a
  // repeater row there is no single value to be unique about
  const exclusive = Boolean(onField) && !nested

  // reading back is answered about THE ENTRY, so inside a repeater row every
  // row would list the same thing, and a component has no collection for the
  // other end to point at. The server applies the same rule — see
  // SchemaModel::normalizeConfig
  const readable = !nested && collection !== ''

  /**
   * Applies one of the shapes.
   *
   * A shape is a change to BOTH halves of the field — `unique` sits beside
   * `required` and the rest lives in the config bag — and it has to be written
   * in ONE call. Two calls each spread the same field object, which is the one
   * the component was rendered with, so the second lands on the parent's
   * unchanged list and silently discards the first: picking "Holds several"
   * set unique and never set multiple.
   *
   * @param {Object} shape direction, multiple and unique
   * @return {void}
   */
  const pick = (shape) => {
    const next = { ...config, direction: shape.direction, multiple: shape.multiple }

    if (onField) {
      onField({ unique: shape.unique, config: next })

      return
    }

    // inside a repeater row there is no field-level door and nothing to be
    // unique about — the config is the whole of the change
    onChange(next)
  }

  return (
    <div className="flex flex-col gap-3">
      <Field
        label={back ? __('Entries from', 'schemapress') : __('Points at', 'schemapress')}
        help={
          back
            ? __('Which collection the entries pointing at this one live in.', 'schemapress')
            : __('Which collection this field holds entries of.', 'schemapress')
        }
      >
        {(id) => (
          <Select
            id={id}
            value={config.collection || ''}
            options={[
              { value: '', label: __('Choose a collection…', 'schemapress') },
              ...(collections || []).map((one) => ({ value: one.key, label: one.label })),
            ]}
            // a field reading back names a relation IN that collection, so the
            // choice cannot survive the collection changing underneath it
            onChange={(next) => onChange({ collection: next, field: '' })}
          />
        )}
      </Field>

      {collections !== null && collections.length === 0 ? (
        <Alert variant="warning">
          {__(
            'There is only this collection so far. Make another one, and a relation has somewhere to point.',
            'schemapress'
          )}
        </Alert>
      ) : null}

      <Field
        label={__('What this field is', 'schemapress')}
        help={__('What one entry of this collection holds, or reads back.', 'schemapress')}
      >
        <RelationShapes field={field} exclusive={exclusive} inverse={readable} onPick={pick} />
      </Field>

      {back && config.collection ? (
        <Field
          label={__('Pointing through', 'schemapress')}
          help={__('Which of that collection’s relations this is the other end of.', 'schemapress')}
        >
          {(id) => (
            <Select
              id={id}
              value={config.field || ''}
              options={[
                { value: '', label: __('Choose a relation…', 'schemapress') },
                ...relations.map((one) => ({
                  value: one.key,
                  label: mirrors(one)
                    ? sprintf(
                        /* translators: %s: a relation field's name */
                        __('%s — points back here', 'schemapress'),
                        one.label
                      )
                    : one.label,
                })),
              ]}
              onChange={(next) => onChange({ field: next })}
            />
          )}
        </Field>
      ) : null}

      {back && config.collection && relations.length === 0 ? (
        <Alert variant="warning">
          {__(
            'That collection has nothing pointing here yet. Add a relation there that holds entries of this collection, and this field becomes the other end of it.',
            'schemapress'
          )}
        </Alert>
      ) : null}

      {back && through && !mirrors(through) ? (
        <Alert variant="warning">
          {__(
            'That relation does not point at this collection, so nothing will ever be listed here.',
            'schemapress'
          )}
        </Alert>
      ) : null}

      {/* drawn once the pair is actually describable: facing back that means a
          relation has been named, since how many sit at each end is ITS
          answer rather than this field's */}
      {!back || through ? (
        <RelationDiagram
          field={field}
          from={here?.label || ''}
          to={target}
          inverse={inverse}
          through={through}
        />
      ) : null}

      {/* only where there is an inverse to be missing: a relation nobody needs
          to walk backwards is a perfectly good one-way relation, and a nudge
          toward a field they do not want would be noise on every schema */}
      {!back && target && !inverse ? (
        <p className="text-[12px] text-muted-foreground">
          {sprintf(
            /* translators: 1: the target collection's name, 2: this field's name */
            __(
              'To read it the other way too, add a Relation field to %1$s set to Reads back, pointing through %2$s.',
              'schemapress'
            ),
            target.label,
            field.label || field.key
          )}
        </p>
      ) : null}

      {!back && many ? (
        <Field
          label={__('Most entries', 'schemapress')}
          help={__(
            '0 for unlimited. There is no minimum — “required” already means at least one.',
            'schemapress'
          )}
        >
          {(id) => (
            <Input
              id={id}
              type="number"
              min="0"
              className="sm:max-w-[12rem]"
              value={config.max || 0}
              onChange={(event) => onChange({ max: Number(event.target.value) || 0 })}
            />
          )}
        </Field>
      ) : null}
    </div>
  )
}

/**
 * Editor for a select field's option list.
 *
 * @param {Object} props
 * @return {JSX.Element} The option editor.
 */
function SelectOptions({ config, onChange }) {
  const options = config.options || []
  const dataset = datasets.find((set) => set.slug === config.source)

  return (
    <div className="flex flex-col gap-3">
      <Switch
        label={__('Allow multiple selections', 'schemapress')}
        checked={Boolean(config.multiple)}
        onChange={(multiple) => onChange({ multiple })}
      />

      {/* a ready-made list is stored by name, not copied in — so a correction
          to it reaches every field that named it, and two collections cannot
          end up with "USA" in one and "United States" in the other */}
      <Field
        label={__('Choices from', 'schemapress')}
        help={__('A ready-made list, or your own.', 'schemapress')}
      >
        {(id) => (
          <Select
            id={id}
            value={config.source || ''}
            options={[
              { value: '', label: __('My own list', 'schemapress') },
              ...datasets.map((set) => ({ value: set.slug, label: set.label })),
            ]}
            onChange={(source) => onChange({ source })}
          />
        )}
      </Field>

      {dataset ? (
        <div className="rounded-md border border-border bg-muted/30 p-3">
          <p className="text-[12px] text-muted-foreground">
            {sprintf(
              /* translators: 1: dataset name, 2: number of choices */
              __('%1$s — %2$d choices, kept up to date for you.', 'schemapress'),
              dataset.label,
              dataset.options.length
            )}
          </p>

          <p className="mt-1 truncate text-[12px] text-muted-foreground/70">
            {dataset.options
              .slice(0, 6)
              .map((option) => option.label)
              .join(', ')}
            {dataset.options.length > 6 ? '…' : ''}
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-2">
          <Heading>{__('Options', 'schemapress')}</Heading>

          {/* label before value: the label is the thing you are deciding, and the
            value is what gets stored under it — a consequence of that decision */}
          {options.map((option, index) => (
            <div key={index} className="flex items-end gap-2">
              <Field label={__('Label', 'schemapress')} className="flex-1">
                {(id) => (
                  <Input
                    id={id}
                    value={option.label}
                    onChange={(event) =>
                      onChange({
                        options: replaceAt(options, index, {
                          ...option,
                          label: event.target.value,
                        }),
                      })
                    }
                  />
                )}
              </Field>
              <Field label={__('Value', 'schemapress')} className="flex-1">
                {(id) => (
                  <Input
                    id={id}
                    className="font-mono text-[12px]"
                    value={option.value}
                    onChange={(event) =>
                      onChange({
                        options: replaceAt(options, index, {
                          ...option,
                          value: event.target.value,
                        }),
                      })
                    }
                  />
                )}
              </Field>
              <Button
                size="icon"
                variant="destructive-ghost"
                aria-label={__('Remove option', 'schemapress')}
                onClick={() => onChange({ options: removeAt(options, index) })}
              >
                <Trash2 />
              </Button>
            </div>
          ))}

          <div>
            <Button
              size="sm"
              variant="outline"
              onClick={() => onChange({ options: [...options, { value: '', label: '' }] })}
            >
              <Plus />
              {__('Add option', 'schemapress')}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
