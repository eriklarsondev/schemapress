/**
 * The documentation screen.
 *
 * The text is not written here. It lives in docs/*.md, which PHP compiles and
 * ships with the page — so a paragraph is edited by editing Markdown, and the
 * same source still reads correctly in the repository.
 *
 * What this file owns is the reading experience: one column of prose, a
 * contents list that tracks where you are, and the app's sidebar still beside
 * it, because looking something up is not a reason to leave the builder.
 */

import { useEffect, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { BookOpen } from 'lucide-react'
import { Alert, Empty, cn } from '../../ui'

/**
 * The docs.
 *
 * @param {Object} props
 * @return {JSX.Element} The screen.
 */
export function DocsView({ docs }) {
  const sections = docs?.sections || []
  const current = useCurrentHeading(sections)

  if (sections.length === 0) {
    return (
      <div className="mx-auto max-w-3xl py-10">
        <Empty
          icon={BookOpen}
          title={__('No documentation was found', 'schemapress')}
          description={__(
            'The documentation is compiled from the plugin’s docs directory, and no source files are there.',
            'schemapress',
          )}
        />
      </div>
    )
  }

  return (
    <div className="mx-auto flex max-w-[76rem] items-start gap-12">
      <div className="min-w-0 flex-1">
        <header className="max-w-2xl pb-8">
          <h1 className="text-[26px] font-semibold tracking-tight">
            {__('Documentation', 'schemapress')}
          </h1>

          <p className="mt-2 text-[14px] leading-relaxed text-muted-foreground">
            {__(
              'Define a collection, fill in its entries, and read them from your theme. Nothing about presentation is stored here — what the content looks like is your templates’ business.',
              'schemapress',
            )}
          </p>
        </header>

        {docs?.parser === false ? (
          <Alert variant="warning" className="mb-8 max-w-2xl">
            {__(
              'A Markdown parser is not installed, so the documentation below is shown as plain text. Run composer install to format it.',
              'schemapress',
            )}
          </Alert>
        ) : null}

        <div className="max-w-2xl space-y-14 pb-24">
          {sections.map((section) => (
            <section key={section.id}>
              <h2
                id={section.id}
                className="scroll-mt-16 border-t border-border pt-8 text-[20px] font-semibold tracking-tight"
              >
                {section.title}
              </h2>

              {/* the HTML is compiled from files this plugin ships, not from
                  anything a user submits — the same source the repository is
                  read from */}
              <div
                className="sp-prose mt-4"
                dangerouslySetInnerHTML={{ __html: section.html }}
              />
            </section>
          ))}
        </div>
      </div>

      <nav
        aria-label={__('On this page', 'schemapress')}
        className="sticky top-2 hidden w-56 shrink-0 py-1 xl:block"
      >
        <p className="px-3 pb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {__('On this page', 'schemapress')}
        </p>

        <ul className="border-l border-border">
          {sections.map((section) => (
            <li key={section.id}>
              <Link id={section.id} label={section.title} current={current} topic />

              {section.headings.map((heading) => (
                <Link
                  key={heading.id}
                  id={heading.id}
                  label={heading.title}
                  current={current}
                />
              ))}
            </li>
          ))}
        </ul>
      </nav>
    </div>
  )
}

/**
 * One contents link.
 *
 * A button rather than an anchor: the app routes on the fragment, so an
 * `href="#..."` would be read as navigation to a screen that does not exist.
 *
 * @param {Object} props
 * @return {JSX.Element} The link.
 */
function Link({ id, label, current, topic }) {
  return (
    <button
      type="button"
      onClick={() => {
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      }}
      className={cn(
        '-ml-px block w-full border-l py-1 pr-2 text-left text-[12px] leading-snug transition-colors',
        topic ? 'pl-3 font-medium' : 'pl-6',
        current === id
          ? 'border-primary text-foreground'
          : 'border-transparent text-muted-foreground hover:text-foreground',
      )}
    >
      {label}
    </button>
  )
}

/**
 * The heading currently in view.
 *
 * A contents list that does not track the page is one you stop trusting, and
 * on a page this long the reader otherwise loses their place.
 *
 * @param {Array} sections
 * @return {string} The heading's id.
 */
function useCurrentHeading(sections) {
  const [current, setCurrent] = useState('')

  useEffect(() => {
    const ids = sections.flatMap((section) => [
      section.id,
      ...section.headings.map((heading) => heading.id),
    ])

    const headings = ids.map((id) => document.getElementById(id)).filter(Boolean)

    if (headings.length === 0 || !window.IntersectionObserver) {
      return undefined
    }

    const visible = new Set()

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            visible.add(entry.target.id)
          } else {
            visible.delete(entry.target.id)
          }
        })

        // the topmost visible one, in document order — with several on screen,
        // the heading you are reading under is the first of them
        const first = ids.find((id) => visible.has(id))

        if (first) {
          setCurrent(first)
        }
      },
      { rootMargin: '-64px 0px -70% 0px' },
    )

    headings.forEach((heading) => observer.observe(heading))

    return () => observer.disconnect()
  }, [sections])

  return current
}
