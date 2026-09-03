/**
 * Field control registry and dispatcher.
 *
 * The registry is keyed by the same type slugs the PHP FieldTypes registry
 * uses, so adding a type is a matter of registering it on both sides.
 */

import { __ } from '@wordpress/i18n'
import { Alert } from '../../ui'
import { visibleFields } from '../conditions'
import { cellClass, gridClass } from '../layout'
import {
  TextField,
  TextareaField,
  EmailField,
  UrlField,
  PhoneField,
  NumberField,
  ToggleField,
  SelectField,
} from './BasicControls'
import { ImageField, FileField } from './MediaControl'
import { LinkField } from './LinkControl'
import { RichTextField } from './RichTextControl'
import { RepeaterField, GroupField } from './RepeaterControl'

const CONTROLS = {
  text: TextField,
  textarea: TextareaField,
  email: EmailField,
  url: UrlField,
  phone: PhoneField,
  wysiwyg: RichTextField,
  number: NumberField,
  toggle: ToggleField,
  select: SelectField,
  image: ImageField,
  file: FileField,
  link: LinkField,
  group: GroupField,
  repeater: RepeaterField,
}

/**
 * Renders the control for a single field.
 *
 * @param {Object} props
 * @return {JSX.Element} The control, or a notice for unknown types.
 */
export function FieldControl({ field, value, onChange, context }) {
  const Control = CONTROLS[field.type]

  if (!Control) {
    return (
      <Alert variant="warning">
        {__('Unsupported field type:', 'schemapress')}{' '}
        <code className="rounded bg-amber-100 px-1 py-0.5">{field.type}</code>
      </Alert>
    )
  }

  return <Control field={field} value={value} onChange={onChange} context={context} />
}

/**
 * Renders a list of fields against a value bag.
 *
 * @param {Object} props
 * @return {JSX.Element} The field list.
 */
export function FieldList({ fields = [], values = {}, onChange, context }) {
  if (fields.length === 0) {
    return (
      <p className="text-[13px] italic text-muted-foreground">
        {__('No fields defined.', 'schemapress')}
      </p>
    )
  }

  return (
    // the same twelve columns the top level uses, so a component keeps the
    // layout it was given when it is imported into a collection — and a
    // repeater row can be laid out at all
    <div className={gridClass()}>
      {/* a condition names a sibling, so inside a repeater row it is evaluated
          against that row's own values — which is what `values` already is */}
      {visibleFields(fields, values).map((field) => (
        <div key={field.key} className={cellClass(field)}>
          <FieldControl
            field={field}
            value={values?.[field.key]}
            context={context}
            onChange={(next) => onChange(field.key, next)}
          />
        </div>
      ))}
    </div>
  )
}

/**
 * Whether a type slug has a registered control.
 *
 * @param {string} type
 * @return {boolean} True when the type can be rendered.
 */
export function isSupported(type) {
  return Boolean(CONTROLS[type])
}
