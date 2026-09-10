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
 *
 * One file is one page, reached at `#/docs/<slug>` and listed in the sidebar
 * under Documentation. The whole set used to be stacked into a single scroll,
 * which meant the sidebar could only ever say "you are in the documentation" —
 * true of every topic at once — and left the page carrying two contents lists
 * that disagreed about what "this page" meant. Splitting it also means a link
 * to a topic is a link to a topic, not to a place to scroll to.
 *
 * The standalone fallback in class-docs.php still renders everything as one
 * page. It exists for when there is no bundle to route with, so a scroll and
 * an anchor list is the whole of what it can offer.
 */

import { useEffect, useRef, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import {
  BookOpen,
  ChevronRight,
  ArrowLeft,
  ArrowRight,
  Rocket,
  Shapes,
  Cable,
  Filter,
  Plug,
  Code2,
  Braces,
} from 'lucide-react'
import { Alert, Empty, cn, copyText } from '../../ui'
import Prism from '../../shared/prism'

/**
 * The docs.
 *
 * @param {Object} props
 * @return {JSX.Element} The screen.
 */
export function DocsView({ docs, page }) {
  const sections = docs?.sections || []

  // one file is one page. an unknown slug — a stale bookmark, or a topic since
  // renamed — opens the first rather than an error: the reader asked for the
  // documentation, and there is documentation to give them
  // `#/docs` with nothing after it is the front door, not the first topic. an
  // unknown slug still falls through to the first page — that is a stale
  // bookmark, and the reader asked for something specific
  const home = !page
  const section = sections.find((candidate) => candidate.id === page) || sections[0]
  const current = useCurrentHeading(home ? null : section)
  const root = useRef(null)

  // these are separate pages now, and a page you open should start at its
  // beginning. the pane scrolls, not the window, so the reset goes to it
  useEffect(() => {
    root.current?.closest('main')?.scrollTo({ top: 0 })
  }, [section, home])

  useCodeChrome(root, home ? null : section)

  if (sections.length === 0) {
    return (
      <div className="mx-auto max-w-3xl py-10">
        <Empty
          icon={BookOpen}
          title={__('No documentation was found', 'schemapress')}
          description={__(
            'The documentation is compiled from the plugin’s docs directory, and no source files are there.',
            'schemapress'
          )}
        />
      </div>
    )
  }

  return (
    // one centered block: text and contents list side by side, the pair
    // centered by mx-auto. no offsets, no mirrors, nothing measured against
    // anything — the margins either side are whatever is left over, and they
    // are equal because that is what centring means
    <div ref={root} className="mx-auto flex w-full max-w-5xl gap-10 py-2">
      <div className="min-w-0 flex-1">
        {home ? <Splash sections={sections} /> : null}

        {home ? null : (
          <>
            <header className="pb-9">
              {/* where you are, not just what this is. a reference is read by
              arriving in the middle of it from a search result, and a title on
              its own does not say what it is the middle of */}
              <nav
                aria-label={__('Breadcrumb', 'schemapress')}
                className="flex items-center gap-1 text-[12px] text-muted-foreground"
              >
                <span>{__('Documentation', 'schemapress')}</span>
                <ChevronRight className="size-3 shrink-0" aria-hidden="true" />
                <span className="font-medium text-foreground">{section.title}</span>
              </nav>

              <h1 className="mt-2.5 text-[34px] font-semibold leading-[1.15] tracking-[-0.025em]">
                {section.title}
              </h1>

              {/* the standing description of the plugin, on the page you arrive at
              and nowhere else. repeated above every topic it stops being an
              introduction and becomes something to scroll past */}
              {section.id === sections[0].id ? (
                <p className="mt-3 max-w-[40rem] text-[16px] leading-relaxed text-muted-foreground">
                  {__(
                    'Define a collection, fill in its entries, and read them from your theme. Nothing about presentation is stored here — what the content looks like is your templates’ business.',
                    'schemapress'
                  )}
                </p>
              ) : null}
            </header>

            {docs?.parser === false ? (
              <Alert variant="warning" className="mb-8">
                {__(
                  'A Markdown parser is not installed, so the documentation below is shown as plain text. Run composer install to format it.',
                  'schemapress'
                )}
              </Alert>
            ) : null}

            {/* the HTML is compiled from files this plugin ships, not from anything
            a user submits — the same source the repository is read from */}
            <div className="sp-prose" dangerouslySetInnerHTML={{ __html: section.html }} />

            <Pager sections={sections} section={section} />
          </>
        )}
      </div>

      {/* the column is always here, and only what is in it is conditional. a
          page with nothing to list would otherwise hand its width back to the
          prose, and the body text would change measure from one page to the
          next — which reads as the layout breaking rather than as a contents
          list being absent */}
      {/* sticky on the aside itself: self-start keeps it from stretching, and
          its containing block is the flex row, which is as tall as the page —
          so it has somewhere to travel */}
      <aside
        className="sticky top-4 hidden max-h-[calc(100vh-6rem)] w-56 shrink-0 self-start overflow-y-auto xl:block"
        aria-hidden={home || section.headings.length < 2}
      >
        {/* a contents list of one entry is not a contents list */}
        {!home && section.headings.length > 1 ? (
          <nav aria-label={__('On this page', 'schemapress')} className="py-2">
            <p className="px-3 pb-2.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
              {__('On this page', 'schemapress')}
            </p>

            {/* the headings of this page only. which page you are on is the
                sidebar's job now, and listing every topic here as well was two
                contents lists disagreeing about what "this page" meant */}
            <ul className="border-l border-border">
              {section.headings.map((heading) => (
                <li key={heading.id}>
                  <Link id={heading.id} label={heading.title} current={current} />
                </li>
              ))}
            </ul>
          </nav>
        ) : null}
      </aside>
    </div>
  )
}

/**
 * Icons, by page. Falls back to a book for a topic added later, so a new file
 * in docs/ still gets a card rather than a hole.
 */
const ICONS = {
  introduction: Shapes,
  installation: Plug,
  'quick-start': Rocket,
  endpoints: Cable,
  'response-format': Braces,
  errors: Filter,
  filters: Filter,
  'sorting-pagination': Filter,
  'what-can-be-queried': Shapes,
  'writing-a-client': Plug,
  php: Code2,
  'twig-timber': Braces,
}

/**
 * The front door.
 *
 * A reference opened at its first topic assumes you already know it is the
 * first topic. This is the other thing a documentation site owes you: what is
 * here, in one screen, so you can go to the part you need rather than reading
 * forward until you reach it.
 *
 * The cards are built from the sections themselves — a new file in docs/ turns
 * up here without this being edited, which is the same bargain the sidebar
 * makes.
 *
 * @param {Object} props
 * @return {JSX.Element} The splash.
 */
function Splash({ sections }) {
  const [start, ...rest] = sections

  return (
    <div className="pb-24">
      {/* the masthead is the one place on this screen that is allowed to be a
          surface rather than a page of text — it is what says you have arrived
          somewhere rather than landed mid-reference */}
      <header className="relative overflow-hidden rounded-2xl border border-border bg-appbar px-8 py-10 text-appbar-foreground">
        <span
          aria-hidden="true"
          className="pointer-events-none absolute -right-16 -top-20 size-64 rounded-full bg-primary/25 blur-3xl"
        />
        <span
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-24 left-1/3 size-56 rounded-full bg-primary/10 blur-3xl"
        />

        <div className="relative">
          <p className="flex items-center gap-1.5 text-[12px] font-medium text-appbar-muted">
            <BookOpen className="size-3.5" aria-hidden="true" />
            {__('SchemaPress', 'schemapress')}
          </p>

          <h1 className="mt-3 text-[40px] font-semibold leading-[1.05] tracking-[-0.03em]">
            {__('Documentation', 'schemapress')}
          </h1>

          <p className="mt-3.5 max-w-[42rem] text-[16px] leading-relaxed text-appbar-muted">
            {__(
              'Define a collection, fill in its entries, and read them from your theme or over HTTP. Nothing about presentation is stored here — what the content looks like is your templates’ business.',
              'schemapress'
            )}
          </p>
        </div>
      </header>

      {/* the first page is the way in, so it is not one card among seven */}
      {start ? (
        <button
          type="button"
          onClick={() => {
            window.location.hash = `/docs/${start.id}`
          }}
          className="group mt-7 flex w-full items-center gap-4 rounded-xl border border-primary/30 bg-primary/[0.06] p-5 text-left transition-all hover:border-primary/60 hover:bg-primary/10 hover:shadow-sm"
        >
          <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <Rocket className="size-5" aria-hidden="true" />
          </span>

          <span className="min-w-0 flex-1">
            <span className="block text-[15px] font-semibold">{start.title}</span>
            {/* no `block` beside line-clamp-2: the clamp works by setting
                display to -webkit-box, and `block` overrides it — same
                specificity, and it lands later in the stylesheet */}
            <span className="mt-0.5 line-clamp-2 text-[13px] leading-relaxed text-muted-foreground">
              {start.description || blurb(start)}
            </span>
          </span>

          <ArrowRight
            className="size-4 shrink-0 text-primary transition-transform group-hover:translate-x-0.5"
            aria-hidden="true"
          />
        </button>
      ) : null}

      {cardGroups(rest).map(({ name, pages }) => (
        <div key={name || 'ungrouped'}>
          <h2 className="mt-12 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
            {name || __('Reference', 'schemapress')}
          </h2>

          <div className="mt-4 grid gap-3.5 sm:grid-cols-2">
            {pages.map((entry) => (
              <Card key={entry.id} section={entry} />
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}

/**
 * The splash's cards, under the group each page declares.
 *
 * @param {Array} sections
 * @return {Array<{name: string, pages: Array}>} Groups, in order.
 */
function cardGroups(sections) {
  const order = []
  const byName = {}

  sections.forEach((section) => {
    const name = section.group || ''

    if (!byName[name]) {
      byName[name] = []
      order.push(name)
    }

    byName[name].push(section)
  })

  return order.map((name) => ({ name, pages: byName[name] }))
}

/**
 * One page, as a card on the splash.
 *
 * The blurb is the page's own opening sentence rather than a second description
 * kept beside it — one that would be edited half as often as the page it
 * describes, and would drift.
 *
 * @param {Object} props
 * @return {JSX.Element} The card.
 */
function Card({ section }) {
  const Icon = ICONS[section.id] || BookOpen

  return (
    <button
      type="button"
      onClick={() => {
        window.location.hash = `/docs/${section.id}`
      }}
      className="group flex h-full items-start gap-3.5 rounded-xl border border-border bg-background p-5 text-left transition-all hover:-translate-y-px hover:border-primary/50 hover:shadow-md"
    >
      <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground transition-colors group-hover:bg-primary group-hover:text-primary-foreground">
        <Icon className="size-[18px]" aria-hidden="true" />
      </span>

      <span className="min-w-0">
        <span className="flex items-center gap-1 text-[14.5px] font-semibold group-hover:text-primary">
          {section.title}
          <ChevronRight
            className="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100"
            aria-hidden="true"
          />
        </span>

        {/* two lines, always: capped so a long one cannot make its card taller
            than the rest, and floored so a short one cannot make it shorter.
            a grid of cards that are all different heights reads as a list that
            has not been finished */}
        <span className="mt-1 line-clamp-2 min-h-[3.25em] text-[13px] leading-relaxed text-muted-foreground">
          {section.description || blurb(section)}
        </span>
      </span>
    </button>
  )
}

/**
 * A page's opening sentence, as plain text.
 *
 * @param {Object} section
 * @return {string} The blurb.
 */
function blurb(section) {
  // a paragraph inside a callout is an aside, not a summary — and on a page
  // that opens with one it is the first <p> in the document
  const html = (section.html || '').replace(/<div class="sp-callout[\s\S]*?<\/div><\/div>/g, '')
  const paragraph = html.match(/<p>([\s\S]*?)<\/p>/)

  if (!paragraph) {
    return ''
  }

  const text = paragraph[1]
    .replace(/<[^>]+>/g, '')
    .replace(/\s+/g, ' ')
    .trim()

  // the first sentence, unless it runs long enough to be a paragraph of its own
  const stop = text.indexOf('. ')
  const sentence = stop > 40 ? text.slice(0, stop + 1) : text

  return sentence.length > 150 ? `${sentence.slice(0, 147).trimEnd()}…` : sentence
}

/**
 * Where to go when this page runs out.
 *
 * A reference is read in order at least once, and a page that simply stops
 * makes the reader go back to the sidebar to find out what came next. Naming
 * the neighbors costs a row and answers it.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The pager.
 */
function Pager({ sections, section }) {
  const at = sections.findIndex((candidate) => candidate.id === section.id)
  const previous = at > 0 ? sections[at - 1] : null
  const next = at > -1 && at < sections.length - 1 ? sections[at + 1] : null

  if (!previous && !next) {
    return null
  }

  return (
    <nav
      aria-label={__('Documentation pages', 'schemapress')}
      className="mt-16 grid gap-3 border-t border-border pb-24 pt-8 sm:grid-cols-2"
    >
      {previous ? <PagerLink section={previous} back /> : <span />}
      {next ? <PagerLink section={next} /> : null}
    </nav>
  )
}

/**
 * One neighbor, as a card.
 *
 * @param {Object} props
 * @return {JSX.Element} The link.
 */
function PagerLink({ section, back }) {
  const Icon = back ? ArrowLeft : ArrowRight

  return (
    <button
      type="button"
      onClick={() => {
        window.location.hash = `/docs/${section.id}`
      }}
      className={cn(
        'group flex flex-col gap-1.5 rounded-xl border border-border p-4 transition-all hover:border-primary/50 hover:bg-accent/40 hover:shadow-sm',
        back ? 'items-start text-left' : 'items-end text-right'
      )}
    >
      <span
        className={cn(
          'flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground',
          back ? 'flex-row' : 'flex-row-reverse'
        )}
      >
        <Icon className="size-3" aria-hidden="true" />
        {back ? __('Previous', 'schemapress') : __('Next', 'schemapress')}
      </span>

      <span className="text-[15px] font-semibold text-foreground group-hover:text-primary">
        {section.title}
      </span>
    </button>
  )
}

/**
 * The two states of the copy button's icon, as lucide draws them — the same
 * pair the Copyable component uses, so the gesture looks the same wherever it
 * appears.
 *
 * Markup rather than components because this bar is built as DOM: the code
 * blocks are rendered Markdown that React never sees, so there is nothing here
 * to mount an icon into.
 */
const COPY_GLYPH =
  '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>' +
  '<path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>'

const CHECK_GLYPH = '<path d="M20 6 9 17l-5-5"/>'

/**
 * Builds one inline icon.
 *
 * @param {string} glyph The paths to draw.
 * @return {SVGElement} The icon.
 */
function icon(glyph) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')

  svg.setAttribute('viewBox', '0 0 24 24')
  svg.setAttribute('fill', 'none')
  svg.setAttribute('stroke', 'currentColor')
  svg.setAttribute('stroke-width', '2')
  svg.setAttribute('stroke-linecap', 'round')
  svg.setAttribute('stroke-linejoin', 'round')
  svg.setAttribute('aria-hidden', 'true')
  svg.innerHTML = glyph

  return svg
}

/**
 * A copy button, wired to whatever it should copy at the moment it is pressed.
 *
 * The sample is read through a function rather than captured, because a tabbed
 * group has one button over several panes and what it copies is whichever one
 * is showing — which is not known when the button is built.
 *
 * @param {Function} read Returns the text to copy.
 * @return {HTMLButtonElement} The button.
 */
function copyButton(read) {
  const button = document.createElement('button')

  button.type = 'button'
  button.className = 'sp-code__copy'

  const glyph = icon(COPY_GLYPH)
  const caption = document.createElement('span')

  caption.textContent = __('Copy', 'schemapress')
  button.append(glyph, caption)

  button.addEventListener('click', () => {
    // copyText, not navigator.clipboard directly: the Clipboard API needs a
    // secure context and a local install over plain http is exactly where it is
    // absent. this button used to reach for it unguarded and threw on those
    // machines — see src/ui/copyable.js for the fallback
    copyText(read()).then((ok) => {
      if (!ok) {
        caption.textContent = __('Press ⌘C', 'schemapress')

        return
      }

      glyph.innerHTML = CHECK_GLYPH
      caption.textContent = __('Copied', 'schemapress')
      button.dataset.copied = 'true'

      window.setTimeout(() => {
        glyph.innerHTML = COPY_GLYPH
        caption.textContent = __('Copy', 'schemapress')
        delete button.dataset.copied
      }, 1600)
    })
  })

  return button
}

/**
 * Gives every code block a bar with its language and a copy button.
 *
 * Done to the rendered HTML rather than in the Markdown, because the Markdown
 * is the source read in the repository too — a copy button is a property of
 * this screen, not of the documentation.
 *
 * @param {Object} root    Ref to the screen's root element.
 * @param {Object} section The page being shown.
 * @return {void}
 */
function useCodeChrome(root, section) {
  useEffect(() => {
    const blocks = root.current?.querySelectorAll('.sp-prose pre') || []

    blocks.forEach((block) => {
      if (block.parentElement?.classList.contains('sp-code')) {
        return
      }

      const code = block.querySelector('code')
      const language = (code?.className.match(/language-(\w+)/) || [])[1] || ''

      // before the copy button is wired: highlighting rewrites the code
      // element's contents into token spans, and textContent survives that
      // unchanged, so the button still copies the sample and not the markup
      if (code && Prism.languages[language]) {
        Prism.highlightElement(code)
      }

      const shell = document.createElement('div')
      shell.className = 'sp-code'

      const bar = document.createElement('div')
      bar.className = 'sp-code__bar'

      const label = document.createElement('span')
      label.className = 'sp-code__lang'
      label.textContent = LANGUAGES[language] || language || __('Code', 'schemapress')

      bar.append(
        label,
        copyButton(() => code?.textContent || '')
      )
      block.parentNode?.insertBefore(shell, block)
      shell.append(bar, block)
    })

    buildTabs(root.current)
  }, [root, section])
}

/**
 * Turns each `:::tabs` group into a tab strip over its panes.
 *
 * Built here rather than shipped as markup because it is behavior, and the
 * same Markdown is read in the repository where three stacked code blocks is
 * exactly the right rendering. Until this runs the panes are all visible, so a
 * failure degrades to that rather than to nothing.
 *
 * @param {Element|null} scope
 * @return {void}
 */
function buildTabs(scope) {
  const groups = scope?.querySelectorAll('.sp-tabs') || []

  groups.forEach((group) => {
    if (group.querySelector('.sp-tabs__strip')) {
      return
    }

    const panes = [...group.querySelectorAll('.sp-tab')]

    if (panes.length < 2) {
      return
    }

    const strip = document.createElement('div')
    strip.className = 'sp-tabs__strip'
    strip.setAttribute('role', 'tablist')

    const tabs = panes.map((pane, index) => {
      const tab = document.createElement('button')

      tab.type = 'button'
      tab.className = 'sp-tabs__tab'
      tab.textContent = pane.dataset.label || ''
      tab.setAttribute('role', 'tab')

      tab.addEventListener('click', () => {
        panes.forEach((other, at) => {
          other.hidden = at !== index
        })

        tabs.forEach((other, at) => {
          other.setAttribute('aria-selected', String(at === index))
        })
      })

      return tab
    })

    tabs.forEach((tab, index) => {
      tab.setAttribute('aria-selected', String(index === 0))
      strip.append(tab)
    })

    // each pane arrived with a bar of its own, which put the copy button on a
    // second row directly under the tabs and repeated the language the tab had
    // already named. one header: tabs on the left, one copy button on the
    // right, copying whichever pane is showing when it is pressed
    panes.forEach((pane) => pane.querySelector('.sp-code__bar')?.remove())

    strip.append(
      copyButton(() => {
        const showing = panes.find((pane) => !pane.hidden)

        return showing?.querySelector('pre code')?.textContent || ''
      })
    )

    panes.forEach((pane, index) => {
      pane.hidden = index !== 0
    })

    group.prepend(strip)
  })
}

/**
 * Display names for the languages the documentation actually uses.
 */
const LANGUAGES = {
  php: 'PHP',
  twig: 'Twig',
  js: 'JavaScript',
  javascript: 'JavaScript',
  json: 'JSON',
  http: 'HTTP',
  bash: 'Shell',
  sh: 'Shell',
  html: 'HTML',
  css: 'CSS',
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
function Link({ id, label, current }) {
  return (
    <button
      type="button"
      onClick={() => {
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      }}
      className={cn(
        '-ml-px block w-full border-l py-1.5 pl-3.5 pr-2 text-left text-[12.5px] leading-snug transition-colors',
        current === id
          ? 'border-primary font-medium text-foreground'
          : 'border-transparent text-muted-foreground hover:text-foreground'
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
 * the reader otherwise loses their place.
 *
 * @param {Object} section
 * @return {string} The heading's id.
 */
function useCurrentHeading(section) {
  const [current, setCurrent] = useState('')

  useEffect(() => {
    const ids = (section?.headings || []).map((heading) => heading.id)

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
      { rootMargin: '-64px 0px -70% 0px' }
    )

    headings.forEach((heading) => observer.observe(heading))

    return () => observer.disconnect()
  }, [section])

  return current
}
