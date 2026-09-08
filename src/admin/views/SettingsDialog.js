/**
 * What a collection is, rather than what is in it.
 *
 * Its name, the note explaining what belongs here, whether entries get a
 * working copy separate from what the site serves, and whether it goes on
 * existing at all.
 *
 * Behind a button rather than a tab, because none of it is work: the tabs are
 * the three things you do to a collection every day, and this is the thing you
 * decide once and come back to twice a year.
 *
 * The machine keys are shown and not editable. They were fixed when the type
 * was created, because the singular one names the post type entries are stored
 * against: changing it would leave every existing entry addressed to a post
 * type nothing declares any more.
 */

import { useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { GitBranch, Zap, Trash2, List, FileSearch, Lock, TriangleAlert } from 'lucide-react'
import {
  Dialog,
  Card,
  CardBody,
  Field,
  Input,
  Textarea,
  Button,
  Badge,
  Alert,
  Select,
  Switch,
  Copyable,
  ConfirmDialog
} from '../../ui'
import { site } from '../../shared/settings'

/**
 * Field types that can name an entry — mirrors SchemaModel::TITLE_TYPES.
 *
 * A name is one line of text you can read in a list and put in a heading, so an
 * image cannot be one, a repeater is many things, and rich text is a document.
 * A date can: a collection that is a diary names its entries by the day.
 */
const TITLE_TYPES = [
  'text', 'textarea', 'email', 'url', 'phone', 'number', 'select', 'date', 'datetime'
]

/**
 * Field types a readable slug can be built from — mirrors
 * SchemaModel::SLUG_TYPES.
 *
 * Narrower than the ones that can name an entry: an email slugifies to
 * `ada-example-com` and a phone number to a run of digits. Both are fine names
 * and neither is an address anybody wants to read.
 */
const SLUG_TYPES = ['text', 'textarea', 'select', 'number', 'date', 'datetime']

/**
 * The two shapes of read a collection can publish, and what each one is.
 *
 * They are separate answers because they give away different amounts. Read one
 * hands over an entry to whoever already has a reference to it; read many hands
 * over the whole collection, and with it the fact of what is in there.
 */
const READS = [
  {
    key: 'list',
    icon: List,
    label: __('Read many', 'schemapress'),
    on: __(
      'Anyone can ask for every published entry, a page at a time.',
      'schemapress'
    ),
    off: __('The collection cannot be listed over HTTP.', 'schemapress')
  },
  {
    key: 'single',
    icon: FileSearch,
    label: __('Read one', 'schemapress'),
    on: __(
      'Anyone holding an entry’s id can read that entry. Ids are random, so this alone does not reveal what else is here.',
      'schemapress'
    ),
    off: __('Single entries are not served over HTTP.', 'schemapress')
  }
]

/**
 * A field's own label, for help text that names the field rather than
 * describing one in the abstract.
 *
 * @param {Array}  fields
 * @param {string} key
 * @return {string} The label.
 */
function labelFor(fields, key) {
  return fields.find((one) => one.key === key)?.label || key
}

/**
 * Reads the stored setting, which was one boolean covering both routes before
 * it was a pair.
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
 * The settings dialog.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
export function SettingsDialog({ type, fields = [], settings, onClose, onSave, onDelete }) {
  const [name, setName] = useState(type.label || '')
  const [description, setDescription] = useState(type.description || '')
  const [drafts, setDrafts] = useState(settings.draftAndPublish !== false)
  const [publicApi, setPublicApi] = useState(() => asPair(settings.publicApi))
  const [titleField, setTitleField] = useState(settings.titleField || '')
  const [slugField, setSlugField] = useState(settings.slugField || '')

  // whether the URL is being built from a different field than the name. read
  // from what is stored rather than defaulted, so a collection somebody already
  // set up that way opens with both controls showing
  const [separate, setSeparate] = useState(
    Boolean(settings.titleField) && (settings.slugField || '') !== (settings.titleField || '')
  )
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [confirming, setConfirming] = useState('')

  // whether the content API is running at all. a switch here can be on and
  // still serve nothing, and this dialog is where somebody would look first
  const ceiling = site().restApi

  const dirty =
    name.trim() !== (type.label || '') ||
    description.trim() !== (type.description || '') ||
    drafts !== (settings.draftAndPublish !== false) ||
    JSON.stringify(publicApi) !== JSON.stringify(asPair(settings.publicApi)) ||
    titleField !== (settings.titleField || '') ||
    slugField !== (settings.slugField || '')

  /**
   * The slug field that follows a chosen name field.
   *
   * Not every type that can name an entry can address one — an email slugifies
   * to `ada-example-com` — so a name the URL cannot use falls back to the random
   * ID rather than to nothing at all.
   *
   * @param {string} key
   * @return {string} The slug field to store.
   */
  const slugFor = (key) => {
    const field = fields.find((one) => one.key === key)

    return field && SLUG_TYPES.includes(field.type) ? key : ''
  }

  /**
   * Picks the field an entry is identified by, carrying the URL with it unless
   * the two have been deliberately separated.
   *
   * @param {string} key
   * @return {void}
   */
  const identifyBy = (key) => {
    setTitleField(key)

    if (!separate) {
      setSlugField(slugFor(key))
    }
  }

  /**
   * Splits the URL off from the name, or puts it back.
   *
   * @param {boolean} next
   * @return {void}
   */
  const separateUrl = (next) => {
    setSeparate(next)

    if (!next) {
      setSlugField(slugFor(titleField))
    }
  }

  /**
   * Stores the settings.
   *
   * @return {void}
   */
  const save = () => {
    setSaving(true)
    setError('')

    Promise.resolve(
      onSave({
        title: name.trim() || type.label,
        description: description.trim(),
        settings: { draftAndPublish: drafts, publicApi, titleField, slugField }
      })
    )
      .then(onClose)
      .catch((failure) => {
        setSaving(false)
        setError(failure.message)
      })
  }

  return (
    <Dialog
      open
      size="lg"
      onOpenChange={(next) => !next && onClose()}
      title={sprintf(
        /* translators: %s: the collection's name */
        __('%s settings', 'schemapress'),
        type.pluralLabel || type.label
      )}
      description={__('What this collection is, and how publishing works in it.', 'schemapress')}
      footer={
        <>
          {/* deleting sits with saving because both are decisions about the
              collection itself — but it reads as dangerous at rest and is
              pushed to the far end, so the two are never adjacent */}
          <Button
            variant="destructive-outline"
            className="mr-auto"
            onClick={() => setConfirming('delete')}
          >
            <Trash2 />
            {__('Delete type', 'schemapress')}
          </Button>

          <Button variant="outline" onClick={onClose}>
            {__('Cancel', 'schemapress')}
          </Button>

          <Button disabled={!dirty || saving} onClick={save}>
            {saving ? __('Saving…', 'schemapress') : __('Save settings', 'schemapress')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {error ? <Alert variant="warning">{error}</Alert> : null}

        {/* what the collection is called on the left, how publishing works on
            the right: one column is what you edit, the other is what you
            decide once */}
        <div className="grid items-start gap-4 lg:grid-cols-2">
          <Card>
            <CardBody className="flex flex-col gap-4">
              <Field
                label={__('Name', 'schemapress')}
                help={__('What this collection is called on screen.', 'schemapress')}
              >
                {(id) => (
                  <Input id={id} value={name} onChange={(event) => setName(event.target.value)} />
                )}
              </Field>

              <Field
                label={__('Description', 'schemapress')}
                hint={__('Optional', 'schemapress')}
                help={__('Shown under the name on this screen.', 'schemapress')}
              >
                {(id) => (
                  <Textarea
                    id={id}
                    rows={2}
                    value={description}
                    placeholder={__('The people we list on the about page.', 'schemapress')}
                    onChange={(event) => setDescription(event.target.value)}
                  />
                )}
              </Field>

              {/* ONE question, because in practice there is one answer. an
                  entry's name and the last part of its URL are the same field
                  almost every time — asking twice made two dropdowns, two
                  paragraphs of help and a URL diagram for a decision that is
                  usually "the first field". the pair is still there underneath,
                  behind a switch, for the collection that genuinely wants them
                  different */}
              <div className="flex flex-col gap-4 border-t border-border pt-4">
                {/* named after what it DOES. it was "Identified by", which is
                    a word for the mechanism rather than a description of the
                    result — the result is that a row in the entries table reads
                    "Ada Lovelace" instead of "Untitled", and the help text says
                    exactly that using the field the collection actually has */}
                <Field
                  label={__('Name entries by', 'schemapress')}
                  help={
                    titleField
                      ? sprintf(
                          /* translators: %s: the name of the chosen field */
                          __(
                            'An entry is listed under its %s instead of “Untitled”, and that is what the API reports and what goes in its URL.',
                            'schemapress'
                          ),
                          labelFor(fields, titleField)
                        )
                      : __(
                          'Entries are listed as “Untitled” and addressed by a random ID. Right for a collection you would never look up by name — a set of settings, a list of links.',
                          'schemapress'
                        )
                  }
                >
                  {(id) => (
                    <Select
                      id={id}
                      value={titleField}
                      onChange={identifyBy}
                      options={[
                        { value: '', label: __('No name', 'schemapress') },
                        ...fields
                          .filter((field) => TITLE_TYPES.includes(field.type))
                          .map((field) => ({ value: field.key, label: field.label || field.key }))
                      ]}
                    />
                  )}
                </Field>

                {titleField ? (
                  <Switch
                    label={__('Use a different field for the URL', 'schemapress')}
                    checked={separate}
                    onChange={separateUrl}
                  />
                ) : null}

                {titleField && separate ? (
                  <Field
                    label={__('URL uses', 'schemapress')}
                    help={__(
                      'Fixed when the entry is first published, so renaming it later does not break a link somebody already has.',
                      'schemapress'
                    )}
                  >
                    {(id) => (
                      <Select
                        id={id}
                        value={slugField}
                        onChange={setSlugField}
                        options={[
                          { value: '', label: __('A random ID', 'schemapress') },
                          ...fields
                            .filter((field) => SLUG_TYPES.includes(field.type))
                            .map((field) => ({ value: field.key, label: field.label || field.key }))
                        ]}
                      />
                    )}
                  </Field>
                ) : null}
              </div>

              <div className="flex flex-col gap-1.5 border-t border-border pt-4">
                <p className="text-[13px] font-medium leading-none">
                  {__('Machine keys', 'schemapress')}
                </p>

                <p className="flex flex-wrap items-center gap-1.5">
                  <Badge variant="mono">{type.key}</Badge>
                  {type.plural && type.plural !== type.key ? (
                    <Badge variant="mono">{type.plural}</Badge>
                  ) : null}
                </p>

                <p className="text-[12px] text-muted-foreground">
                  {__('What templates ask for. Fixed when the collection was made.', 'schemapress')}
                </p>
              </div>
            </CardBody>
          </Card>

          <Card>
            <CardBody className="flex flex-col gap-3">
              <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <p className="flex items-center gap-1.5 text-[13px] font-medium">
                    {drafts ? (
                      <GitBranch className="size-3.5 text-muted-foreground" />
                    ) : (
                      <Zap className="size-3.5 text-muted-foreground" />
                    )}
                    {__('Draft and publish', 'schemapress')}
                  </p>

                  <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                    {drafts
                      ? __(
                          'Saving writes a draft. Nothing reaches the site until you publish it, so a half-finished edit to a live entry stays private.',
                          'schemapress'
                        )
                      : __(
                          'Saving publishes. There is one copy of each entry and it is always the one the site is serving.',
                          'schemapress'
                        )}
                  </p>
                </div>

                {/* the kit's Switch takes onChange, not Radix's onCheckedChange */}
                <Switch
                  checked={drafts}
                  aria-label={__('Draft and publish', 'schemapress')}
                  onChange={(next) => (next ? setDrafts(true) : setConfirming('drafts'))}
                />
              </div>

              {/* the other decision about who sees this collection, so it sits
                  with publishing rather than with the name. turning one ON is
                  the direction that gives something away, so that is the one
                  that asks — the opposite of the switch above it */}
              <div className="border-t border-border pt-4">
                <p className="text-[13px] font-medium">{__('Public API', 'schemapress')}</p>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {__(
                    'What this collection answers over HTTP, with no key. Drafts and unpublished edits are never served either way.',
                    'schemapress'
                  )}
                </p>

                {/* the switches below would otherwise say yes and serve
                    nothing, and this dialog is where somebody would look first */}
                {!ceiling ? (
                  <p className="mt-2 flex items-start gap-1.5 text-[12px] leading-relaxed text-amber-700">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                      {__(
                        'The REST Content API is switched off for this site, so the schemapress/api namespace is not registered and nothing here is being served. Turn it on in SchemaPress Settings.',
                        'schemapress'
                      )}
                    </span>
                  </p>
                ) : null}
              </div>

              {READS.map((read) => {
                const on = publicApi[read.key]
                const Icon = on ? read.icon : Lock

                return (
                  <div key={read.key} className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                      <p className="flex items-center gap-1.5 text-[13px] font-medium">
                        <Icon className="size-3.5 text-muted-foreground" />
                        {read.label}
                      </p>

                      <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                        {on ? read.on : read.off}
                      </p>

                      {/* apiSlug, not the machine key: the address is the
                          hyphenated plural, and the server decides what that is
                          rather than this dialog assembling one */}
                      {on ? (
                        <Copyable
                          className="mt-2"
                          value={`${window.location.origin}/wp-json/schemapress/api/${
                            type.apiSlug
                          }${read.key === 'single' ? '/:id' : ''}`}
                        />
                      ) : null}
                    </div>

                    <Switch
                      checked={on}
                      aria-label={read.label}
                      onChange={(next) =>
                        next
                          ? setConfirming(`api:${read.key}`)
                          : setPublicApi((current) => ({ ...current, [read.key]: false }))
                      }
                    />
                  </div>
                )
              })}
            </CardBody>
          </Card>
        </div>
      </div>

      {/* turning drafts off is the direction that loses something: every draft
          in the collection becomes live the next time it is saved, and there is
          no longer a copy to hold work back in */}
      {confirming.startsWith('api:') ? (
        <ConfirmDialog
          open
          destructive={false}
          onOpenChange={(next) => !next && setConfirming('')}
          title={
            confirming === 'api:list'
              ? __('Let anyone list this collection?', 'schemapress')
              : __('Let anyone read one of these?', 'schemapress')
          }
          description={sprintf(
            confirming === 'api:list'
              ? /* translators: %s: the plural name of the collection */
                __(
                  'Anyone who knows the address will be able to read every published %s without logging in, and page through the lot. Drafts and unpublished edits are never served. You can turn this back off at any time.',
                  'schemapress'
                )
              : /* translators: %s: the plural name of the collection */
                __(
                  'Anyone holding an entry’s id will be able to read that %s without logging in. Ids are random, so this does not let anyone list the collection. You can turn this back off at any time.',
                  'schemapress'
                ),
            (confirming === 'api:list'
              ? type.pluralLabel || type.label || ''
              : type.singularLabel || type.label || ''
            ).toLowerCase()
          )}
          confirmLabel={__('Turn on', 'schemapress')}
          onConfirm={() => {
            setPublicApi((current) => ({ ...current, [confirming.slice(4)]: true }))
            setConfirming('')
          }}
        />
      ) : null}

      {confirming === 'drafts' ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setConfirming('')}
          title={__('Turn off draft and publish?', 'schemapress')}
          description={sprintf(
            /* translators: %s: the singular name of the collection */
            __(
              'Saving a %s will publish it immediately, and unpublished drafts go live the next time they are saved. You can turn this back on later.',
              'schemapress'
            ),
            (type.singularLabel || type.label || '').toLowerCase()
          )}
          confirmLabel={__('Turn it off', 'schemapress')}
          onConfirm={() => {
            setDrafts(false)
            setConfirming('')
          }}
        />
      ) : null}

      {confirming === 'delete' ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setConfirming('')}
          title={__('Delete this collection type?', 'schemapress')}
          description={__(
            'Every entry in it is deleted too, permanently. This cannot be undone.',
            'schemapress'
          )}
          confirmLabel={__('Delete', 'schemapress')}
          onConfirm={() => {
            setConfirming('')
            onDelete()
          }}
        />
      ) : null}
    </Dialog>
  )
}
