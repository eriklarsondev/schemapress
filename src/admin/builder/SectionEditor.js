/**
 * One section type in a schema, on the Schemas screen.
 *
 * Expands in place rather than opening a dialog. The build screen already
 * drills into components, and a second, modal way of doing the same thing is
 * both more to learn and a way to end up with dialogs on top of dialogs.
 */

import { useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { ChevronUp, ChevronDown, Trash2, GripVertical, ChevronRight } from 'lucide-react'
import { toKey, uniqueKey } from '../../shared/utils'
import { Button, Card, Input, Field, Badge, Heading, cn } from '../../ui'
import { FieldsEditor } from '../../shared/builder/FieldEditor'

/**
 * Section type definition editor.
 *
 * @param {Object} props
 * @return {JSX.Element} The editor.
 */
export function SectionEditor({
  section,
  index,
  total,
  siblingKeys,
  fieldTypes,
  onChange,
  onMove,
  onRemove
}) {
  const [open, setOpen] = useState(false)

  // the key is the section type placed content refers to, so renaming one
  // orphans every section already placed with it. it tracks the label only
  // while still the untouched placeholder a new section is created with.
  const [derived, setDerived] = useState(() => /^section(_\d+)?$/.test(section.key))

  /**
   * Merges a partial change into the section.
   *
   * @param {Object} patch
   * @return {void}
   */
  const update = (patch) => onChange({ ...section, ...patch })

  const fields = section.fields || []
  const enabled = section.layout || []

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center gap-2 px-3 py-2.5">
        <GripVertical className="size-3.5 shrink-0 text-muted-foreground/40" />

        <button
          type="button"
          aria-expanded={open}
          onClick={() => setOpen((state) => !state)}
          className="flex min-w-0 flex-1 items-start gap-2 text-left"
        >
          <ChevronRight
            className={cn(
              'mt-1 size-3.5 shrink-0 text-muted-foreground transition-transform',
              open && 'rotate-90'
            )}
          />

          <span className="flex min-w-0 flex-1 flex-col gap-0.5">
            <span className="flex flex-wrap items-center gap-1.5">
              <span className="text-[13px] font-semibold">{section.label}</span>
              <Badge variant="mono">{section.key}</Badge>
              {section.container ? (
                <Badge variant="outline">{__('container', 'schemapress')}</Badge>
              ) : null}
            </span>

            <span className="flex flex-wrap items-center gap-1.5 text-[12px] text-muted-foreground">
              <span>
                {fields.length}{' '}
                {fields.length === 1
                  ? __('field', 'schemapress')
                  : __('fields', 'schemapress')}
              </span>
              {enabled.length > 0 ? (
                <span>· {enabled.join(', ')}</span>
              ) : null}
              {section.max > 0 ? (
                <span>
                  · {__('max', 'schemapress')} {section.max}
                </span>
              ) : null}
            </span>
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
          aria-label={__('Remove section type', 'schemapress')}
          onClick={onRemove}
        >
          <Trash2 />
        </Button>
      </div>

      {open ? (
        <div className="flex flex-col gap-5 border-t border-border bg-muted/20 p-4">
          <section className="flex flex-col gap-3">
            <div className="grid gap-3 sm:grid-cols-3">
              <Field label={__('Label', 'schemapress')}>
                {(id) => (
                  <Input
                    id={id}
                    value={section.label}
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
                    ? __('The section type in delivered JSON', 'schemapress')
                    : __('Changing this orphans sections already placed', 'schemapress')
                }
              >
                {(id) => (
                  <Input
                    id={id}
                    className="font-mono text-[12px]"
                    value={section.key}
                    onChange={(event) => {
                      setDerived(false)
                      update({ key: uniqueKey(toKey(event.target.value), siblingKeys) })
                    }}
                  />
                )}
              </Field>

              <Field
                label={__('Max per page', 'schemapress')}
                help={__('0 for unlimited', 'schemapress')}
              >
                {(id) => (
                  <Input
                    id={id}
                    type="number"
                    min="0"
                    value={section.max || 0}
                    onChange={(event) => update({ max: Number(event.target.value) || 0 })}
                  />
                )}
              </Field>
            </div>

            <Field
              label={__('Description', 'schemapress')}
              help={__('Shown to authors in the component library', 'schemapress')}
            >
              {(id) => (
                <Input
                  id={id}
                  value={section.description || ''}
                  onChange={(event) => update({ description: event.target.value })}
                />
              )}
            </Field>
          </section>

          <section className="flex flex-col gap-3">
            <Heading>{__('Fields', 'schemapress')}</Heading>
            <FieldsEditor
              fields={fields}
              fieldTypes={fieldTypes}
              onChange={(next) => update({ fields: next })}
            />
          </section>
        </div>
      ) : null}
    </Card>
  )
}
