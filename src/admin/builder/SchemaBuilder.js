/**
 * The section-type list of a schema.
 *
 * Controlled and persistence-free so both the standalone schema editor and the
 * guided workflow can host it and decide for themselves when to save.
 */

import { __ } from '@wordpress/i18n'
import { Plus, Layers } from 'lucide-react'
import { move, removeAt, replaceAt, uniqueKey } from '../../shared/utils'
import { Button, Empty } from '../../ui'
import { SectionEditor } from './SectionEditor'

/**
 * Creates a blank section type whose key does not collide with its siblings.
 *
 * @param {Array} sections
 * @return {Object} The new section type.
 */
export function blankSection(sections) {
  return {
    key: uniqueKey('section', sections.map((section) => section.key)),
    label: __('New Section', 'schemapress'),
    description: '',
    icon: 'layout',
    max: 0,
    layout: [],
    fields: []
  }
}

/**
 * Section type list editor.
 *
 * @param {Object} props
 * @return {JSX.Element} The builder.
 */
export function SchemaBuilder({ sections, fieldTypes, onChange }) {
  return (
    <div className="flex flex-col gap-3">
      {sections.map((section, index) => (
        <SectionEditor
          key={index}
          section={section}
          index={index}
          total={sections.length}
          siblingKeys={sections.filter((_, i) => i !== index).map((s) => s.key)}
          fieldTypes={fieldTypes}
          onChange={(next) => onChange(replaceAt(sections, index, next))}
          onMove={(to) => onChange(move(sections, index, to))}
          onRemove={() => onChange(removeAt(sections, index))}
        />
      ))}

      {sections.length === 0 ? (
        <Empty
          icon={Layers}
          title={__('No section types yet', 'schemapress')}
          description={__(
            'A section type is one block of a page — a hero, a body of text, a grid of cards. Add the first one to describe what authors can place.',
            'schemapress'
          )}
        />
      ) : null}

      <div>
        <Button variant="outline" onClick={() => onChange([...sections, blankSection(sections)])}>
          <Plus />
          {__('Add section type', 'schemapress')}
        </Button>
      </div>
    </div>
  )
}
