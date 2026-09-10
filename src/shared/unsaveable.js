/**
 * Controls holding something that cannot be stored.
 *
 * MOST FIELDS CANNOT BE IN THIS STATE. A text field holds text; whatever you
 * type is what gets saved. But two of them parse what you type before they will
 * commit it — JSON and Color — and while it does not parse they keep the text
 * on screen and DO NOT call onChange, because half-written JSON is not a value
 * and storing it would replace the last good one with nothing.
 *
 * Which is right, and left a hole: the broken text lives in the control's own
 * state and never reaches the entry's values, so the form could not see it. You
 * could type `{"a":` into a JSON field, press Save, and the entry saved happily
 * — storing whatever the field held BEFORE you started typing. Nothing was lost
 * loudly. The save reported success, and the thing you wrote was simply not in
 * it.
 *
 * So a control can say so. It registers while it is unsaveable and deregisters
 * when it is not, and the form asks before letting Save be pressed — the same
 * gate a required field that is still empty goes through, and for the same
 * reason: a control that cannot answer is a control the entry is not ready past.
 *
 * A CONTEXT RATHER THAN A PROP, because the controls that need it are not
 * reliably at the top level. A JSON field can sit inside a group inside a
 * repeater row, and threading a callback down through FieldList, RepeaterField
 * and back into FieldList is four files that have to agree — where nesting is
 * exactly what a context is for.
 *
 * Outside a provider the hook does nothing, so a control still works on a screen
 * that has no Save button to gate.
 */

import { createContext, useContext, useEffect, useId } from '@wordpress/element'

const Unsaveable = createContext(null)

/**
 * Collects what the controls beneath it cannot store.
 *
 * @param {Object}   props
 * @param {Function} props.onChange called with an array of field labels
 * @return {JSX.Element} The provider.
 */
export function UnsaveableProvider({ onChange, children }) {
  return <Unsaveable.Provider value={onChange}>{children}</Unsaveable.Provider>
}

/**
 * Reports that this control is holding something unsaveable, or is not.
 *
 * Keyed on a generated id rather than the field's key, because a repeater with
 * three rows has three controls under one key — and two of them being fine does
 * not make the third saveable.
 *
 * @param {boolean} unsaveable
 * @param {string}  label what to call the field in the message
 * @return {void}
 */
export function useUnsaveable(unsaveable, label) {
  const report = useContext(Unsaveable)
  const id = useId()

  useEffect(() => {
    if (!report) {
      return undefined
    }

    report(id, unsaveable ? label : null)

    // on the way out, whatever it was holding stops mattering: a control that
    // has unmounted is not blocking anything. without this, opening an entry
    // with broken JSON and navigating away left the form permanently unsaveable
    // over a field that was no longer on screen
    return () => report(id, null)
  }, [report, id, unsaveable, label])
}
