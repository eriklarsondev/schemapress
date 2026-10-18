/**
 * The SchemaPress mark.
 *
 * The same three rows as the plugin's icon and its admin menu glyph, drawn
 * without the tile: wherever this is used something else is already supplying
 * the shape behind it. Filled with `currentColor`, so it takes the colour of
 * whatever it sits in rather than carrying one of its own.
 *
 * The geometry is the 20px version rather than the 256px one reduced. A mark
 * drawn for the large size lands on half pixels down here and goes soft.
 *
 * Same construction as the icon: every square is 4.4 and every gap is 1.6, so
 * the middle bar is 4.4 + 1.6 + 4.4 = 10.4 and rows two and three end together
 * at 17.4.
 */
export function Mark({ className, ...props }) {
  return (
    <svg
      viewBox="0 0 20 20"
      fill="currentColor"
      className={className}
      aria-hidden="true"
      focusable="false"
      {...props}
    >
      <rect x="1" y="1.2" width="4.4" height="4.4" rx="1.3" />
      <rect x="7" y="1.2" width="12" height="4.4" rx="1.3" />
      <rect x="1" y="7.8" width="4.4" height="4.4" rx="1.3" />
      <rect x="7" y="7.8" width="10.4" height="4.4" rx="1.3" />
      <rect x="1" y="14.4" width="4.4" height="4.4" rx="1.3" />
      <rect x="7" y="14.4" width="4.4" height="4.4" rx="1.3" />
      <rect x="13" y="14.4" width="4.4" height="4.4" rx="1.3" />
    </svg>
  )
}
