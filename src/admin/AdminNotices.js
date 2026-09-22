/**
 * wp-admin's notices, moved into the pane.
 *
 * WordPress prints every notice at the top of the page, before anything the
 * page renders — on this screen, above the shell. The shell fills the screen
 * below the admin bar, so a notice there made the page taller than the window:
 * it scrolled, and took the sidebar with it. Core's update nag sits there on
 * every screen until the site is updated, so that was most of the time.
 *
 * In here they head the pane and scroll away with it, which is what they do on
 * every other screen in wp-admin. Only the sidebar stays put.
 */

import { useLayoutEffect, useRef } from '@wordpress/element'

/**
 * What wp-admin counts as a notice: the three common.js moves under a page's
 * heading, and the update nag, which it leaves wherever it was printed.
 */
const NOTICE = 'div.notice, div.updated, div.error, .update-nag'

/**
 * The notices region.
 *
 * Rendered outside the `.schemapress` scope, and it has to be. Inside it the
 * scoped reset strips a notice's border, its margins and its dismiss button,
 * and the notice's own classes are read as utilities — the update nag is
 * `inline`, which is Tailwind's too.
 *
 * @return {JSX.Element} The region.
 */
export function AdminNotices() {
  const region = useRef(null)
  const marker = useRef(null)

  useLayoutEffect(() => {
    // where wp-admin prints them, and the wrap this app is mounted in, which
    // is where a script adding one to "the top of the page" tends to put it
    const sources = [
      document.getElementById('wpbody-content'),
      region.current.closest('.wrap'),
    ].filter(Boolean)

    const adopt = () => {
      sources
        .flatMap((source) => [...source.children])
        .filter((node) => node.matches(NOTICE))
        // ahead of the marker, which is where they stand relative to the page
        // heading on any other screen: printed ones first, then the ones core
        // moves under it
        .forEach((node) => region.current.insertBefore(node, marker.current))
    }

    adopt()

    // the printed ones are all here by now; this is for a notice added once the
    // page is running. childList only, so the app rendering further down the
    // tree never wakes it
    const observer = new MutationObserver(adopt)
    sources.forEach((source) => observer.observe(source, { childList: true }))

    return () => observer.disconnect()
  }, [])

  return (
    <div ref={region} className="sp-notices">
      {/* the marker core looks for. when common.js finds it on the page it
          moves every notice to just after it, and a script adding one later
          puts it here too. whichever of that and this app's first render
          happens first, the effect above collects what is left — including
          the update nag, which core never moves at all */}
      <hr ref={marker} className="wp-header-end" />
    </div>
  )
}
