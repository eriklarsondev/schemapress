/**
 * The page index — the entry point to the guided editor.
 *
 * Every page is listed, bound or not, and each row states how far its setup
 * has got. That is the whole navigation model: pick a page, continue where it
 * left off.
 */

import { useState } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import { Search, ArrowRight, FileText, ExternalLink } from 'lucide-react'
import { api } from '../../shared/api'
import { useAsync } from '../useAsync'
import { Button, Input, Alert, Loading, Empty, Badge, Card, cn } from '../../ui'

/**
 * Describes how far a page's setup has progressed.
 *
 * @param {Object} page
 * @return {{label: string, variant: string, cta: string}} The status.
 */
function statusOf(page) {
  // a page can reach a schema without a template, so the schema — not the
  // template — is what says whether setup has happened
  if (!page.schema) {
    return page.template
      ? {
          label: __('Needs a schema', 'schemapress'),
          variant: 'warning',
          cta: __('Continue', 'schemapress')
        }
      : {
          label: __('Not set up', 'schemapress'),
          variant: 'outline',
          cta: __('Set up', 'schemapress')
        }
  }

  if (page.section_count === 0) {
    return {
      label: __('No content', 'schemapress'),
      variant: 'warning',
      cta: __('Add content', 'schemapress')
    }
  }

  return {
    label: sprintf(
      /* translators: %d: number of sections */
      _n('%d section', '%d sections', page.section_count, 'schemapress'),
      page.section_count
    ),
    variant: 'success',
    cta: __('Edit', 'schemapress')
  }
}

/**
 * Page list.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function PagesView({ navigate }) {
  const [search, setSearch] = useState('')
  const { data: pages, error, loading } = useAsync(() => api.pages(search), [search])

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold tracking-tight">{__('Pages', 'schemapress')}</h2>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {__(
              'Choose a page to give it a template, define its schema and fill in content.',
              'schemapress'
            )}
          </p>
        </div>

        <div className="relative w-64 max-w-full">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            className="pl-8"
            placeholder={__('Search pages…', 'schemapress')}
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
      </div>

      {error ? <Alert variant="error">{error}</Alert> : null}

      {loading ? <Loading label={__('Loading pages…', 'schemapress')} /> : null}

      {!loading && (pages || []).length === 0 ? (
        <Empty
          icon={FileText}
          title={__('No pages found', 'schemapress')}
          description={__(
            'Create a page in WordPress first, then return here to give it a structure.',
            'schemapress'
          )}
        />
      ) : null}

      <div className="flex flex-col gap-1.5">
        {(pages || []).map((page) => {
          const status = statusOf(page)

          return (
            <Card
              key={page.id}
              className={cn(
                'group flex items-center gap-3 px-4 py-3 transition-colors hover:border-ring/30'
              )}
            >
              <button
                type="button"
                onClick={() => navigate('pages', page.id)}
                className="flex min-w-0 flex-1 items-center gap-3 text-left"
              >
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-2">
                    <span className="truncate text-[13px] font-medium">{page.title}</span>
                    {page.status !== 'publish' ? (
                      <Badge variant="outline">{page.status}</Badge>
                    ) : null}
                  </span>
                  <span className="mt-1 flex flex-wrap items-center gap-1.5">
                    <Badge variant="mono">/{page.slug}</Badge>
                    {page.template ? <Badge variant="mono">{page.template}</Badge> : null}
                    {page.schema ? <Badge>{page.schema.title}</Badge> : null}
                    {page.source === 'page' ? (
                      <Badge variant="outline">{__('page-only schema', 'schemapress')}</Badge>
                    ) : null}
                  </span>
                </span>
              </button>

              <Badge variant={status.variant}>{status.label}</Badge>

              <Button variant="ghost" size="icon-sm" asChild>
                <a
                  href={page.view_link}
                  target="_blank"
                  rel="noreferrer"
                  aria-label={__('View page', 'schemapress')}
                >
                  <ExternalLink />
                </a>
              </Button>

              <Button size="sm" variant="outline" onClick={() => navigate('pages', page.id)}>
                {status.cta}
                <ArrowRight />
              </Button>
            </Card>
          )
        })}
      </div>
    </div>
  )
}
