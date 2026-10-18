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

## The mark and the banner are generated

`icon.svg` is the source of the mark, and `src/banner.html` is the source of the
banner. `src/build.sh` renders both with headless Chrome:

```
assets/src/build.sh
```

Chrome is the renderer because the banner is a *layout* rather than a drawing —
flexbox decides where the wordmark ends and the card begins — and because it is
the one thing guaranteed to be on a machine that builds this. The 1544×500
banner is the same document at `--force-device-scale-factor=2` rather than a
second layout, so the two sizes can never drift apart.

The colours are not chosen, they are taken: `--sp-appbar` and `--sp-primary` out
of `src/shared/style.css`, so the banner matches the screens underneath it.

**Do not edit the PNGs.** They are build output. Edit the source and re-run.

## What is still needed

Screenshots. **`readme.txt` has no `== Screenshots ==` section until they
exist** — a caption with no image renders as a numbered blank on the plugin
page. The captions below are the ones it will carry.

| File | Size | What it is | Status |
| --- | --- | --- | --- |
| `icon.svg` | vector | The icon, preferred over the PNG because it scales. | **done** |
| `icon-256x256.png` | 256×256 | The same mark, for anything that will not take an SVG. | **done** |
| `banner-772x250.png` | 772×250 | The header on the plugin page. | **done** |
| `banner-1544x500.png` | 1544×500 | The same banner for high-density screens. | **done** |
| `screenshot-1.png` | — | The Schema tab: a collection's fields, their types and their rules. | needed |
| `screenshot-2.png` | — | The Entries tab, with bulk actions and per-column sorting. | needed |
| `screenshot-3.png` | — | An entry, with its draft and published copies tracked separately. | needed |
| `screenshot-4.png` | — | The Settings screen: the API master switch, export and import. | needed |

The numbers matter: `screenshot-1.png` is captioned by the **first** line under
`== Screenshots ==` in `readme.txt`, and so on down. Reordering the captions
without renaming the files silently mislabels every one of them.

Screenshots have no required size. Take them at a consistent width — 1200px or
wider reads well on the plugin page — and crop to the screen rather than the
browser: the chrome around it is not what somebody is trying to see.
