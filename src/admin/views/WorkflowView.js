/**
 * The guided editor for one page.
 *
 * Two steps, not three. Choosing a template is a real decision with real
 * consequences — it is what other pages will share — so it stays its own step.
 * Defining components and filling them in is one activity, so it is one step.
 */

import { useState, useEffect } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { ArrowLeft, ExternalLink } from 'lucide-react'
import { api } from '../../shared/api'
import { useAsync } from '../useAsync'
import { Button, Alert, Loading, Badge } from '../../ui'
import { Stepper } from '../workflow/Stepper'
import { TemplateStep } from '../workflow/TemplateStep'
import { BuildStep } from '../workflow/BuildStep'

/**
 * Page workflow.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function WorkflowView({ postId, navigate, settings }) {
  const { data, error, loading, reload } = useAsync(() => api.workflow(postId), [postId])
  const [step, setStep] = useState(null)

  // the server decides where to land; once the user has moved, their choice
  // wins until the page is reopened
  useEffect(() => {
    if (data && step === null) {
      setStep(data.step === 'schema' ? 'build' : data.step === 'content' ? 'build' : 'template')
    }
  }, [data, step])

  if (loading) {
    return <Loading label={__('Loading page…', 'schemapress')} />
  }

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Alert variant="error">{error}</Alert>
        <div>
          <Button variant="outline" onClick={() => navigate('pages')}>
            <ArrowLeft />
            {__('Back to pages', 'schemapress')}
          </Button>
        </div>
      </div>
    )
  }

  const direct = data.source === 'page'
  const componentCount = data.schema?.definition?.sections?.length || 0

  const steps = [
    {
      key: 'template',
      label: __('Template', 'schemapress'),
      summary: data.template
        ? data.template.label
        : direct
          ? __('Not used', 'schemapress')
          : __('Not chosen', 'schemapress'),
      // a directly bound page has deliberately opted out of a template, so the
      // step counts as settled rather than outstanding
      complete: Boolean(data.template) || direct
    },
    {
      key: 'build',
      label: __('Build the page', 'schemapress'),
      summary: data.schema
        ? `${data.content.sections.length} ${__('placed', 'schemapress')} · ${componentCount} ${__(
            'components',
            'schemapress'
          )}`
        : __('Not started', 'schemapress'),
      complete: data.content.sections.length > 0
    }
  ]

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-start gap-3">
        <Button
          size="icon"
          variant="ghost"
          aria-label={__('Back to pages', 'schemapress')}
          onClick={() => navigate('pages')}
        >
          <ArrowLeft />
        </Button>

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h2 className="truncate text-lg font-semibold tracking-tight">{data.post.title}</h2>
            {data.post.status !== 'publish' ? (
              <Badge variant="warning">{data.post.status}</Badge>
            ) : null}
          </div>
          <p className="mt-0.5 text-[12px] text-muted-foreground">
            <code className="rounded bg-muted px-1 py-0.5">/{data.post.slug}</code>
          </p>
        </div>

        <Button variant="ghost" size="sm" asChild>
          <a href={data.post.edit_link} target="_blank" rel="noreferrer">
            {__('WordPress editor', 'schemapress')}
            <ExternalLink />
          </a>
        </Button>
      </div>

      <div className="rounded-lg border border-border bg-muted/40 p-1.5">
        <Stepper
          steps={steps}
          current={step}
          // building is always reachable — it is also where a page opts out of
          // templates entirely
          reached={1}
          onSelect={setStep}
        />
      </div>

      {step === 'template' ? (
        <TemplateStep
          postId={postId}
          current={data.template}
          onDone={() => {
            reload()
            setStep('build')
          }}
          onSkip={() => setStep('build')}
        />
      ) : null}

      {step === 'build' ? (
        <BuildStep
          postId={postId}
          template={data.template}
          schema={data.schema}
          source={data.source}
          content={data.content}
          fieldTypes={settings.fieldTypes || []}
          // deliberately not reloading after each autosave: refetching would
          // reset the editor's state from the server mid-edit. the stepper's
          // counts go briefly stale, which costs nothing
          onBack={() => setStep('template')}
        />
      ) : null}
    </div>
  )
}
