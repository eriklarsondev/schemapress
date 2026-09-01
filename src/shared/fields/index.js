/**
 * Field control registry and dispatcher.
 *
 * The registry is keyed by the same type slugs the PHP FieldTypes registry
 * uses, so adding a type is a matter of registering it on both sides.
 */

import { __ } from '@wordpress/i18n'
import { Alert } from '../../ui'
import {
  TextField,
  TextareaField,
  NumberField,
  ToggleField,
  SelectField
} from './BasicControls'
import { ImageField, FileField } from './MediaControl'
import { LinkField } from './LinkControl'
import { PostField } from './PostControl'
import { RichTextField } from './RichTextControl'
import { RepeaterField, GroupField } from './RepeaterControl'

const CONTROLS = {
  text: TextField,
  textarea: TextareaField,
  wysiwyg: RichTextField,
  number: NumberField,
  toggle: ToggleField,
  select: SelectField,
  image: ImageField,
  file: FileField,
  link: LinkField,
  post: PostField,
  group: GroupField,
  repeater: RepeaterField
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
    <div className="flex flex-col gap-4">
      {fields.map((field) => (
        <FieldControl
          key={field.key}
          field={field}
          value={values?.[field.key]}
          context={context}
          onChange={(next) => onChange(field.key, next)}
        />
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
