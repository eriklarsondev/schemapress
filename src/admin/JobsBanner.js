/**
 * What the site is still working through.
 *
 * Rebuilding a filter index, erasing a collection and minting identifiers all
 * used to happen inside the request that asked for them, which on a collection
 * of any size did not finish. They are jobs now, worked through in the
 * background — which is the right answer and introduces a new way to be
 * confusing: you rename a field, the save returns immediately, and filtering by
 * that field gives the wrong answer for the next minute with nothing on screen
 * explaining why.
 *
 * So the work says it is happening. The banner is only present while something
 * is queued, and disappears on its own when the queue drains.
 */

import { useEffect, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Loader2 } from 'lucide-react'
import { api } from '../shared/api'
import { queuedJobs } from '../shared/settings'

/**
 * How often to ask, while something is running.
 *
 * Four seconds: fast enough that a short job's progress bar moves, slow enough
 * that a long one does not make a request a second for an hour.
 */
const INTERVAL = 4000

/**
 * What each kind of job is called while it runs.
 *
 * A function rather than a map of strings so the labels are translated when the
 * banner renders rather than when the module is first evaluated.
 *
 * @param {string} job
 * @return {string} The description.
 */
function describe(job) {
  const names = {
    reindex: __('Rebuilding filters', 'schemapress'),
    purge: __('Deleting a collection', 'schemapress'),
    backfill: __('Preparing entries', 'schemapress'),
  }

  return names[job] || __('Working', 'schemapress')
}

/**
 * The queue, while there is one.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The banner, or nothing.
 */
export function JobsBanner({ onFinished }) {
  // seeded from what PHP put on the page, so a reindex started before a reload
  // is still visible after it rather than appearing to have vanished
  const [jobs, setJobs] = useState(queuedJobs)

  useEffect(() => {
    if (jobs.length === 0) {
      return undefined
    }

    let live = true

    const timer = setInterval(() => {
      api
        .jobs()
        .then((result) => {
          if (!live) {
            return
          }

          const next = result.jobs || []

          // the queue emptying is worth telling the screen about: a listing
          // filtered by a field whose index was being rebuilt has been showing
          // the wrong rows, and now would be the time to ask again
          if (next.length === 0 && jobs.length > 0) {
            onFinished?.()
          }

          setJobs(next)
        })
        // a failed poll is not worth a message. the work is happening on the
        // server whatever this request did, and the banner going quiet is a
        // smaller wrong than an error about a progress indicator
        .catch(() => {})
    }, INTERVAL)

    return () => {
      live = false
      clearInterval(timer)
    }
  }, [jobs.length, onFinished])

  if (jobs.length === 0) {
    return null
  }

  return (
    <div className="flex flex-col gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2.5">
      {jobs.map((job) => {
        const done = Math.min(job.done, job.total)
        const percent = job.total > 0 ? Math.round((done / job.total) * 100) : 0

        return (
          <div key={job.id} className="flex items-center gap-3">
            <Loader2 className="size-3.5 shrink-0 animate-spin text-muted-foreground" />

            <span className="text-[13px] font-medium">{describe(job.job)}</span>

            <span className="text-[12px] text-muted-foreground">
              {job.total > 0
                ? sprintf(
                    /* translators: 1: entries handled so far, 2: entries in total */
                    __('%1$d of %2$d', 'schemapress'),
                    done,
                    job.total,
                  )
                : __('starting…', 'schemapress')}
            </span>

            {/* a real progress element rather than a styled div: it announces
                itself to a screen reader as progress, with a value */}
            <progress
              value={done}
              max={Math.max(1, job.total)}
              aria-label={describe(job.job)}
              className="ml-auto h-1.5 w-32 overflow-hidden rounded-full [&::-webkit-progress-bar]:bg-border [&::-webkit-progress-value]:bg-primary [&::-moz-progress-bar]:bg-primary"
            >
              {percent}%
            </progress>
          </div>
        )
      })}
    </div>
  )
}
