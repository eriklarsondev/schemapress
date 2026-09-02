/**
 * The thing that stops a white screen.
 *
 * An uncaught error during render unmounts the whole React tree, and what is
 * left is a blank page with the real cause only in the console. That is the
 * worst failure mode an admin screen has, because it looks identical to "the
 * plugin is broken" no matter how small the fault was.
 *
 * This catches it, keeps the rest of the app mounted, and shows what happened
 * with a way out.
 */

import { Component } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { TriangleAlert } from 'lucide-react'

export class ErrorBoundary extends Component {
  constructor(props) {
    super(props)

    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    // the console is still where a developer will look, so the detail goes
    // there in full rather than being summarised into the UI
    // eslint-disable-next-line no-console
    console.error('SchemaPress:', error, info)
  }

  render() {
    const { error } = this.state

    if (!error) {
      return this.props.children
    }

    return (
      <div className="mx-auto max-w-xl rounded-lg border border-destructive/30 bg-destructive/5 p-5">
        <div className="flex items-start gap-3">
          <TriangleAlert className="mt-0.5 size-5 shrink-0 text-destructive" />

          <div className="min-w-0 flex-1">
            <h2 className="text-[15px] font-semibold">
              {__('Something went wrong on this screen', 'schemapress')}
            </h2>

            <p className="mt-1 text-[13px] text-muted-foreground">
              {__(
                'The rest of the app is still running. The details are in the browser console.',
                'schemapress'
              )}
            </p>

            <pre className="mt-3 overflow-x-auto rounded border border-border bg-background p-2 text-[11px]">
              <code>{String(error?.message || error)}</code>
            </pre>

            <button
              type="button"
              onClick={() => this.setState({ error: null })}
              className="mt-3 rounded-md border border-border bg-background px-3 py-1.5 text-[13px] font-medium transition-colors hover:bg-accent"
            >
              {__('Try again', 'schemapress')}
            </button>
          </div>
        </div>
      </div>
    )
  }
}
