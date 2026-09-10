/**
 * Type-specific settings for a field definition.
 *
 * Only the keys the server whitelists for each type are offered — anything
 * else is discarded by SchemaModel::normalizeConfig, which would read as the
 * UI silently losing the setting.
 */

import { __, sprintf } from '@wordpress/i18n'
import { Plus, Trash2 } from 'lucide-react'
import { removeAt, replaceAt } from '../utils'
import { datasets } from '../settings'
import { Button, Input, Field, Switch, Heading, Select } from '../../ui'

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
 * Renders the settings panel for a field's type.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The settings, or null for types with none.
 */
export function FieldConfig({ field, onChange, onField, nested = false }) {
  const config = field.config || {}

  /**
   * Merges a partial change into the config bag.
   *
   * @param {Object} patch
   * @return {void}
   */
  const update = (patch) => onChange({ ...config, ...patch })

  const panel = TypeSettings({ field, config, update })

  // uniqueness is a question about the whole collection — "does another entry
  // already hold this" — and the index it is answered against holds one value
  // per field per entry. inside a repeater row there is no such value, so the
  // rule could not be enforced and is not offered
  const unique = !nested && Boolean(onField) && UNIQUE_TYPES.includes(field.type)

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
function TypeSettings({ field, config, update }) {
  switch (field.type) {
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
