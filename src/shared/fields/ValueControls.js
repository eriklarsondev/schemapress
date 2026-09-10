/**
 * The two controls that edit a value with a shape of its own.
 *
 * A color is a string a stylesheet can use, and a JSON field is a shape this
 * collection deliberately does not describe. Both need an editor that knows
 * something about what is being typed — a swatch, a parser — which is what
 * separates them from a plain text field.
 */

import { useState, useEffect, useMemo, useRef } from '@wordpress/element'
import { __, _n, sprintf } from '@wordpress/i18n'
import { X, Braces, ChevronDown, ChevronRight } from 'lucide-react'
import { Field, Input, Button, Tooltip, cn } from '../../ui'
import { useUnsaveable } from '../unsaveable'
import { widthOf } from '../layout'
// already in the bundle for the documentation screen, and already carrying the
// json grammar — see shared/prism.js
import Prism from '../prism'

/**
 * A few colors worth one click.
 *
 * Not a palette the site defines — that would be a setting, and a setting whose
 * value is a list of hex codes is a stylesheet in the wrong place. These are
 * only a starting point for somebody who wants "a red" and does not have the
 * code to hand.
 */
const SWATCHES = [
  '#111827',
  '#6b7280',
  '#ef4444',
  '#f59e0b',
  '#10b981',
  '#3b82f6',
  '#8b5cf6',
  '#ec4899',
]

/**
 * Whether a string is a hex color.
 *
 * Mirrors sanitize_hex_color() on the server, which is what actually decides:
 * a value that fails there is stored as nothing, so the control has to refuse
 * the same things or somebody types a color name and watches it disappear on
 * save with no explanation.
 *
 * @param {string} value
 * @return {boolean} True when it is one.
 */
function isHex(value) {
  return /^#([0-9a-f]{3}){1,2}$/i.test(value)
}

/**
 * A color, as a swatch and a hex code.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function ColorField({ field, value, onChange }) {
  // typed text is held locally until it is a color. committing every keystroke
  // would mean "#ff" is stored as nothing on the way to "#ff0000", and the
  // swatch flickers back to empty between the two
  const [text, setText] = useState(value || '')

  useEffect(() => setText(value || ''), [value])

  const valid = text === '' || isHex(text)

  // the same hole JSON had: a half-typed `#ff` is not committed, so the form
  // would save the colour that was there before and say it worked
  useUnsaveable(!valid, field.label)

  return (
    <Field
      label={field.label}
      help={field.help}
      required={field.required}
      error={valid ? undefined : __('That is not a hex color, like #3b82f6.', 'schemapress')}
    >
      {(id) => (
        <div className="flex flex-col gap-2">
          <div className="flex items-center gap-2">
            {/* the native picker, which is the one thing a hand-written swatch
                grid cannot be: it opens the operating system's color picker,
                including the eyedropper */}
            {/* no sizing classes: a color input is sized in style.css, beside
                the reset that used to swallow it. a utility here would be
                outranked by that reset and quietly do nothing, which is how
                this control came to be a full-width bar */}
            <input
              type="color"
              value={isHex(text) ? text : '#ffffff'}
              aria-label={__('Pick a color', 'schemapress')}
              onChange={(event) => {
                setText(event.target.value)
                onChange(event.target.value)
              }}
            />

            <Input
              id={id}
              value={text}
              placeholder="#3b82f6"
              spellCheck={false}
              onChange={(event) => {
                const next = event.target.value
                setText(next)

                if (next === '' || isHex(next)) {
                  onChange(next)
                }
              }}
            />

            {value ? (
              <Button
                size="icon-sm"
                variant="destructive-ghost"
                aria-label={__('Clear color', 'schemapress')}
                onClick={() => {
                  setText('')
                  onChange('')
                }}
              >
                <X />
              </Button>
            ) : null}
          </div>

          {/* offset to sit under the hex field rather than under the picker:
              these are shortcuts for what you would otherwise type in there,
              and lining them up with it says so */}
          <div className="flex flex-wrap gap-1.5 pl-[2.75rem]">
            {SWATCHES.map((swatch) => (
              <button
                key={swatch}
                type="button"
                title={swatch}
                aria-label={swatch}
                aria-pressed={value?.toLowerCase() === swatch}
                onClick={() => {
                  setText(swatch)
                  onChange(swatch)
                }}
                style={{ backgroundColor: swatch }}
                className={cn(
                  // 24px rather than 20: these are the smallest targets on the
                  // screen and were under every pointer-target guideline there
                  // is. `ring-inset` so the selected one does not grow and
                  // shove the row along as you click across it
                  'size-6 rounded-md ring-1 ring-inset transition-transform hover:scale-110',
                  value?.toLowerCase() === swatch
                    ? 'ring-2 ring-foreground'
                    : // a swatch light enough to vanish into the form still has
                      // to read as a chip, so the ring is a shade of the color
                      // itself rather than a border that only works on dark ones
                      'ring-black/15',
                )}
              />
            ))}
          </div>
        </div>
      )}
    </Field>
  )
}

/**
 * A JSON payload, edited as text and stored as data.
 *
 * The value round-trips through the parser: what is stored is decoded data
 * rather than the string somebody typed, which is what lets it come back out of
 * the API as JSON rather than as JSON inside a string. So the control holds the
 * text while it is being typed — half-written JSON does not parse — and commits
 * only what does.
 *
 * IT IS ALWAYS EXPANDED. Anything arriving from outside is written out one
 * value to a line, and so is anything pasted in or left behind on blur — the
 * two ways a minified blob gets in here. Reformatting was a button somebody had
 * to know to press, which meant the usual state of the field was a single
 * unreadable line and the way out of it was not visible from inside it.
 *
 * Not on every keystroke, though, and that is the one place it must not happen:
 * re-indenting what somebody is halfway through typing moves the caret out from
 * under them. A paste is not typing and a blur is not either, so both are safe
 * moments to tidy, and between them there is no way to leave it minified.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function JsonField({ field, value, onChange }) {
  const [text, setText] = useState(() => stringify(value))
  const [invalid, setInvalid] = useState(false)
  // a paste lands as an ordinary change, indistinguishable from typing by the
  // time it arrives — so the paste event itself leaves this note for it
  const pasted = useRef(false)

  // text that does not parse is never committed, so without this the form would
  // save the last value that DID parse and report success — see shared/unsaveable
  useUnsaveable(invalid, field.label)

  // only when the value changes from OUTSIDE — a different entry loading, a
  // draft being discarded
  useEffect(() => {
    setText((current) => (equivalent(current, value) ? current : stringify(value)))
    setInvalid(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(value ?? null)])

  const rows = Number(field.config?.rows) || 8

  /**
   * Takes new text, storing it when it parses and holding it when it does not.
   *
   * One path for typing and for completion alike, so a bracket the editor
   * closed is validated exactly as one somebody typed.
   *
   * @param {string} next
   * @return {void}
   */
  const commit = (next) => {
    const tidy = pasted.current

    pasted.current = false

    if (next.trim() === '') {
      setText(next)
      setInvalid(false)
      onChange(null)

      return
    }

    try {
      const parsed = JSON.parse(next)

      onChange(parsed)
      setInvalid(false)
      setText(tidy ? stringify(parsed) : next)
    } catch {
      // held, not committed. the text stays on screen so it can be fixed
      // rather than vanishing on the first unbalanced brace — and it is left
      // exactly as it was typed, because half-written JSON cannot be reindented
      setText(next)
      setInvalid(true)
    }
  }

  return (
    <Field
      label={field.label}
      help={field.help}
      required={field.required}
      error={invalid ? __('That is not valid JSON, so it has not been stored.', 'schemapress') : undefined}
    >
      {(id) => (
        <JsonEditor
          id={id}
          text={text}
          rows={rows}
          invalid={invalid}
          value={value}
          // the preview is a second column, and a JSON field set to a third of
          // a row would give each pane about ninety pixels — too narrow to read
          // code in. so it appears only where there is room for it
          preview={widthOf(field) === 'full'}
          onChange={commit}
          onFormat={() => setText(stringify(value))}
          onPaste={() => {
            pasted.current = true
          }}
          onBlur={() => {
            if (!invalid && text.trim() !== '') {
              setText(stringify(value))
            }
          }}
        />
      )}
    </Field>
  )
}

/**
 * The editor itself: a textarea nobody can see, over the same text highlighted.
 *
 * The alternative was a real editor component. This is a JSON blob in a form
 * field, and CodeMirror is two hundred kilobytes and a second editing model to
 * keep in step with this one — where the browser already has a perfectly good
 * one that does undo, selection, spellcheck, accessibility and every keyboard
 * convention correctly. So the textarea stays and only its color is borrowed:
 * transparent glyphs, a visible caret, and Prism's output painted underneath in
 * exactly the same place.
 *
 * The highlighted copy is the layer in the flow, so the editor is as tall as
 * its content and never scrolls inside itself — which is the other half of
 * "show me all of it". `rows` is a floor rather than a size.
 *
 * @param {Object} props
 * @return {JSX.Element} The editor.
 */
function JsonEditor({ id, text, rows, invalid, value, preview, onChange, onFormat, onPaste, onBlur }) {
  const html = useMemo(
    () => (text === '' ? '' : Prism.highlight(text, Prism.languages.json, 'json')),
    [text],
  )

  const empty = text.trim() === ''

  return (
    <div className={cn('sp-json', invalid && 'is-invalid', preview && 'is-split')}>
      <div className="sp-json-editor">
        {/* ABOVE the textarea, which covers the whole editor — so this needs a
            layer of its own or the click lands on the field behind it.

            ALWAYS THERE, disabled when there is nothing to do. It used to be
            absent on an empty field, which meant the one moment the control
            appeared was the moment you had already typed something — so nobody
            learned it existed until after they had needed it, and the editor
            changed shape while being typed into. A control in a fixed place that
            is sometimes unavailable is easier to learn than one that comes and
            goes. */}
        <div className="absolute right-1.5 top-1.5 z-10">
          {/* only the refusal is worth a tooltip. when the button is available it
              says what it does, and a tooltip repeating the word already printed
              on it is the kind of help that trains people to ignore tooltips.
              Tooltip renders nothing at all for an empty label */}
          <Tooltip
            label={
              invalid
                ? __('Fix the JSON first', 'schemapress')
                : empty
                  ? __('Nothing to format yet', 'schemapress')
                  : ''
            }
            // a disabled button emits no pointer events, so Tooltip has to know
            // to wrap it in something that can still be hovered — and the
            // disabled state is precisely when the label is worth reading
            disabled={invalid || empty}
          >
            <Button
              size="sm"
              // outline rather than ghost: it sits ON the code, and a control
              // with no surface of its own has to be read through whatever
              // happens to be on the first line behind it
              variant="outline"
              disabled={invalid || empty}
              // the caret stays where it was: without this, pressing the button
              // blurs the textarea, and a blur is one of the two moments this
              // field reformats on its own — so the click would land on a field
              // that had already done the job and moved the caret to the end
              onMouseDown={(event) => event.preventDefault()}
              onClick={onFormat}
            >
              <Braces />
              {__('Format', 'schemapress')}
            </Button>
          </Tooltip>
        </div>

        {/* aria-hidden because it is the same text twice: a screen reader is
            reading the textarea, and the highlighting is a color, not content */}
        <pre
          aria-hidden="true"
          className="sp-json-layer"
          // the floor, in lines of this layer's own text plus the padding the
          // border box includes. `rows` on a textarea meant lines of content, and
          // it goes on the element that decides the height — which is this one
          style={{ minHeight: `calc(${rows} * 1.6em + 1.5rem)` }}
        >
          {/* the trailing newline is what gives a final empty line its height. a
              textarea keeps one and a <pre> collapses it, so without this the
              caret on the last line sits below the text it is highlighting */}
          <code className="language-json" dangerouslySetInnerHTML={{ __html: `${html}\n` }} />
        </pre>

        <textarea
          id={id}
          value={text}
          spellCheck={false}
          placeholder={'{\n  "key": "value"\n}'}
          className="sp-json-layer"
          onChange={(event) => onChange(event.target.value)}
          onKeyDown={(event) => complete(event, onChange)}
            onPaste={onPaste}
            onBlur={onBlur}
          />
      </div>

      {preview ? <JsonPreview value={value} stale={invalid} /> : null}
    </div>
  )
}

/**
 * What a value is, in the words JSON uses for it.
 *
 * `typeof null` is "object", which is a JavaScript fact rather than a JSON one,
 * and an array is an object too. Both matter here: the tree draws them
 * differently and colours them differently.
 *
 * @param {*} value
 * @return {string} object, array, null, string, number or boolean.
 */
function kindOf(value) {
  if (value === null) {
    return 'null'
  }

  return Array.isArray(value) ? 'array' : typeof value
}

/**
 * A scalar as the preview prints it.
 *
 * Strings keep their quotes. Without them `12` and `"12"` are the same three
 * characters on screen, and telling those apart is most of what somebody is
 * looking at a JSON preview to do.
 *
 * @param {*} value
 * @return {string} The printed form.
 */
function printed(value) {
  return typeof value === 'string' ? `"${value}"` : String(value)
}

/**
 * One row of the preview: a scalar, or a container and everything under it.
 *
 * The token classes are Prism's, and deliberately — this sits inside `.sp-json`,
 * where those classes already have a measured colour for every JSON type. A
 * string is the same green in the preview as in the editor beside it because it
 * is literally the same rule, rather than a second palette that agrees today.
 *
 * @param {Object} props
 * @return {JSX.Element} The row.
 */
function JsonNode({ name, value }) {
  // open, because the point of this pane is seeing what is in there. a tree
  // that starts collapsed is a tree you have to dismantle before it tells you
  // anything, and this field's whole behaviour is already "show me all of it"
  const [open, setOpen] = useState(true)

  const kind = kindOf(value)
  const nested = kind === 'object' || kind === 'array'

  if (!nested) {
    return (
      <li className="flex gap-2 py-px">
        {name === null ? null : <span className="token property shrink-0">{name}</span>}
        <span className={cn('token min-w-0 break-all', kind)}>{printed(value)}</span>
      </li>
    )
  }

  const entries =
    kind === 'array'
      ? value.map((item, index) => [String(index), item])
      : Object.entries(value)

  return (
    <li className="py-px">
      <button
        type="button"
        onClick={() => setOpen((current) => !current)}
        className="flex w-full items-center gap-1 rounded text-left hover:bg-primary/5"
      >
        {open ? (
          <ChevronDown className="size-3 shrink-0 opacity-60" />
        ) : (
          <ChevronRight className="size-3 shrink-0 opacity-60" />
        )}

        <span className="token punctuation shrink-0">{kind === 'array' ? '[]' : '{}'}</span>
        {name === null ? null : <span className="token property truncate">{name}</span>}

        <span className="ml-auto shrink-0 pl-2 text-[11px] text-muted-foreground">
          {kind === 'array'
            ? sprintf(
                /* translators: %d: how many entries an array holds */
                _n('%d item', '%d items', entries.length, 'schemapress'),
                entries.length
              )
            : sprintf(
                /* translators: %d: how many keys an object holds */
                _n('%d key', '%d keys', entries.length, 'schemapress'),
                entries.length
              )}
        </span>
      </button>

      {open && entries.length > 0 ? (
        // the indent is a border rather than padding, so the nesting is a line
        // you can follow down the pane instead of an offset you have to measure
        <ul className="ml-[0.4rem] border-l border-border pl-2.5">
          {entries.map(([key, item]) => (
            <JsonNode key={key} name={key} value={item} />
          ))}
        </ul>
      ) : null}
    </li>
  )
}

/**
 * The parsed value, as a tree.
 *
 * IT RENDERS `value`, NOT THE TEXT, and that is the whole design. `value` is
 * the last thing that parsed — the control never commits text that does not —
 * so while somebody is halfway through typing a new key the preview goes on
 * showing the last version that meant something, rather than blanking on every
 * keystroke that leaves a brace unbalanced. Which is the only way a preview of
 * a thing being typed is any use at all.
 *
 * @param {Object} props
 * @return {JSX.Element} The pane.
 */
function JsonPreview({ value, stale }) {
  const empty = value === null || value === undefined || value === ''

  return (
    <div className="sp-json-preview">
      {stale && !empty ? (
        // said plainly, because an unchanging preview beside text that IS
        // changing reads as a broken pane rather than a deliberate one
        <p className="mb-1.5 text-[11px] font-medium text-muted-foreground">
          {__('Last valid version', 'schemapress')}
        </p>
      ) : null}

      {empty ? (
        <p className="text-[12px] italic text-muted-foreground">
          {__('Nothing to preview yet.', 'schemapress')}
        </p>
      ) : (
        <ul className="font-mono text-[12px] leading-[1.6]">
          <JsonNode name={null} value={value} />
        </ul>
      )}
    </div>
  )
}

/**
 * What each opening character is closed by.
 */
const PAIRS = { '{': '}', '[': ']', '"': '"' }

/**
 * One level of indentation, matching what Tidy up writes.
 */
const INDENT = '  '

/**
 * Whether the caret sits inside a JSON string, reading the current line.
 *
 * A JSON string cannot span a line, so counting the unescaped quotes before the
 * caret on this line answers it: an odd number means one is open. That is what
 * decides whether `{` opens an object — and gets its partner — or is only a
 * brace somebody is typing into a value.
 *
 * @param {string} before the text before the caret
 * @return {boolean} True inside a string.
 */
function inString(before) {
  const line = before.slice(before.lastIndexOf('\n') + 1)
  let open = false

  for (let index = 0; index < line.length; index++) {
    if (line[index] === '\\') {
      index++

      continue
    }

    if (line[index] === '"') {
      open = !open
    }
  }

  return open
}

/**
 * Whether a quote typed here would open an object KEY rather than a value.
 *
 * The distinction is the whole difficulty. `"` inside an object starts a key and
 * wants `: ""` after it; the identical keystroke inside an array starts a value
 * and must not get a colon, because `["a": ""]` is not JSON at all. Nothing
 * about the character says which, so the surrounding structure has to.
 *
 * Which means a full scan from the top rather than reading the current line the
 * way inString() does. A line knows whether a quote is open on it; it cannot
 * know whether the container two levels up is `{` or `[`, and that is exactly
 * what is being asked.
 *
 * A key can start where a key can start: directly inside `{`, or after the comma
 * that ended the last pair. Anywhere else a quote is part of something already
 * under way.
 *
 * @param {string} before the text before the caret
 * @return {boolean} True where a key would begin.
 */
function opensKey(before) {
  const stack = []
  let quoted = false

  for (let index = 0; index < before.length; index++) {
    const character = before[index]

    if (quoted) {
      // an escaped character cannot close the string, whatever it is
      if (character === '\\') {
        index++
      } else if (character === '"') {
        quoted = false
      }

      continue
    }

    if (character === '"') {
      quoted = true
    } else if (character === '{' || character === '[') {
      stack.push(character)
    } else if (character === '}' || character === ']') {
      stack.pop()
    }
  }

  if (quoted || stack[stack.length - 1] !== '{') {
    return false
  }

  const previous = before.replace(/\s+$/, '').slice(-1)

  return previous === '{' || previous === ','
}

/**
 * Replaces a range of a textarea's text, keeping the browser's undo history.
 *
 * Through execCommand rather than by setting the value, because setting the
 * value wipes the undo stack: a completed bracket would be the one thing Cmd+Z
 * could not take back. execCommand is deprecated in name and still the only
 * way to insert text into a field as though it had been typed; where it is
 * unavailable, the value is set directly and only undo is lost.
 *
 * @param {HTMLTextAreaElement} el
 * @param {number}   from
 * @param {number}   to
 * @param {string}   text
 * @param {Function} commit fallback when the browser will not insert
 * @return {void}
 */
function replace(el, from, to, text, commit) {
  el.setSelectionRange(from, to)

  const inserted =
    text === ''
      ? document.execCommand('delete')
      : document.execCommand('insertText', false, text)

  if (!inserted) {
    commit(el.value.slice(0, from) + text + el.value.slice(to))
  }
}

/**
 * Closes what was opened, the way a code editor does.
 *
 *   "           where a KEY begins, write the whole pair: `"": ""`
 *   {  [  "     insert the pair, caret between — or wrap a selection in it
 *   }  ]  "     step over the one already there rather than doubling it
 *   Enter       between a pair, open an indented line; anywhere else, keep
 *               the current line's indentation
 *   Backspace   between an empty pair, remove both halves
 *
 * Tab is NOT taken. A textarea that swallows Tab traps a keyboard user in it
 * with no way out, and Enter already gives indentation for free.
 *
 * @param {KeyboardEvent} event
 * @param {Function}      commit
 * @return {void}
 */
function complete(event, commit) {
  // mid-composition the key is a candidate character, not the character; and a
  // modifier means a shortcut, which is not ours to interpret
  if (event.nativeEvent?.isComposing || event.metaKey || event.ctrlKey || event.altKey) {
    return
  }

  const el = event.currentTarget
  const start = el.selectionStart
  const end = el.selectionEnd
  const before = el.value.slice(0, start)
  const after = el.value.slice(end)
  const selected = el.value.slice(start, end)
  const key = event.key
  const quoted = inString(before)

  // stepping over: the closer is already there, so typing it means "past it"
  if ((key === '}' || key === ']' || key === '"') && !selected && after[0] === key) {
    if (key !== '"' || quoted) {
      event.preventDefault()
      el.setSelectionRange(start + 1, start + 1)

      return
    }
  }

  // a quote where a KEY begins writes the whole empty pair, not just its own
  // partner: `"": ""`, caret between the first two. The rest of a property is
  // four characters that are the same every time — the closing quote, the
  // colon, the space, and a pair of quotes for the value — and typing them is
  // the part of writing JSON by hand that is purely mechanical.
  //
  // A pair rather than a bare `": "` because the value is a string more often
  // than it is anything else, and the quotes are trivial to type over when it
  // is not. Only where a key can start — see opensKey, which is what keeps this
  // out of arrays.
  if (key === '"' && !selected && opensKey(before)) {
    event.preventDefault()
    replace(el, start, end, '"": ""', commit)
    el.setSelectionRange(start + 1, start + 1)

    return
  }

  if (PAIRS[key]) {
    // inside a string a brace is text, and a quote closes the string rather
    // than opening a new one — neither wants a partner
    if (quoted && !selected) {
      return
    }

    event.preventDefault()
    replace(el, start, end, key + selected + PAIRS[key], commit)

    // a wrapped selection stays selected; otherwise the caret goes between
    el.setSelectionRange(start + 1, start + 1 + selected.length)

    return
  }

  if (key === 'Enter' && !event.shiftKey) {
    const line = before.slice(before.lastIndexOf('\n') + 1)
    const indent = line.match(/^\s*/)[0]
    const opener = before.trimEnd().slice(-1)
    const opens = opener === '{' || opener === '['

    event.preventDefault()

    // spaces and tabs only, not newlines: `{|\n}` has already been opened, and
    // reading past the newline would open it a second time with a blank line
    if (opens && after.replace(/^[ \t]*/, '')[0] === PAIRS[opener]) {
      // {|}  becomes  {
      //                 |
      //               }
      const inner = `\n${indent}${INDENT}`

      replace(el, start, end, `${inner}\n${indent}`, commit)
      el.setSelectionRange(start + inner.length, start + inner.length)

      return
    }

    const next = `\n${indent}${opens ? INDENT : ''}`

    replace(el, start, end, next, commit)
    el.setSelectionRange(start + next.length, start + next.length)

    return
  }

  if (key === 'Backspace' && !selected && start > 0) {
    const opener = before.slice(-1)

    if (PAIRS[opener] && after[0] === PAIRS[opener]) {
      event.preventDefault()
      replace(el, start - 1, start + 1, '', commit)
    }
  }
}

/**
 * A value as indented JSON, or empty for nothing.
 *
 * @param {*} value
 * @return {string} The text.
 */
function stringify(value) {
  // an empty string is what a fresh form hands every field before it has been
  // touched, and JSON.stringify('') is the two characters "" — which is how a
  // JSON field that had never been filled in arrived showing a pair of quotes
  // and a Tidy up button for them. nothing is nothing, whichever shape it came in
  if (value === null || value === undefined || value === '') {
    return ''
  }

  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return ''
  }
}

/**
 * Whether some text already represents a value, whatever its whitespace.
 *
 * @param {string} text
 * @param {*}      value
 * @return {boolean} True when parsing the text gives the value.
 */
function equivalent(text, value) {
  try {
    return JSON.stringify(JSON.parse(text)) === JSON.stringify(value ?? null)
  } catch {
    return false
  }
}
