<!-- group: Get started -->
<!-- description: Requirements, the two install commands, and how to tell which one you still need to run. -->

## Installation

| | |
| --- | --- |
| WordPress | 6.2 or later |
| PHP | 8.2 or later |
| Timber | Optional — 2.x, only if your theme renders through Twig |

:::caution PHP 8.2 is enforced
WordPress checks the `Requires PHP` header before activating, which is the only thing
standing between an older site and a fatal error the moment the autoloader reaches Timber.
If the plugin refuses to activate, that is why.
:::

### Installing

Drop the plugin in `wp-content/plugins/schemapress` and activate it in **Plugins**.

Then install its dependencies. From the plugin directory:

```bash
composer install
npm install && npm run build
```

`composer install` brings in the Markdown parser that renders these pages and Timber's
library. `npm run build` compiles the admin screens.

:::note Working from a release
A packaged release ships with `vendor/` and `build/` already in it. The two commands above
are only needed when you are working from the repository.
:::

If the admin screens do not appear, `build/` is missing — run the npm step. If this
documentation renders as plain text, `vendor/` is missing — run the composer step.
