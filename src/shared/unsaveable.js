/**
 * Controls holding something that cannot be stored.
 *
 * JSON and Color parse what you type before committing it, and while it does not
 * parse they keep the text on screen without calling onChange — half-written JSON
 * is not a value. That text then lives only in the control's own state, so
 * without this the form cannot see it: typing `{"a":` and pressing Save reported
 * success and stored whatever the field held before you started.
 *
 * So a control registers while it is unsaveable, and the form gates Save on it —
 * the same gate an empty required field goes through.
 *
 * A context rather than a prop, because a JSON field can sit inside a group
 * inside a repeater row. Outside a provider the hook does nothing, so a control
 * still works on a screen with no Save button to gate.
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
