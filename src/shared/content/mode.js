/**
 * Design mode.
 *
 * The builder serves two jobs that happen to share a screen: shaping what a
 * component *is*, and filling in what it *says*. Showing both at once is what
 * made the surface overwhelming - a field's key, its type and its classes are
 * noise to someone writing a headline.
 *
 * Design mode reveals the first job. Content mode hides it. Nothing is
 * removed, only deferred, so complete control is always one toggle away.
 */

import { createContext, useContext } from '@wordpress/element'

const DesignContext = createContext(true)

/**
 * Provides the current mode to a builder subtree.
 *
 * @param {Object} props
 * @return {JSX.Element} The provider.
 */
export function DesignProvider({ design, children }) {
  return <DesignContext.Provider value={design}>{children}</DesignContext.Provider>
}

/**
 * Whether structural controls should be shown.
 *
 * @return {boolean} True in design mode.
 */
export function useDesign() {
  return useContext(DesignContext)
}
