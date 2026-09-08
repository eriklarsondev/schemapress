/**
 * What this site publishes over HTTP.
 *
 * A master switch over the REST Content API, and under it every collection with
 * its own pair. Those pairs are the same switches as on each collection's
 * settings dialog, stored in the same place — what this screen adds is seeing
 * them together, because deciding what a site publishes is one decision about
 * all of them and making it through eleven dialogs is how one gets missed.
 *
 * The master switch covers ONE of the three ways this plugin delivers content.
 * PHP and Twig are how a theme renders a page: they run on the server, never
 * leave it, and are always available — switching them off would mean switching
 * the site off. REST is the only surface where "off" means anything, so it is
 * the only one with a switch.
 *
 * With it off the collection cards go inert rather than disappearing. What each
 * collection had chosen still matters — it is what comes back when the API is
 * turned on again — so it stays on screen, greyed, saying so.
 */

import { useState } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import { List, FileSearch, Globe, Lock, Code2, ShieldCheck } from 'lucide-react'
import { Card, CardBody, Button, Switch, Alert, Badge, ConfirmDialog, Copyable, cn } from '../../ui'
import { api } from '../../shared/api'
import { site, setSite } from '../../shared/settings'

/**
 * The two shapes of read, and what each one gives away.
 */
const READS = [
  {
    key: 'list',
    icon: List,
    label: __('Read many', 'schemapress'),
  },
  {
    key: 'single',
    icon: FileSearch,
    label: __('Read one', 'schemapress'),
  },
]

/**
 * Reads a stored setting that was one boolean before it was a pair.
 *
 * @param {*} value
 * @return {{list: boolean, single: boolean}} The pair.
 */
function asPair(value) {
  if (value && typeof value === 'object') {
    return { list: Boolean(value.list), single: Boolean(value.single) }
  }

  return { list: Boolean(value), single: Boolean(value) }
}

/**
 * Every collection's pair, keyed by id.
 *
 * @param {Array} types
 * @return {Object} The pairs.
 */
function pairsOf(types) {
  const pairs = {}

  types.forEach((type) => {
    pairs[type.id] = asPair(type.publicApi)
  })

  return pairs
}

/**
 * The settings screen.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function SettingsView({ types = [], onSaved }) {
  const [rest, setRest] = useState(() => site().restApi)
  const [collections, setCollections] = useState(() => pairsOf(types))
  const [saved, setSaved] = useState(() =>
    JSON.stringify({ restApi: site().restApi, collections: pairsOf(types) }),
  )
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  // '' | 'rest-on' | 'rest-off' | 'save'
  const [confirming, setConfirming] = useState('')

  const dirty = JSON.stringify({ restApi: rest, collections }) !== saved
  const before = JSON.parse(saved)

  /**
   * Names one shape of read on one collection, for a dialog's list.
   *
   * @param {Object} type
   * @param {Object} read
   * @return {string} "Team Members — read many".
   */
  const name = (type, read) =>
    `${type.pluralLabel || type.label} — ${read.label.toLowerCase()}`

  // what pressing Save would newly expose. the master switch is NOT counted
  // here — it asks for itself the moment it is flipped, and counting it again
  // would mean two dialogs for one decision. so this is only the collections
  // whose own switch was opened, and only while the API is on to serve them.
  //
  // only the opening direction is worth stopping for: closing something is the
  // safe way to be wrong, and a confirmation on it would only train people
  // through the dialog
  const opening = rest
    ? types.flatMap((type) =>
        READS.filter(
          (read) =>
            collections[type.id]?.[read.key] && !before.collections[type.id]?.[read.key],
        ).map((read) => name(type, read)),
      )
    : []

  /**
   * Stores the master switch and every collection's pair, in one request.
   *
   * @return {void}
   */
  const save = () => {
    setConfirming('')
    setBusy(true)
    setError('')

    api
      .saveSettings({ restApi: rest, collections })
      .then((result) => {
        const stored = pairsOf(result.types || [])

        // the module holds it too, so a collection's own settings dialog opened
        // after this reads the new value rather than the one the page booted
        // with
        setSite(result.settings)
        setRest(result.settings.restApi)
        setCollections(stored)
        setSaved(JSON.stringify({ restApi: result.settings.restApi, collections: stored }))
        setBusy(false)
        onSaved?.()
      })
      .catch((failure) => {
        setBusy(false)
        setError(failure.message)
      })
  }

  /**
   * Flips one collection's switch.
   *
   * @param {number}  id
   * @param {string}  key
   * @param {boolean} next
   * @return {void}
   */
  const toggle = (id, key, next) =>
    setCollections((state) => ({ ...state, [id]: { ...state[id], [key]: next } }))

  return (
    <div className="mx-auto flex w-full max-w-4xl flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-[20px] font-semibold tracking-tight">
            {__('Settings', 'schemapress')}
          </h1>
          <p className="mt-0.5 text-[13px] text-muted-foreground">
            {__('What this site publishes over HTTP.', 'schemapress')}
          </p>
        </div>

        <Button
          size="sm"
          disabled={busy || !dirty}
          onClick={() => (opening.length > 0 ? setConfirming('save') : save())}
        >
          {busy ? __('Saving…', 'schemapress') : __('Save changes', 'schemapress')}
        </Button>
      </header>

      {error ? <Alert variant="warning">{error}</Alert> : null}

      <Card>
        <CardBody className="flex items-start gap-3">
          <span
            className={cn(
              'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
              rest ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
            )}
          >
            {rest ? <Globe className="size-4" /> : <Lock className="size-4" />}
          </span>

          <div className="min-w-0 flex-1">
            <p className="text-[14px] font-semibold">{__('REST Content API', 'schemapress')}</p>

            <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
              {rest
                ? __(
                    'Collections can be read over HTTP. Reads are public and unauthenticated — anyone with the address gets them — and only published entries are ever served.',
                    'schemapress',
                  )
                : __(
                    'The schemapress/api namespace is not registered, so it does not appear under /wp-json/ and every address below it answers 404. What each collection had chosen is kept for when this is turned back on.',
                    'schemapress',
                  )}
            </p>
          </div>

          {/* both directions ask. one turns a public API on, the other takes
              a running one down — and neither is a thing to do by brushing
              past a toggle */}
          <Switch
            checked={rest}
            aria-label={__('REST Content API', 'schemapress')}
            onChange={(next) => setConfirming(next ? 'rest-on' : 'rest-off')}
          />
        </CardBody>
      </Card>

      {/* the other two surfaces, listed so the master switch above cannot read
          as "turn the plugin off". they have no switch because there is nothing
          to switch: they run on the server and never leave it */}
      <Card>
        <CardBody className="flex items-start gap-3">
          <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <Code2 className="size-4" />
          </span>

          <div className="min-w-0">
            <p className="flex items-center gap-2 text-[14px] font-semibold">
              {__('PHP and Twig', 'schemapress')}
              <Badge variant="outline">{__('always on', 'schemapress')}</Badge>
            </p>

            <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
              {__(
                'How your theme reads content — SchemaPress::collection() and sp_collection(). They run on the server and never go over HTTP, so the switch above does not touch them and a page the site renders itself is unaffected either way.',
                'schemapress',
              )}
            </p>
          </div>
        </CardBody>
      </Card>

      <div className="mt-1">
        <h2 className="px-1 text-[14px] font-semibold">{__('Collections', 'schemapress')}</h2>
        <p className="mt-0.5 px-1 text-[12px] text-muted-foreground">
          {rest
            ? __(
                'Each one answers independently, and publishes nothing until its own switch is on.',
                'schemapress',
              )
            : __(
                'Kept as they were. These take effect again when the API above is turned back on.',
                'schemapress',
              )}
        </p>
      </div>

      {types.length === 0 ? (
        <Card>
          <CardBody>
            <p className="text-[13px] italic text-muted-foreground">
              {__('No collections yet.', 'schemapress')}
            </p>
          </CardBody>
        </Card>
      ) : (
        <div className="grid gap-3 lg:grid-cols-2">
          {types.map((type) => (
            <CollectionCard
              key={type.id}
              type={type}
              pair={collections[type.id] || { list: false, single: false }}
              disabled={!rest}
              onToggle={(key, next) => toggle(type.id, key, next)}
            />
          ))}
        </div>
      )}

      <p className="flex items-start gap-2 px-1 text-[12px] leading-relaxed text-muted-foreground">
        <ShieldCheck className="mt-0.5 size-3.5 shrink-0" />
        <span>
          {__(
            'Only published entries are ever served. Drafts, unpublished edits and anything in the trash are never readable over the API, whatever these switches say.',
            'schemapress',
          )}
        </span>
      </p>

      {confirming === 'rest-on' ? (
        <ConfirmDialog
          open
          destructive={false}
          onOpenChange={(next) => !next && setConfirming('')}
          title={__('Turn on the REST Content API?', 'schemapress')}
          description={__(
            'The schemapress/api namespace returns to the WordPress REST API, and collections that publish themselves become readable over HTTP.',
            'schemapress',
          )}
          confirmLabel={__('Turn it on', 'schemapress')}
          onConfirm={() => {
            setRest(true)
            setConfirming('')
          }}
        />
      ) : null}

      {confirming === 'rest-off' ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setConfirming('')}
          title={__('Turn off the REST Content API?', 'schemapress')}
          description={__(
            'The schemapress/api namespace is removed from the WordPress REST API, so anything fetching content over HTTP will break. Your theme is unaffected, and each collection keeps its switches.',
            'schemapress',
          )}
          confirmLabel={__('Turn it off', 'schemapress')}
          onConfirm={() => {
            setRest(false)
            setConfirming('')
          }}
        />
      ) : null}

      {confirming === 'save' ? (
        <ConfirmDialog
          open
          destructive={false}
          onOpenChange={(next) => !next && setConfirming('')}
          title={__('Publish these to the API?', 'schemapress')}
          description={sprintf(
            /* translators: 1: number of reads being opened, 2: a list of them */
            _n(
              'This makes %2$s readable by anyone who knows the address, without logging in. Drafts and unpublished edits are never served.',
              'This makes %1$d things readable by anyone who knows the address, without logging in: %2$s. Drafts and unpublished edits are never served.',
              opening.length,
              'schemapress',
            ),
            opening.length,
            opening.join(', '),
          )}
          confirmLabel={__('Publish', 'schemapress')}
          onConfirm={save}
        />
      ) : null}
    </div>
  )
}

/**
 * One collection, with its own pair of switches.
 *
 * A header saying what it is, then one row per shape of read — separated by
 * rules rather than spacing, so a column of these reads as a table of the same
 * decision made repeatedly rather than as a stack of unrelated panels.
 *
 * Each row carries its OWN address when it is on. One address per card was
 * ambiguous the moment both switches were: the list URL and the single URL are
 * different things, and the point of turning one on is to fetch it from
 * somewhere.
 *
 * @param {Object} props
 * @return {JSX.Element} The card.
 */
function CollectionCard({ type, pair, disabled, onToggle }) {
  const open = pair.list || pair.single
  const name = type.pluralLabel || type.label

  // apiSlug, not the machine key: the address is the hyphenated plural, and the
  // server is what decides that rather than this screen assembling one
  const path = `/wp-json/schemapress/api/${type.apiSlug}`

  return (
    <Card
      aria-disabled={disabled || undefined}
      className={cn(
        'overflow-hidden transition-opacity',
        !open && 'border-dashed bg-muted/20',
        // inert rather than gone. what this collection had chosen is what comes
        // back when the API is switched on again, so it stays legible — greyed
        // is the difference between "not in effect" and "not decided"
        disabled && 'pointer-events-none select-none opacity-50',
      )}
    >
      {/* no status badge. the switches below are the status, and a chip saying
          "published" beside two toggles that already say so is the same fact
          twice in one card */}
      <div className="px-4 py-3">
        <p className="truncate text-[14px] font-semibold leading-tight">{name}</p>

        {/* the machine name beside the count, because the address below is built
            from it and this is where you would check it matches what a template
            asks for */}
        <p className="mt-1 flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <span className="truncate font-mono">{type.apiSlug}</span>
          <span aria-hidden="true">·</span>
          <span className="shrink-0">
            {sprintf(
              /* translators: %d: number of entries */
              _n('%d entry', '%d entries', type.entries || 0, 'schemapress'),
              type.entries || 0,
            )}
          </span>
        </p>
      </div>

      <div className="divide-y divide-border border-t border-border">
        {READS.map((read) => {
          const on = pair[read.key]
          const suffix = read.key === 'single' ? '/:id' : ''

          return (
            <div key={read.key} className="px-4 py-2.5">
              <div className="flex items-center justify-between gap-3">
                <span className="flex min-w-0 items-center gap-2">
                  <read.icon
                    className={cn(
                      'size-3.5 shrink-0',
                      on ? 'text-foreground' : 'text-muted-foreground/60',
                    )}
                  />
                  <span
                    className={cn(
                      'truncate text-[13px]',
                      on ? 'font-medium' : 'text-muted-foreground',
                    )}
                  >
                    {read.label}
                  </span>
                </span>

                <Switch
                  checked={on}
                  disabled={disabled}
                  aria-label={sprintf(
                    /* translators: 1: a shape of read, 2: the collection's name */
                    __('%1$s — %2$s', 'schemapress'),
                    read.label,
                    name,
                  )}
                  onChange={(next) => onToggle(read.key, next)}
                />
              </div>

              {/* shown as a path, copied as the whole address. every card on
                  this screen shares an origin, so printing it is a prefix that
                  pushes the part you were reading past the truncation */}
              {on ? (
                <Copyable
                  className="mt-2 w-full"
                  display={`${path}${suffix}`}
                  value={`${window.location.origin}${path}${suffix}`}
                />
              ) : null}
            </div>
          )
        })}
      </div>
    </Card>
  )
}
