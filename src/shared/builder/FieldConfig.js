/**
 * Type-specific settings for a field definition.
 *
 * Only the keys the server whitelists for each type are offered — anything
 * else is discarded by SchemaModel::normalizeConfig, which would read as the
 * UI silently losing the setting.
 */

import { __ } from '@wordpress/i18n'
import { Plus, Trash2 } from 'lucide-react'
import { removeAt, replaceAt } from '../utils'
import { Button, Input, Field, Switch, Heading, Select } from '../../ui'

/**
 * Renders the settings panel for a field's type.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The settings, or null for types with none.
 */
export function FieldConfig({ field, onChange }) {
  const config = field.config || {}

  /**
   * Merges a partial change into the config bag.
   *
   * @param {Object} patch
   * @return {void}
   */
  const update = (patch) => onChange({ ...config, ...patch })

  switch (field.type) {
    case 'text':
    case 'textarea':
    case 'email':
    case 'url':
    case 'phone':
      return (
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={__('Placeholder', 'schemapress')}>
            {(id) => (
              <Input
                id={id}
                value={config.placeholder || ''}
                onChange={(event) => update({ placeholder: event.target.value })}
              />
            )}
          </Field>
          <Field label={__('Max length', 'schemapress')} help={__('0 for none', 'schemapress')}>
            {(id) => (
              <Input
                id={id}
                type="number"
                min="0"
                value={config.maxlength || 0}
                onChange={(event) => update({ maxlength: Number(event.target.value) || 0 })}
              />
            )}
          </Field>
        </div>
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

  return (
    <div className="flex flex-col gap-3">
      <Switch
        label={__('Allow multiple selections', 'schemapress')}
        checked={Boolean(config.multiple)}
        onChange={(multiple) => onChange({ multiple })}
      />

      <div className="flex flex-col gap-2">
        <Heading>{__('Options', 'schemapress')}</Heading>

        {options.map((option, index) => (
          <div key={index} className="flex items-end gap-2">
            <Field label={__('Value', 'schemapress')} className="flex-1">
              {(id) => (
                <Input
                  id={id}
                  className="font-mono text-[12px]"
                  value={option.value}
                  onChange={(event) =>
                    onChange({
                      options: replaceAt(options, index, { ...option, value: event.target.value }),
                    })
                  }
                />
              )}
            </Field>
            <Field label={__('Label', 'schemapress')} className="flex-1">
              {(id) => (
                <Input
                  id={id}
                  value={option.label}
                  onChange={(event) =>
                    onChange({
                      options: replaceAt(options, index, { ...option, label: event.target.value }),
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
    </div>
  )
}
