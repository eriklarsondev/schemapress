/**
 * Controls for the scalar field types. Each receives the normalized field
 * definition plus its current value and reports changes upward; none of them
 * hold state of their own.
 */

import { Field, Input, Textarea, Select, Switch } from '../../ui'

/**
 * Single-line text input.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function TextField({ field, value, onChange }) {
  return (
    <Field label={field.label} help={field.help} required={field.required}>
      {(id) => (
        <Input
          id={id}
          placeholder={field.config?.placeholder || ''}
          maxLength={field.config?.maxlength || undefined}
          value={value ?? ''}
          onChange={(event) => onChange(event.target.value)}
        />
      )}
    </Field>
  )
}

/**
 * Email, URL and phone.
 *
 * All three are a single line of text, so they share one control and differ
 * only in the input type — which is what gets the right keyboard on a phone,
 * the browser's own validation, and a tappable link on the way back out.
 *
 * @param {string} type   the HTML input type
 * @param {string} hint   the placeholder shown when the field configures none
 * @param {string} mode   the inputMode, for mobile keyboards
 * @return {Function} The control.
 */
function typed(type, hint, mode) {
  return function TypedField({ field, value, onChange }) {
    return (
      <Field label={field.label} help={field.help} required={field.required}>
        {(id) => (
          <Input
            id={id}
            type={type}
            inputMode={mode}
            placeholder={field.config?.placeholder || hint}
            maxLength={field.config?.maxlength || undefined}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
          />
        )}
      </Field>
    )
  }
}

/** An email address. */
export const EmailField = typed('email', 'name@example.com', 'email')

/** A web address. */
export const UrlField = typed('url', 'https://example.com', 'url')

/** A telephone number, in whatever shape the country writes them. */
export const PhoneField = typed('tel', '+1 555 0100', 'tel')

/**
 * Multi-line plain text input.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function TextareaField({ field, value, onChange }) {
  return (
    <Field label={field.label} help={field.help} required={field.required}>
      {(id) => (
        <Textarea
          id={id}
          rows={4}
          placeholder={field.config?.placeholder || ''}
          value={value ?? ''}
          onChange={(event) => onChange(event.target.value)}
        />
      )}
    </Field>
  )
}

/**
 * Numeric input. Empty is preserved as null rather than coerced to zero, so
 * "unset" and "zero" stay distinguishable.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function NumberField({ field, value, onChange }) {
  return (
    <Field label={field.label} help={field.help} required={field.required}>
      {(id) => (
        <Input
          id={id}
          type="number"
          min={field.config?.min}
          max={field.config?.max}
          step={field.config?.step}
          value={value ?? ''}
          onChange={(event) =>
            onChange(event.target.value === '' ? null : Number(event.target.value))
          }
        />
      )}
    </Field>
  )
}

/**
 * Boolean switch.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function ToggleField({ field, value, onChange }) {
  return (
    <Switch label={field.label} help={field.help} checked={Boolean(value)} onChange={onChange} />
  )
}

/**
 * Single or multiple choice from the field's configured options.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function SelectField({ field, value, onChange }) {
  const options = field.config?.options || []
  const multiple = Boolean(field.config?.multiple)

  if (!multiple) {
    return (
      <Field label={field.label} help={field.help} required={field.required}>
        {(id) => (
          <Select
            id={id}
            value={value ?? ''}
            placeholder="—"
            options={[{ value: '', label: '—' }, ...options]}
            onChange={onChange}
          />
        )}
      </Field>
    )
  }

  const selected = Array.isArray(value) ? value : []

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      <div className="flex flex-col gap-1.5 rounded-md border border-input p-2.5">
        {options.length === 0 ? (
          <p className="text-[12px] text-muted-foreground">No options defined.</p>
        ) : null}

        {options.map((option) => (
          <label key={option.value} className="flex cursor-pointer items-center gap-2">
            <input
              type="checkbox"
              className="size-3.5"
              checked={selected.includes(option.value)}
              onChange={(event) =>
                onChange(
                  event.target.checked
                    ? [...selected, option.value]
                    : selected.filter((entry) => entry !== option.value),
                )
              }
            />
            <span className="text-[13px]">{option.label}</span>
          </label>
        ))}
      </div>
    </Field>
  )
}
