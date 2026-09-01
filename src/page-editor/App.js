/**
 * The builder as it appears on a page's own edit screen.
 *
 * The same live canvas the SchemaPress screen uses, in content-only mode: no
 * element palette, no keys, no structural controls. An author editing a page
 * here cannot reshape the component behind it, because that component belongs
 * to every other page on the template too.
 *
 * State is mirrored into the hidden form field on every change, so the post's
 * Publish button saves sections together with the post.
 */

import { useState, useEffect } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { ExternalLink } from 'lucide-react'
import { PageBuilder } from '../shared/content/PageBuilder'
import { PreviewProvider } from '../shared/content/preview'
import { DesignProvider } from '../shared/content/mode'
import { Button, Badge } from '../ui'

/**
 * Writes the current state into the hidden input the post form submits.
 *
 * @param {string} inputName
 * @param {Array}  sections
 * @return {void}
 */
function mirrorToForm(inputName, sections) {
  const input = document.getElementById(inputName)

  if (input) {
    input.value = JSON.stringify({ version: 1, sections })
  }
}

/**
 * Root component of the page editor metabox.
 *
 * @param {Object} props
 * @return {JSX.Element} The editor.
 */
export function App({ settings }) {
  const inputName = settings.inputName || 'schemapress_content'
  const [sections, setSections] = useState(() => settings.content?.sections || [])

  useEffect(() => {
    mirrorToForm(inputName, sections)
  }, [inputName, sections])

  return (
    // `.schemapress` is the scope every Tailwind utility is prefixed with, and
    // that prefix is a descendant selector — so this element carries the class
    // alone and the styling goes on the child inside it
    <div className="schemapress">
      <div className="bg-background p-4">
        <div className="mb-3 flex items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <span className="text-[13px] font-semibold">{settings.schemaTitle}</span>
            <Badge variant="outline">{__('Schema', 'schemapress')}</Badge>
          </div>

          {settings.appUrl ? (
            <Button size="sm" variant="ghost" asChild>
              <a href={`${settings.appUrl}#/pages/${settings.postId}`}>
                {__('Open in SchemaPress', 'schemapress')}
                <ExternalLink />
              </a>
            </Button>
          ) : null}
        </div>

        <PreviewProvider
          postId={settings.postId}
          definition={settings.definition}
          sections={sections}
        >
          {/* no design mode here: reshaping a component from a single page
              would change every other page using it, which is not a decision
              this screen should be able to make */}
          <DesignProvider design={false}>
            <PageBuilder
              definition={settings.definition}
              sections={sections}
              onChange={setSections}
            />
          </DesignProvider>
        </PreviewProvider>
      </div>
    </div>
  )
}
