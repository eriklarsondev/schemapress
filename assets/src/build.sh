#!/usr/bin/env bash
#
# Renders the directory assets from their sources.
#
# The banner exists at two sizes and the icon at two formats, all from two
# source files — so a change to the mark or the wording is made once. Chrome is
# the renderer because it is the only one guaranteed to be on a machine that
# builds this, and because the banner is a layout rather than a drawing.
#
# The 2x banner is the SAME document at --force-device-scale-factor=2 rather
# than a second layout, so the two can never drift.
#
# Run: assets/src/build.sh
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
assets="$(dirname "$here")"

chrome="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

if [ ! -x "$chrome" ]; then
  echo "Google Chrome is needed to render these and was not found at:" >&2
  echo "  $chrome" >&2
  exit 1
fi

shoot() {
  local out="$1" w="$2" h="$3" scale="$4" page="$5" bg="${6:-}"

  "$chrome" --headless --disable-gpu --no-sandbox --hide-scrollbars \
    ${bg:+--default-background-color="$bg"} \
    --force-device-scale-factor="$scale" \
    --screenshot="$out" --window-size="$w,$h" "file://$page" >/dev/null 2>&1

  printf '  %-26s %sx%s\n' "$(basename "$out")" "$((w * scale))" "$((h * scale))"
}

# the icon, from the svg that is itself a shipped asset
cat > "$here/.icon.html" <<HTML
<!doctype html><meta charset="utf-8">
<style>html,body{margin:0;padding:0;width:256px;height:256px;overflow:hidden}
img{display:block;width:256px;height:256px}</style>
<img src="../icon.svg">
HTML

echo "Rendering:"
shoot "$assets/icon-256x256.png" 256 256 1 "$here/.icon.html" 00000000
shoot "$assets/banner-772x250.png" 772 250 1 "$here/banner.html"
shoot "$assets/banner-1544x500.png" 772 250 2 "$here/banner.html"

rm -f "$here/.icon.html"

echo "Done."
