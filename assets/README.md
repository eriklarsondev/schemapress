# Directory assets

**These do not ship inside the plugin.** The wordpress.org repository is an SVN
checkout with three top-level directories, and `assets/` is a sibling of the
plugin rather than part of it:

```
schemapress/
  assets/    <- this directory: icon, banner, screenshots
  tags/      <- one directory per released version
  trunk/     <- the plugin itself, which is what `npm run package` builds
```

So the images below are committed here for convenience, copied into SVN
`assets/` at release, and excluded from the zip by `.distignore`. Putting them
in the plugin would ship a megabyte of marketing to every site that installs it,
every time it updates.

## What is still needed

None of these exist yet. **`readme.txt` has no `== Screenshots ==` section
because of that** — a caption with no image renders as a numbered blank on the
plugin page. The captions below are the ones it carried; put the section back
when the files land.

| File | Size | What it is |
| --- | --- | --- |
| `icon-256x256.png` | 256×256 | The icon in search results and the installer. `icon.svg` is accepted instead and scales better. |
| `banner-772x250.png` | 772×250 | The header on the plugin page. |
| `banner-1544x500.png` | 1544×500 | The same banner for high-density screens. Optional, and obvious when it is missing. |
| `screenshot-1.png` | — | The Schema tab: a collection's fields, their types and their rules. |
| `screenshot-2.png` | — | The Entries tab, with bulk actions and per-column sorting. |
| `screenshot-3.png` | — | An entry, with its draft and published copies tracked separately. |
| `screenshot-4.png` | — | The Settings screen: the API master switch, export and import. |

The numbers matter: `screenshot-1.png` is captioned by the **first** line under
`== Screenshots ==` in `readme.txt`, and so on down. Reordering the captions
without renaming the files silently mislabels every one of them.

Screenshots have no required size. Take them at a consistent width — 1200px or
wider reads well on the plugin page — and crop to the screen rather than the
browser: the chrome around it is not what somebody is trying to see.
