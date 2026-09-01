/**
 * The settings panel for the selected component.
 *
 * Lives in the rail beside the canvas, so the page stays visible while its
 * parts are being changed. Narrow by design - the page is the wide thing.
 *
 * The organising idea is scope. Some controls change this page; others change
 * the component, and so change every page using it. Those are labelled,
 * because they are otherwise indistinguishable: a section's width and whether
 * a width control exists at all are both just "Width".
 */

import { useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Users, Layers, Boxes, LayoutDashboard as LayoutIcon } from 'lucide-react'
import { Badge, Card, CardBody, Alert, Tabs, TabPanel, cn } from '../../ui'
import { ElementCanvas } from './ElementCanvas'
import { SectionOutline } from './SectionOutline'
import { LayoutControls, hasLayout } from './LayoutControls'
import { useDesign } from './mode'

/**
 * Component settings panel.
 *
 * @param {Object} props
 * @return {JSX.Element} The panel.
 */
export function SectionFocus({
  section,
  definition,
  address,
  types,
  counts,
  fieldTypes,
  editable,
  maxDepth,
  openKey,
  onOpened,
  onNavigate,
  onChange,
  onDefinitionChange,
  onListChange,
  onDrop
}) {
  const design = useDesign()
  const [tab, setTab] = useState('elements')

  if (!definition) {
    return (
      <Alert variant="warning">
        {__('That component no longer exists in the schema.', 'schemapress')}
      </Alert>
    )
  }

  const layoutEnabled = definition.layout || []
  const structural = editable && design

  const tabs = [
    { value: 'elements', label: __('Content', 'schemapress'), icon: Layers },
    { value: 'layout', label: __('Layout', 'schemapress'), icon: LayoutIcon },
    ...(definition.container
      ? [{ value: 'inside', label: __('Inside', 'schemapress'), icon: Boxes }]
      : [])
  ]

  return (
    <div className="flex flex-col gap-3">
      <div>
        <div className="flex flex-wrap items-center gap-1.5">
          <h2 className="text-[15px] font-semibold tracking-tight">{definition.label}</h2>
          <Badge variant="mono">{definition.key}</Badge>
          {definition.container ? (
            <Badge variant="outline">{__('container', 'schemapress')}</Badge>
          ) : null}
        </div>

        {definition.description ? (
          <p className="mt-0.5 text-[12px] text-muted-foreground">{definition.description}</p>
        ) : null}
      </div>

      <Tabs tabs={tabs} value={tab} onValueChange={setTab}>
        <TabPanel value="elements">
          <Card>
            <CardBody className="flex flex-col gap-3">
              <ScopeNote scope={structural ? 'both' : 'page'} />

              <ElementCanvas
                fields={definition.fields || []}
                values={section.values}
                editable={editable}
                context={{ columns: Number(section.layout?.columns) || undefined }}
                openKey={openKey}
                onOpened={onOpened}
                onFieldsChange={
                  editable ? (fields) => onDefinitionChange({ ...definition, fields }) : () => {}
                }
                onChange={(key, value) =>
                  onChange({ ...section, values: { ...section.values, [key]: value } })
                }
              />
            </CardBody>
          </Card>
        </TabPanel>

        <TabPanel value="layout">
          <Card>
            <CardBody className="flex flex-col gap-3">
              <ScopeNote scope="page" />

              {hasLayout(layoutEnabled) ? (
                <LayoutControls
                  enabled={layoutEnabled}
                  values={section.layout || {}}
                  onChange={(layout) => onChange({ ...section, layout })}
                />
              ) : (
                <p className="text-[12px] text-muted-foreground">
                  {__('This component has no layout controls.', 'schemapress')}
                </p>
              )}
            </CardBody>
          </Card>
        </TabPanel>

        {definition.container ? (
          <TabPanel value="inside">
            <SectionOutline
              sections={section.children || []}
              types={types}
              selectedId={section.id}
              counts={counts}
              editable={editable}
              onSelect={onNavigate}
              onDrop={onDrop}
              onListChange={onListChange}
            />
          </TabPanel>
        ) : null}
      </Tabs>
    </div>
  )
}

/**
 * States what an edit below it reaches.
 *
 * @param {Object} props
 * @return {JSX.Element} The note.
 */
function ScopeNote({ scope }) {
  const notes = {
    page: __('Applies to this page', 'schemapress'),
    both: __('Content is this page; structure is every page using it', 'schemapress')
  }

  return (
    <p
      className={cn(
        'flex items-center gap-1 text-[11px]',
        scope === 'both' ? 'text-amber-800' : 'text-muted-foreground'
      )}
    >
      {scope === 'both' ? <Users className="size-3 shrink-0" /> : null}
      {notes[scope]}
    </p>
  )
}
