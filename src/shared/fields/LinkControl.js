/**
 * Link field: url, label and target stored as one object so a template or a
 * front-end component can render a complete anchor without reaching for
 * sibling fields.
 */

import { __ } from '@wordpress/i18n'
import { Field, Input, Switch } from '../../ui'

/**
 * Composite link control.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function LinkField({ field, value, onChange }) {
  const link = value || { url: '', label: '', target: '' }

  /**
   * Merges a partial change into the link object.
   *
   * @param {Object} patch
   * @return {void}
   */
  const update = (patch) => onChange({ ...link, ...patch })

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      <div className="flex flex-col gap-3 rounded-md border border-input p-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={__('URL', 'schemapress')}>
            {(id) => (
              <Input
                id={id}
                type="url"
                placeholder="https://"
                value={link.url || ''}
                onChange={(event) => update({ url: event.target.value })}
              />
            )}
          </Field>
          <Field label={__('Label', 'schemapress')}>
            {(id) => (
              <Input
                id={id}
                value={link.label || ''}
                onChange={(event) => update({ label: event.target.value })}
              />
            )}
          </Field>
        </div>

        <Switch
          label={__('Open in a new tab', 'schemapress')}
          checked={link.target === '_blank'}
          onChange={(next) => update({ target: next ? '_blank' : '' })}
        />
      </div>
    </Field>
  )
}
