/**
 * The field tree editor.
 *
 * FieldsEditor and FieldEditor are mutually recursive: group and repeater
 * fields nest another FieldsEditor, so a schema can describe arbitrarily deep
 * structures with one component pair.
 */

import { useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { ChevronUp, ChevronDown, Trash2, Plus, ChevronRight } from 'lucide-react'
import { move, removeAt, replaceAt, toKey, uniqueKey } from '../utils'
import { Button, Input, Field, Select, Switch, Badge, Heading, cn } from '../../ui'
import { FieldConfig } from './FieldConfig'

/**
 * An ordered list of field definitions.
 *
 * @param {Object} props
 * @return {JSX.Element} The list editor.
 */
export function FieldsEditor({ fields, fieldTypes, onChange }) {
  /**
   * Appends a text field with a unique key.
   *
   * @return {void}
   */
  const addField = () =>
    onChange([
      ...fields,
      {
        key: uniqueKey('field', fields.map((field) => field.key)),
        label: __('New Field', 'schemapress'),
        type: 'text',
        help: '',
        required: false,
        config: {}
      }
    ])

  return (
    <div className="flex flex-col gap-1.5">
      {fields.map((field, index) => (
        <FieldEditor
          key={index}
          field={field}
          index={index}
          total={fields.length}
          siblingKeys={fields.filter((_, i) => i !== index).map((f) => f.key)}
          fieldTypes={fieldTypes}
          onChange={(next) => onChange(replaceAt(fields, index, next))}
          onMove={(to) => onChange(move(fields, index, to))}
          onRemove={() => onChange(removeAt(fields, index))}
        />
      ))}

      {fields.length === 0 ? (
        <p className="rounded-md border border-dashed border-border px-3 py-4 text-center text-[12px] text-muted-foreground">
          {__('No fields yet.', 'schemapress')}
        </p>
      ) : null}

      <div>
        <Button size="sm" variant="outline" onClick={addField}>
          <Plus />
          {__('Add field', 'schemapress')}
        </Button>
      </div>
    </div>
  )
}

/**
 * One field definition, collapsible, with its type settings and any children.
 *
 * @param {Object} props
 * @return {JSX.Element} The field editor.
 */
function FieldEditor({
  field,
  index,
  total,
  siblingKeys,
  fieldTypes,
  onChange,
  onMove,
  onRemove
}) {
  const [open, setOpen] = useState(false)

  // a key is the address of every value already stored under it, so renaming
  // one orphans that content. the key tracks the label only while it is still
  // the untouched placeholder a new field is created with; after that it stays
  // put unless it is edited deliberately.
  const [derived, setDerived] = useState(() => /^field(_\d+)?$/.test(field.key))

  const type = fieldTypes.find((candidate) => candidate.type === field.type)
  const nests = Boolean(type?.children)

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
      fields: definition?.children ? field.fields || [] : undefined
    })
  }

  return (
    <div className="overflow-hidden rounded-md border border-border bg-background">
      <div className="flex items-center gap-1 px-2 py-1.5">
        <button
          type="button"
          aria-expanded={open}
          onClick={() => setOpen((state) => !state)}
          className="flex min-w-0 flex-1 items-center gap-2 text-left"
        >
          <ChevronRight
            className={cn(
              'size-3 shrink-0 text-muted-foreground transition-transform',
              open && 'rotate-90'
            )}
          />
          <span className="truncate text-[13px] font-medium">
            {field.label || __('(unnamed)', 'schemapress')}
          </span>
          <Badge variant="mono">{field.key}</Badge>
          {field.required ? <Badge variant="warning">{__('required', 'schemapress')}</Badge> : null}
          <span className="ml-auto shrink-0 text-[11px] text-muted-foreground">
            {type?.label || field.type}
          </span>
        </button>

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
      </div>

      {open ? (
        <div className="flex flex-col gap-3 border-t border-border bg-muted/20 p-3">
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
                            key: uniqueKey(toKey(event.target.value), siblingKeys)
                          }
                        : { label: event.target.value }
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
                    label: candidate.label
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

          {nests ? (
            <div className="rounded-md border-l-2 border-border bg-background p-3 pl-4">
              <Heading className="mb-2">{__('Sub fields', 'schemapress')}</Heading>
              <FieldsEditor
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
