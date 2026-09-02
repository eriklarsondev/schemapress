/**
 * Timestamps, told the way people ask about them.
 *
 * "20 min. ago" is the thing you actually wanted to know; a date is a fact you
 * then have to convert. Down a column of entries the relative form is also the
 * only one that can be scanned — the differences are the point, and absolute
 * timestamps bury those behind sixteen near-identical characters.
 *
 * The exact time is never thrown away, only moved into the tooltip, because
 * "3 weeks ago" is the wrong answer when you need to line something up against
 * a deploy or a support ticket.
 */

import { useEffect, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'

/**
 * How long a unit runs before the next one up reads better, and how many
 * seconds are in it. A month is the average one and a year the real one, so
 * eleven months never rounds up into "1 year ago".
 */
const UNITS = [
  [60, 1, 'second'],
  [3600, 60, 'minute'],
  [86400, 3600, 'hour'],
  [604800, 86400, 'day'],
  [2629800, 604800, 'week'],
  [31557600, 2629800, 'month'],
  [Infinity, 31557600, 'year']
]

/**
 * Parses a WordPress GMT timestamp.
 *
 * They arrive as "Y-m-d H:i:s" in UTC with no zone marker, which Safari refuses
 * outright and every other browser reads as local time — an hour or eight in
 * the wrong direction, and occasionally in the future. Rebuilding it as an ISO
 * instant is what makes the answer the same everywhere.
 *
 * @param {string} stamp
 * @return {Date|null} The instant, or null if it is missing or unparseable.
 */
function parse(stamp) {
  if (!stamp) {
    return null
  }

  const at = new Date(`${String(stamp).trim().replace(' ', 'T')}Z`)

  return Number.isNaN(at.getTime()) ? null : at
}

/**
 * Turns a GMT timestamp into how long ago it was.
 *
 * @param {string} stamp
 * @return {string} A phrase like "20 min. ago", or '' if there is no timestamp.
 */
export function ago(stamp) {
  const at = parse(stamp)

  if (!at) {
    return ''
  }

  const seconds = Math.round((at.getTime() - Date.now()) / 1000)
  const elapsed = Math.abs(seconds)

  // clock skew between the server and this browser can land a save a few
  // seconds in the future, and "in 4 seconds" is alarming for something you
  // watched yourself do a moment ago
  if (elapsed < 45) {
    return __('just now', 'schemapress')
  }

  const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto', style: 'short' })
  const [, per, unit] = UNITS.find(([limit]) => elapsed < limit)

  return format.format(Math.round(seconds / per), unit)
}

/**
 * The same timestamp in full, in the reader's own timezone.
 *
 * @param {string} stamp
 * @return {string} A local date and time, or '' if there is no timestamp.
 */
export function exact(stamp) {
  const at = parse(stamp)

  return at ? at.toLocaleString() : ''
}

/**
 * One clock for every relative timestamp on the screen.
 *
 * A relative time is only true at the moment it is rendered — leave the tab
 * open and "just now" quietly becomes a lie. Re-rendering on a timer fixes
 * that, and sharing a single interval across every instance means a table of
 * fifty rows costs one timer rather than fifty, and they all change together
 * instead of drifting a row at a time.
 */
const clock = {
  listeners: new Set(),
  timer: null,

  /**
   * Registers a re-render, starting the interval on the first subscriber and
   * stopping it after the last one leaves.
   *
   * @param {Function} listener
   * @return {Function} An unsubscribe.
   */
  subscribe(listener) {
    this.listeners.add(listener)

    if (!this.timer) {
      this.timer = setInterval(() => this.listeners.forEach((call) => call()), 30000)
    }

    return () => {
      this.listeners.delete(listener)

      if (this.listeners.size === 0) {
        clearInterval(this.timer)
        this.timer = null
      }
    }
  }
}

/**
 * A timestamp, relative, with the exact time on hover.
 *
 * @param {Object} props
 * @param {string} props.stamp    A GMT timestamp.
 * @param {string} props.fallback Shown when there is no timestamp at all.
 * @return {JSX.Element} The time element.
 */
export function Ago({ stamp, fallback = __('Never', 'schemapress'), className }) {
  const [, tick] = useState(0)

  useEffect(() => clock.subscribe(() => tick((count) => count + 1)), [])

  const relative = ago(stamp)

  if (!relative) {
    return <span className={className}>{fallback}</span>
  }

  return (
    <time dateTime={stamp} title={exact(stamp)} className={className}>
      {relative}
    </time>
  )
}
