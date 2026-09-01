/**
 * The builder: a panel beside the live page.
 *
 * The canvas is the rendered page, always. The panel is contextual - it shows
 * the component library until you select something, and that component's
 * settings once you do. That is the shape every visual builder converges on,
 * because it means the page never leaves the screen while you work on it.
 *
 * The page tree lives here; SectionFocus and the library only report changes.
 */

import { useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { ChevronLeft, Layers } from 'lucide-react'
import {
  move,
  nodeId,
  emptyValues,
  uniqueKey,
  presetToSection,
  listAt,
  setListAt,
  nodeAt,
  setNodeAt,
  isWithin
} from '../utils'
import { defaultLayout } from '../settings'
import { Alert, Button, Empty } from '../../ui'
import { ComponentLibrary } from './ComponentLibrary'
import { SectionOutline } from './SectionOutline'
import { SectionFocus } from './SectionFocus'
import { PageCanvas } from './PageCanvas'
import { DragProvider } from './dnd'

const MAX_DEPTH = 3

/**
 * Finds a section's address by its id, anywhere in the tree.
 *
 * The canvas only knows ids - it is rendered HTML, and an address would mean
 * encoding the tree into the markup.
 *
 * @param {Array} sections
 * @param {string} id
 * @param {Array} path
 * @return {Array|null} The address, or null.
 */
function addressOf(sections, id, path = []) {
  for (let index = 0; index < sections.length; index++) {
    const here = [...path, index]

    if (sections[index].id === id) {
      return here
    }

    const inside = addressOf(sections[index].children || [], id, here)

    if (inside) {
      return inside
    }
  }

  return null
}

/**
 * The page builder.
 *
 * @param {Object} props
 * @return {JSX.Element} The builder.
 */
export function PageBuilder({ definition, sections, fieldTypes, onChange, onDefinitionChange }) {
  const types = definition?.sections || []
  const editable = typeof onDefinitionChange === 'function'

  const [selected, setSelected] = useState(null)
  const [openKey, setOpenKey] = useState(null)

  const address = selected ? addressOf(sections, selected) : null
  const focused = address ? nodeAt(sections, address) : null

  const counts = countTypes(sections)

  /**
   * Replaces the list at a path.
   *
   * @param {Array} path
   * @param {Array} next
   * @return {void}
   */
  const setList = (path, next) => onChange(setListAt(sections, path, next))

  /**
   * Inserts an instance of an existing type.
   *
   * @param {string} type
   * @param {Array}  path
   * @param {number} index
   * @return {void}
   */
  const addSection = (type, path = [], index = null) => {
    const typeDefinition = types.find((candidate) => candidate.key === type)

    if (!typeDefinition) {
      return
    }

    const list = listAt(sections, path)
    const instance = blankInstance(typeDefinition)
    const next = [...list]

    next.splice(index ?? list.length, 0, instance)
    onChange(setListAt(sections, path, next))

    // land on what you just added, rather than making the author find it
    setSelected(instance.id)
  }

  /**
   * Defines a new section type and places one in the same action.
   *
   * @param {Object|null} preset
   * @param {Array}       path
   * @param {number|null} index
   * @return {void}
   */
  const createSection = (preset, path = [], index = null) => {
    const taken = types.map((type) => type.key)

    const typeDefinition = preset
      ? presetToSection(preset, taken)
      : {
          key: uniqueKey('section', taken),
          label: __('New Component', 'schemapress'),
          description: '',
          icon: 'layout',
          max: 0,
          container: false,
          layout: [],
          layoutDefaults: {},
          fields: []
        }

    onDefinitionChange([...types, typeDefinition])

    const list = listAt(sections, path)
    const instance = blankInstance(typeDefinition)
    const next = [...list]

    next.splice(index ?? list.length, 0, instance)
    onChange(setListAt(sections, path, next))
    setSelected(instance.id)
  }

  /**
   * Replaces one section type in the schema.
   *
   * @param {Object} next
   * @return {void}
   */
  const updateType = (next) => {
    const index = types.findIndex((type) => type.key === next.key)

    onDefinitionChange(
      index === -1 ? [...types, next] : types.map((type, i) => (i === index ? next : type))
    )
  }

  /**
   * Resolves a drop into the right mutation.
   *
   * @param {Array}  path
   * @param {number} index
   * @param {Object} payload
   * @return {void}
   */
  const handleDrop = (path, index, payload) => {
    if (payload.kind === 'move') {
      moveSection(payload, path, index)

      return
    }

    if (payload.kind === 'type') {
      addSection(payload.key, path, index)

      return
    }

    createSection(payload.preset, path, index)
  }

  /**
   * Moves a section, possibly to a different level of the tree.
   *
   * @param {Object} payload
   * @param {Array}  toPath
   * @param {number} toIndex
   * @return {void}
   */
  const moveSection = (payload, toPath, toIndex) => {
    const { path: fromPath, index: fromIndex } = payload
    const source = [...fromPath, fromIndex]

    // dropping a container inside itself would take the branch out of the tree
    if (isWithin(toPath, source)) {
      return
    }

    if (fromPath.length === toPath.length && isWithin(toPath, fromPath)) {
      const list = listAt(sections, fromPath)
      // the insertion point counts the section being moved, so a downward
      // move shifts every later slot
      const to = fromIndex < toIndex ? toIndex - 1 : toIndex

      onChange(setListAt(sections, fromPath, move(list, fromIndex, to)))

      return
    }

    const fromList = listAt(sections, fromPath)
    const moved = fromList[fromIndex]

    if (!moved) {
      return
    }

    const without = setListAt(
      sections,
      fromPath,
      fromList.filter((_, i) => i !== fromIndex)
    )

    const toList = [...listAt(without, toPath)]
    toList.splice(Math.min(toIndex, toList.length), 0, moved)

    onChange(setListAt(without, toPath, toList))
  }

  /**
   * Applies a change to the selected section.
   *
   * @param {Object} next
   * @return {void}
   */
  const updateSelected = (next) => onChange(setNodeAt(sections, address, next))

  /**
   * Applies a typed value from the canvas, which reports section ids rather
   * than addresses.
   *
   * @param {string} sectionId
   * @param {string} key
   * @param {string} value
   * @return {void}
   */
  const editField = (sectionId, key, value) => {
    const at = addressOf(sections, sectionId)
    const node = at && nodeAt(sections, at)

    if (!node) {
      return
    }

    onChange(setNodeAt(sections, at, { ...node, values: { ...node.values, [key]: value } }))
  }

  if (types.length === 0 && !editable) {
    return (
      <Alert variant="warning">
        {__('This schema defines no components yet.', 'schemapress')}
      </Alert>
    )
  }

  return (
    <DragProvider>
      <div className="grid items-start gap-4 lg:grid-cols-[19rem_minmax(0,1fr)]">
        <aside className="lg:sticky lg:top-6 lg:max-h-[calc(100vh-9rem)] lg:overflow-y-auto">
          {focused ? (
            <div className="flex flex-col gap-3">
              <Button variant="outline" size="sm" onClick={() => setSelected(null)}>
                <ChevronLeft />
                {__('All components', 'schemapress')}
              </Button>

              <SectionFocus
                section={focused}
                definition={types.find((candidate) => candidate.key === focused.type)}
                address={address}
                types={types}
                counts={counts}
                fieldTypes={fieldTypes}
                editable={editable}
                maxDepth={MAX_DEPTH}
                openKey={openKey}
                onOpened={() => setOpenKey(null)}
                onNavigate={(id) => setSelected(id)}
                onChange={updateSelected}
                onDefinitionChange={updateType}
                onListChange={setList}
                onDrop={handleDrop}
              />
            </div>
          ) : (
            <ComponentLibrary
              sections={types}
              counts={counts}
              onSelect={(type) => addSection(type)}
              onCreate={editable ? (preset) => createSection(preset) : undefined}
            />
          )}
        </aside>

        <div className="flex flex-col gap-3">
          {sections.length === 0 ? (
            <Empty
              icon={Layers}
              title={__('Nothing on this page yet', 'schemapress')}
              description={__(
                'Pick a component from the library to start building.',
                'schemapress'
              )}
              className="py-20"
            />
          ) : (
            <PageCanvas
              selectedId={selected}
              onSelect={setSelected}
              onFieldEdit={editField}
              onFieldOpen={setOpenKey}
            />
          )}

          <SectionOutline
            sections={sections}
            types={types}
            selectedId={selected}
            counts={counts}
            editable={editable}
            onSelect={setSelected}
            onDrop={handleDrop}
            onListChange={setList}
          />
        </div>
      </div>
    </DragProvider>
  )
}

/**
 * Counts placed instances of each type across the whole tree.
 *
 * @param {Array}  sections
 * @param {Object} totals
 * @return {Object} Counts keyed by type.
 */
function countTypes(sections, totals = {}) {
  sections.forEach((section) => {
    totals[section.type] = (totals[section.type] || 0) + 1

    if (section.children?.length) {
      countTypes(section.children, totals)
    }
  })

  return totals
}

/**
 * An empty placed instance of a section type.
 *
 * @param {Object} typeDefinition
 * @return {Object} The instance.
 */
function blankInstance(typeDefinition) {
  return {
    id: nodeId('s'),
    type: typeDefinition.key,
    layout: defaultLayout(typeDefinition.layout, typeDefinition.layoutDefaults),
    values: emptyValues(typeDefinition.fields),
    children: []
  }
}
