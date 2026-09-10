/**
 * Getting a schema out of this site, and back into another one.
 *
 * A collection's definition lives in one meta row of one database, which is a
 * fine place to keep it and — until this screen — the only place it was. So a
 * schema could not be committed to a repository, moved from staging to
 * production, or used to seed a fresh checkout. Every developer on a project
 * started from an empty SchemaPress and rebuilt the collections by hand, which
 * is both tedious and wrong: a field key is derived from its label, and labels
 * get retyped.
 *
 * The file is an export rather than the source of truth. The database stays
 * authoritative — these definitions are edited through a UI, by people who do
 * not have the repository — and this is how a copy of it travels.
 */

import { useRef, useState } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import { Download, Upload, FileJson, AlertTriangle } from 'lucide-react'
import { Card, CardBody, Button, Checkbox, Alert, Segmented, Field, ConfirmDialog } from '../../ui'
import { api } from '../../shared/api'

/**
 * Hands the browser a file it did not have to fetch twice.
 *
 * The export comes back as JSON in a response rather than as a download, so it
 * is turned into one here — which also means the filename is the server's
 * choice, and names the site and the day it was taken.
 *
 * @param {string} filename
 * @param {Object} payload
 * @return {void}
 */
function download(filename, payload) {
  const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)

  // the object URL holds the blob in memory until it is let go, and an admin
  // exporting a few times a day would otherwise accumulate them for the life of
  // the tab
  URL.revokeObjectURL(url)
}

/**
 * Export and import.
 *
 * @param {Object} props
 * @return {JSX.Element} The panel.
 */
export function DataPanel({ types = [], onImported }) {
  const [withEntries, setWithEntries] = useState(false)
  const [chosen, setChosen] = useState([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const [mode, setMode] = useState('merge')
  const [restoreEntries, setRestoreEntries] = useState(false)
  const [pending, setPending] = useState(null)

  const input = useRef(null)

  /**
   * Fetches the export and hands it over as a file.
   *
   * @return {void}
   */
  const runExport = () => {
    setBusy(true)
    setError('')
    setNotice('')

    api
      .export({
        types: chosen.join(','),
        entries: withEntries ? 1 : '',
      })
      .then((result) => {
        download(result.filename, result.export)
        setNotice(__('Export downloaded.', 'schemapress'))
      })
      .catch((failure) => setError(failure.message))
      .finally(() => setBusy(false))
  }

  /**
   * Reads the chosen file and holds it until the import is confirmed.
   *
   * Parsed here rather than on the server so an unreadable file is refused
   * before anything is sent, and so the dialog can say what is actually in it —
   * "3 collections" is a different decision from "31 collections".
   *
   * @param {File} file
   * @return {void}
   */
  const read = (file) => {
    setError('')
    setNotice('')

    file
      .text()
      .then((text) => {
        const payload = JSON.parse(text)

        if (!payload || typeof payload !== 'object' || !payload.schemapress) {
          throw new Error(__('That file is not a SchemaPress export.', 'schemapress'))
        }

        setPending({ payload, name: file.name })
      })
      .catch((failure) =>
        setError(
          failure instanceof SyntaxError
            ? __('That file is not valid JSON.', 'schemapress')
            : failure.message
        )
      )
      .finally(() => {
        // so choosing the same file twice in a row fires a change event the
        // second time — without this, fixing a file and re-picking it does
        // nothing and looks like the button is broken
        if (input.current) {
          input.current.value = ''
        }
      })
  }

  /**
   * Sends the held document.
   *
   * @return {void}
   */
  const runImport = () => {
    setBusy(true)
    setError('')

    api
      .import(pending.payload, { mode, entries: restoreEntries })
      .then((result) => {
        const report = result.report
        const created = report.collections.filter((one) => one.created).length

        setNotice(
          sprintf(
            /* translators: 1: collections created, 2: collections updated, 3: entries */
            __('%1$d created, %2$d updated, %3$d entries.', 'schemapress'),
            created,
            report.collections.length - created,
            report.entries
          )
        )

        // what happened and what to look at are separate messages. the import
        // went through either way; these are the parts of it — an image this
        // site does not have, a field whose type changed, an entry left in the
        // trash — that somebody should check before they forget it happened
        const skipped = report.collections.flatMap((one) => one.skipped || [])
        const cautions = [
          ...(report.warnings || []),
          ...skipped.map((one) =>
            sprintf(
              /* translators: %s: why an entry was not imported */
              __('Skipped: %s', 'schemapress'),
              one.message
            )
          ),
        ]

        setError(cautions.join(' '))

        onImported?.(result)
      })
      .catch((failure) => setError(failure.message))
      .finally(() => {
        setBusy(false)
        setPending(null)
      })
  }

  const incoming = pending ? (pending.payload.collections || []).length : 0
  const carriesEntries = pending
    ? (pending.payload.collections || []).some((one) => Array.isArray(one.entries))
    : false

  return (
    <>
      {error ? <Alert variant="warning">{error}</Alert> : null}
      {notice ? <Alert variant="success">{notice}</Alert> : null}

      <div className="grid gap-3 lg:grid-cols-2">
        <Card>
          <CardBody className="flex flex-col gap-3">
            <div className="flex items-start gap-3">
              <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Download className="size-4" />
              </span>

              <div className="min-w-0">
                <p className="text-[14px] font-semibold">{__('Export', 'schemapress')}</p>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {__(
                    'A JSON document holding your collections and their fields. Commit it, diff it, or read it into another install.',
                    'schemapress'
                  )}
                </p>
              </div>
            </div>

            {types.length > 0 ? (
              <Field
                label={__('What to export', 'schemapress')}
                help={__('Nothing ticked means every collection.', 'schemapress')}
              >
                <div className="flex max-h-40 flex-col gap-1.5 overflow-y-auto rounded-md border border-border p-2">
                  {types.map((type) => (
                    <Checkbox
                      key={type.id}
                      checked={chosen.includes(type.id)}
                      label={type.pluralLabel || type.label}
                      onChange={() =>
                        setChosen((current) =>
                          current.includes(type.id)
                            ? current.filter((one) => one !== type.id)
                            : [...current, type.id]
                        )
                      }
                    />
                  ))}
                </div>
              </Field>
            ) : null}

            <Checkbox
              checked={withEntries}
              label={__('Include the entries', 'schemapress')}
              help={__(
                'The content as well as its shape. Images travel as attachment ids, which will not resolve on a site that does not have them.',
                'schemapress'
              )}
              onChange={setWithEntries}
            />

            <Button
              size="sm"
              className="self-start"
              disabled={busy || types.length === 0}
              onClick={runExport}
            >
              <Download />
              {__('Download export', 'schemapress')}
            </Button>
          </CardBody>
        </Card>

        <Card>
          <CardBody className="flex flex-col gap-3">
            <div className="flex items-start gap-3">
              <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Upload className="size-4" />
              </span>

              <div className="min-w-0">
                <p className="text-[14px] font-semibold">{__('Import', 'schemapress')}</p>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {__(
                    'Collections are matched on their machine key, so re-importing a file updates what it made rather than making it again.',
                    'schemapress'
                  )}
                </p>
              </div>
            </div>

            <Field
              label={__('If a collection already exists', 'schemapress')}
              help={
                mode === 'merge'
                  ? __(
                      'A field the file does not mention is left alone. Nothing an import does can orphan stored values.',
                      'schemapress'
                    )
                  : __(
                      'The collection becomes exactly what the file says. A field it does not mention is deleted, and the values stored under it are orphaned.',
                      'schemapress'
                    )
              }
            >
              <Segmented
                value={mode}
                onChange={setMode}
                options={[
                  { value: 'merge', label: __('Merge', 'schemapress') },
                  { value: 'replace', label: __('Replace', 'schemapress') },
                ]}
              />
            </Field>

            <input
              ref={input}
              type="file"
              accept="application/json,.json"
              className="sr-only"
              onChange={(event) => event.target.files?.[0] && read(event.target.files[0])}
            />

            <Button
              size="sm"
              variant="outline"
              className="self-start"
              disabled={busy}
              onClick={() => input.current?.click()}
            >
              <FileJson />
              {__('Choose a file…', 'schemapress')}
            </Button>

            <p className="flex items-start gap-2 text-[12px] leading-relaxed text-muted-foreground">
              <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
              <span>
                {__(
                  'An import never turns on a collection’s public API, whatever the file says. Publishing is a decision about this site.',
                  'schemapress'
                )}
              </span>
            </p>
          </CardBody>
        </Card>
      </div>

      {pending ? (
        <ConfirmDialog
          open
          destructive={mode === 'replace'}
          onOpenChange={(next) => !next && setPending(null)}
          title={sprintf(
            /* translators: %s: the chosen file's name */
            __('Import %s?', 'schemapress'),
            pending.name
          )}
          description={sprintf(
            mode === 'replace'
              ? /* translators: %d: number of collections in the file */
                _n(
                  'It holds %d collection. Any field it does not mention will be deleted from a collection that already exists, and the values stored under it orphaned.',
                  'It holds %d collections. Any field they do not mention will be deleted from collections that already exist, and the values stored under them orphaned.',
                  incoming,
                  'schemapress'
                )
              : /* translators: %d: number of collections in the file */
                _n(
                  'It holds %d collection. Existing fields it does not mention are left alone.',
                  'It holds %d collections. Existing fields they do not mention are left alone.',
                  incoming,
                  'schemapress'
                ),
            incoming
          )}
          confirmLabel={__('Import', 'schemapress')}
          onConfirm={runImport}
        >
          {carriesEntries ? (
            <Checkbox
              checked={restoreEntries}
              label={__('Restore the entries too', 'schemapress')}
              help={__(
                'An entry the destination refuses — a unique value already taken, a required field added since — is skipped rather than stopping the import.',
                'schemapress'
              )}
              onChange={setRestoreEntries}
            />
          ) : null}
        </ConfirmDialog>
      ) : null}
    </>
  )
}
