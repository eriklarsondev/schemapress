/**
 * Editing one element.
 *
 * Content first, because filling something in is what an author came here to
 * do. Classes second, because styling is a developer's pass and belongs behind
 * the thing being styled rather than in front of it.
 *
 * The two tabs write to different places: content is this instance's value,
 * classes are part of the schema and apply to every instance of the component.
 * The dialog says so, since nothing else about the interface would reveal it.
 */

import { useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { PencilLine, Paintbrush, Info } from 'lucide-react'
import {
  Dialog,
  Tabs,
  TabPanel,
  Field,
  Input,
  Badge,
  Alert,
  Heading,
  Button,
  Switch,
  Select
} from '../../ui'
import { FieldControl } from '../fields'
import { FieldConfig } from '../builder/FieldConfig'
import { FieldsEditor } from '../builder/FieldEditor'
import { toKey, uniqueKey } from '../utils'
import { settings, rolesFor } from '../settings'
import { fieldTypesForClient } from './fieldTypes'

/**
 * Element editor.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
export function ElementDialog({
  field,
  value,
  editable,
  context,
  siblingKeys,
  onClose,
  onChange,
  onFieldChange
}) {
  const [tab, setTab] = useState('content')

  // the key is the address of everything already stored under it, so it only
  // tracks the label while still the placeholder a new element is created with
  const [derived, setDerived] = useState(() => /^field(_\d+)?$/.test(field.key))

  const tabs = [
    { value: 'content', label: __('Content', 'schemapress'), icon: PencilLine },
    { value: 'classes', label: __('Classes', 'schemapress'), icon: Paintbrush }
  ]

  const available = rolesFor(field.type)
  const roleHelp = available.find((role) => role.key === field.role)?.description

  const control = <FieldControl field={field} value={value} onChange={onChange} context={context} />

  // in content mode there is only one thing to do here, and a tab strip over a
  // single tab is furniture
  if (!editable) {
    return (
      <Dialog
        open
        onOpenChange={(next) => !next && onClose()}
        size="lg"
        title={field.label}
        footer={<Button onClick={onClose}>{__('Done', 'schemapress')}</Button>}
      >
        {control}
      </Dialog>
    )
  }

  /**
   * Merges a change into the field definition.
   *
   * @param {Object} patch
   * @return {void}
   */
  const updateField = (patch) => onFieldChange({ ...field, ...patch })

  return (
    <Dialog
      open
      onOpenChange={(next) => !next && onClose()}
      size="lg"
      title={field.label}
      badge={<Badge variant="mono">{field.key}</Badge>}
      footer={<Button onClick={onClose}>{__('Done', 'schemapress')}</Button>}
    >
      <Tabs tabs={tabs} value={tab} onValueChange={setTab}>
        <TabPanel value="content">{control}</TabPanel>

        <TabPanel value="classes">
          <div className="flex flex-col gap-5">
            <Field
              label={__('CSS classes', 'schemapress')}
              help={__(
                'Applied to this element wherever the component appears.',
                'schemapress'
              )}
            >
              {(id) => (
                <Input
                  id={id}
                  className="font-mono text-[12px]"
                  placeholder="text-2xl font-semibold tracking-tight"
                  value={field.classes || ''}
                  onChange={(event) => updateField({ classes: event.target.value })}
                  disabled={!editable}
                />
              )}
            </Field>

            <Alert variant="info">
              <span className="flex items-start gap-2">
                <Info className="mt-0.5 size-3.5 shrink-0" />
                <span>
                  {__(
                    'Classes live in the schema, not in this page — every instance of this component gets them.',
                    'schemapress'
                  )}
                  {settings.safelistPath ? (
                    <>
                      {' '}
                      {__(
                        'Tailwind only scans files, so add this to the content array in tailwind.config.js and rebuild:',
                        'schemapress'
                      )}{' '}
                      <code className="rounded bg-sky-100 px-1 py-0.5 text-[11px]">
                        {settings.safelistPath}
                      </code>
                    </>
                  ) : null}
                </span>
              </span>
            </Alert>

            {editable ? (
              <section className="flex flex-col gap-3 border-t border-border pt-4">
                <Heading>{__('Element settings', 'schemapress')}</Heading>

                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label={__('Label', 'schemapress')}>
                    {(id) => (
                      <Input
                        id={id}
                        value={field.label}
                        onChange={(event) =>
                          updateField(
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
                        : __('Changing this orphans saved content', 'schemapress')
                    }
                  >
                    {(id) => (
                      <Input
                        id={id}
                        className="font-mono text-[12px]"
                        value={field.key}
                        onChange={(event) => {
                          setDerived(false)
                          updateField({ key: uniqueKey(toKey(event.target.value), siblingKeys) })
                        }}
                      />
                    )}
                  </Field>
                </div>

                {available.length > 0 ? (
                  <Field
                    label={__('Role', 'schemapress')}
                    help={
                      roleHelp ||
                      __('Where this element sits in the component.', 'schemapress')
                    }
                  >
                    {(id) => (
                      <Select
                        id={id}
                        value={field.role || ''}
                        options={[
                          { value: '', label: __('In the flow', 'schemapress') },
                          ...available.map((role) => ({ value: role.key, label: role.label }))
                        ]}
                        onChange={(role) => updateField({ role })}
                      />
                    )}
                  </Field>
                ) : null}

                <div className="flex items-end gap-4">
                  <Field label={__('Help text', 'schemapress')} className="flex-1">
                    {(id) => (
                      <Input
                        id={id}
                        value={field.help || ''}
                        onChange={(event) => updateField({ help: event.target.value })}
                      />
                    )}
                  </Field>
                  <div className="pb-2">
                    <Switch
                      label={__('Required', 'schemapress')}
                      checked={Boolean(field.required)}
                      onChange={(required) => updateField({ required })}
                    />
                  </div>
                </div>

                <FieldConfig field={field} onChange={(config) => updateField({ config })} />

                {Array.isArray(field.fields) ? (
                  <div className="rounded-md border-l-2 border-border bg-muted/20 p-3 pl-4">
                    <Heading className="mb-2">{__('Sub elements', 'schemapress')}</Heading>
                    <FieldsEditor
                      fields={field.fields}
                      fieldTypes={fieldTypesForClient()}
                      onChange={(fields) => updateField({ fields })}
                    />
                  </div>
                ) : null}
              </section>
            ) : null}
          </div>
        </TabPanel>
      </Tabs>
    </Dialog>
  )
}
