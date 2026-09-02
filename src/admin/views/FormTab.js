/**
 * Arranging the entry form.
 *
 * Same fields, different question. Fields asks what an entry *is*; this asks
 * what filling one in should feel like — what order, and how wide each control
 * sits. Strapi separates these for good reason: the shape of the data and the
 * ergonomics of typing it in change at different times and for different
 * people.
 *
 * Width is stored on the field as a form hint. It is not presentation of the
 * *content* — nothing here reaches the front end — it is presentation of the
 * admin form, which is this plugin's own screen to arrange.
 */

import { useEffect, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Save, ChevronUp, ChevronDown, LayoutList } from 'lucide-react'
import { Card, CardBody, Button, Alert, Badge, Empty, Segmented, cn } from '../../ui'
import { move } from '../../shared/utils'

const WIDTHS = [
  { value: 'full', label: __('Full', 'schemapress') },
  { value: 'half', label: __('Half', 'schemapress') }
]

/**
 * The entry form arranger.
 *
 * @param {Object} props
 * @return {JSX.Element} The tab.
 */
export function FormTab({ fields, onChange }) {
  const [draft, setDraft] = useState(fields)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    setDraft(fields)
  }, [fields])

  const dirty = JSON.stringify(draft) !== JSON.stringify(fields)

  /**
   * Sets one field's form width.
   *
   * @param {number} index
   * @param {string} width
   * @return {void}
   */
  const setWidth = (index, width) =>
    setDraft(
      draft.map((field, i) =>
        i === index ? { ...field, config: { ...field.config, width } } : field
      )
    )

  /**
   * Stores the draft.
   *
   * @return {void}
   */
  const save = () => {
    setSaving(true)
    Promise.resolve(onChange(draft)).finally(() => setSaving(false))
  }

  if (fields.length === 0) {
    return (
      <Empty
        icon={LayoutList}
        title={__('Nothing to arrange yet', 'schemapress')}
        description={__('Add some fields first, then come back.', 'schemapress')}
        className="py-16"
      />
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <Alert variant="info">
        {__(
          'This is the order and width of the entry form only. It changes nothing about how the content is delivered.',
          'schemapress'
        )}
      </Alert>

      <Card>
        <CardBody className="flex flex-col gap-2">
          {/* laid out as the form will be, so the arrangement is legible as an
              arrangement rather than as a list of settings about one */}
          <div className="flex flex-wrap gap-2">
            {draft.map((field, index) => (
              <div
                key={field.key}
                className={cn(
                  'flex flex-col gap-2 rounded-lg border border-border bg-background p-3',
                  (field.config?.width || 'full') === 'half'
                    ? 'w-[calc(50%-0.25rem)]'
                    : 'w-full'
                )}
              >
                <div className="flex min-w-0 items-center gap-1.5">
                  <span className="min-w-0 flex-1 truncate text-[13px] font-medium">
                    {field.label}
                  </span>
                  <Badge variant="mono">{field.type}</Badge>
                </div>

                <div className="flex items-center justify-between gap-2">
                  <Segmented
                    value={field.config?.width || 'full'}
                    options={WIDTHS}
                    onChange={(width) => setWidth(index, width)}
                  />

                  <span className="flex items-center gap-0.5">
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      aria-label={__('Move earlier', 'schemapress')}
                      disabled={index === 0}
                      onClick={() => setDraft(move(draft, index, index - 1))}
                    >
                      <ChevronUp />
                    </Button>
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      aria-label={__('Move later', 'schemapress')}
                      disabled={index === draft.length - 1}
                      onClick={() => setDraft(move(draft, index, index + 1))}
                    >
                      <ChevronDown />
                    </Button>
                  </span>
                </div>
              </div>
            ))}
          </div>
        </CardBody>
      </Card>

      <div className="flex items-center gap-3">
        <Button disabled={!dirty || saving} onClick={save}>
          <Save />
          {saving ? __('Saving…', 'schemapress') : __('Save layout', 'schemapress')}
        </Button>

        {dirty && !saving ? (
          <span className="text-[12px] text-muted-foreground">
            {__('Unsaved changes', 'schemapress')}
          </span>
        ) : null}
      </div>
    </div>
  )
}
