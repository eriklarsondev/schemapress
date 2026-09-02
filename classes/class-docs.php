<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the documentation screen.
 *
 * the text lives in docs/*.md, one file per topic, compiled into a single page
 * in filename order. keeping it as Markdown means the same source reads well in
 * the repository and on GitHub, and editing a paragraph does not mean editing
 * PHP.
 *
 * one placeholder is filled in before rendering, so the page states what is true
 * of *this* install rather than what is true in general:
 *
 *   %%timber_status%%   whether the Twig functions are registered here
 *
 * the screen is server-rendered and loads no bundle. it explains what to do
 * when the admin bundle has not been built and when Timber is missing — both
 * states in which a React screen would itself be blank.
 */
class Docs
{
    const PAGE_SLUG = 'schemapress-docs';

    /**
     * where the Markdown sources live, relative to the plugin root.
     */
    const SOURCE_DIR = 'docs';

    /**
     * hooks the submenu page.
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenu'], 20);
    }

    /**
     * registers the documentation page under the SchemaPress menu.
     *
     * @return void
     */
    public function registerMenu()
    {
        add_submenu_page(
            Admin::PAGE_SLUG,
            __('SchemaPress Docs', 'schemapress'),
            __('Documentation', 'schemapress'),
            Admin::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * renders the page.
     *
     * @return void
     */
    public function render()
    {
        $html = self::html();

        echo '<div class="schemapress-docs">';

        self::styles();

        printf(
            '<header class="sp-docs-head"><h1>%s</h1><p>%s</p></header>',
            esc_html__('Documentation', 'schemapress'),
            esc_html__(
                'Define a collection, fill in its entries, and read them from your theme. Nothing about presentation is stored here — what the content looks like is your templates’ business.',
                'schemapress'
            )
        );

        if (!self::parserAvailable()) {
            printf(
                '<div class="notice notice-warning inline"><p>%s <code>composer install</code></p></div>',
                esc_html__(
                    'A Markdown parser is not installed, so the documentation below is shown as plain text. To format it, run',
                    'schemapress'
                )
            );
        }

        printf(
            '<div class="sp-docs-layout">%s<div class="sp-docs-body">%s</div></div>',
            self::nav($html),
            $html
        );

        self::script();

        echo '</div>';
    }

    // --- compilation ---------------------------------------------------------

    /**
     * every Markdown source, in filename order.
     *
     * the numeric prefixes are what order the page, so a new topic is added by
     * dropping a file in rather than by editing a list here.
     *
     * @return string[] absolute paths
     */
    public static function files()
    {
        $paths = glob(SCHEMAPRESS_PATH . self::SOURCE_DIR . '/*.md') ?: [];

        sort($paths);

        /**
         * filters the documentation sources.
         *
         * @param string[] $paths
         */
        return apply_filters('schemapress/docs/files', $paths);
    }

    /**
     * the compiled Markdown: every source joined, with live values filled in.
     *
     * @return string
     */
    public static function source()
    {
        $parts = [];

        foreach (self::files() as $path) {
            $contents = file_get_contents($path);

            if ($contents !== false) {
                $parts[] = trim($contents);
            }
        }

        return strtr(implode("\n\n", $parts), [
            '%%timber_status%%' => self::timberStatus(),
        ]);
    }

    /**
     * the compiled documentation as HTML, with heading anchors.
     *
     * @return string
     */
    public static function html()
    {
        $markdown = self::source();

        if ($markdown === '') {
            return '<p>' . esc_html__('No documentation was found.', 'schemapress') . '</p>';
        }

        return self::anchors(self::parse($markdown));
    }

    /**
     * renders Markdown to HTML.
     *
     * HTML in the source is allowed rather than escaped: the sources are files
     * this plugin ships, not anything a user submits, and the status callout is
     * written as markup.
     *
     * without a parser the raw Markdown is shown instead. that is a degraded
     * page, not a broken one — Markdown is designed to be readable unrendered,
     * and the same `composer install` that fixes it is required for the plugin
     * to render at all.
     *
     * @param string $markdown
     *
     * @return string
     */
    private static function parse($markdown)
    {
        if (!self::parserAvailable()) {
            return '<pre class="sp-docs-raw">' . esc_html($markdown) . '</pre>';
        }

        $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        return (string) $converter->convert($markdown);
    }

    /**
     * whether a Markdown parser is installed.
     *
     * @return boolean
     */
    public static function parserAvailable()
    {
        return class_exists('League\\CommonMark\\GithubFlavoredMarkdownConverter');
    }

    /**
     * gives every second- and third-level heading an id, so the contents list
     * can link to it.
     *
     * @param string $html
     *
     * @return string
     */
    private static function anchors($html)
    {
        // the docs are full of markup samples, and a sample containing an <h2>
        // is not a heading of this page. code blocks are held out of the scan
        // rather than trusting every fence to have been escaped
        $parts = preg_split('/(<pre\b.*?<\/pre>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';

        foreach ($parts as $part) {
            if (strncmp($part, '<pre', 4) === 0) {
                $out .= $part;

                continue;
            }

            $out .= preg_replace_callback(
                '/<h([23])>(.*?)<\/h\1>/s',
                function ($match) {
                    $text = trim(wp_strip_all_tags($match[2]));

                    return sprintf(
                        '<h%1$s id="%2$s">%3$s</h%1$s>',
                        $match[1],
                        esc_attr(sanitize_title($text)),
                        $match[2]
                    );
                },
                $part
            );
        }

        return $out;
    }

    /**
     * the sidebar, built from the compiled headings.
     *
     * topics come from the h2s and their subheadings nest under them, so the
     * sidebar mirrors the shape of the docs directory without restating it.
     *
     * @param string $html
     *
     * @return string
     */
    private static function nav($html)
    {
        $found = preg_match_all(
            '/<h([23]) id="([^"]+)">(.*?)<\/h\1>/s',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        if (!$found) {
            return '';
        }

        $items = '';
        $topic = false;
        $sub = false;

        foreach ($matches as $match) {
            $link = sprintf(
                '<a href="#%s">%s</a>',
                esc_attr($match[2]),
                esc_html(trim(wp_strip_all_tags($match[3])))
            );

            if ($match[1] === '2') {
                // close whatever the previous topic left open. a topic with no
                // subheadings still has an <li> waiting to be closed, which is
                // why this cannot key off the sublist alone
                $items .= $sub ? '</ul>' : '';
                $items .= $topic ? '</li>' : '';
                $items .= '<li class="sp-docs-nav__topic">' . $link;

                $topic = true;
                $sub = false;

                continue;
            }

            // a subheading before any heading has nothing to nest under
            if (!$topic) {
                continue;
            }

            $items .= $sub ? '' : '<ul class="sp-docs-nav__sub">';
            $items .= '<li>' . $link . '</li>';
            $sub = true;
        }

        $items .= $sub ? '</ul>' : '';
        $items .= $topic ? '</li>' : '';

        // subheadings with no topic above them leave nothing to list, and an
        // empty sidebar is a column of whitespace beside the content
        if ($items === '') {
            return '';
        }

        return sprintf(
            '<nav class="sp-docs-nav" aria-label="%s"><ul>%s</ul></nav>',
            esc_attr__('Documentation', 'schemapress'),
            $items
        );
    }

    /**
     * marks the section currently in view in the sidebar.
     *
     * a link that does not track the page is a link you stop trusting, and on a
     * page this long the reader otherwise loses their place. plain DOM, no
     * dependency: this screen deliberately loads no bundle.
     *
     * @return void
     */
    private static function script()
    {
        echo "<script>
        (function () {
            var nav = document.querySelector('.sp-docs-nav');

            if (!nav || !window.IntersectionObserver) {
                return;
            }

            var links = {};

            nav.querySelectorAll('a[href^=\"#\"]').forEach(function (link) {
                links[decodeURIComponent(link.hash.slice(1))] = link;
            });

            var headings = Object.keys(links)
                .map(function (id) { return document.getElementById(id); })
                .filter(Boolean);

            if (!headings.length) {
                return;
            }

            var visible = new Set();

            var mark = function () {
                var current = headings.filter(function (heading) {
                    return visible.has(heading.id);
                })[0];

                if (!current) {
                    return;
                }

                nav.querySelectorAll('a').forEach(function (link) {
                    link.classList.remove('is-current');
                });

                links[current.id].classList.add('is-current');
            };

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        visible.add(entry.target.id);
                    } else {
                        visible.delete(entry.target.id);
                    }
                });

                mark();
            }, { rootMargin: '-60px 0px -70% 0px' });

            headings.forEach(function (heading) { observer.observe(heading); });
        }());
        </script>";
    }

    // --- live values ---------------------------------------------------------

    /**
     * whether this install can render, as a callout.
     *
     * @return string
     */
    private static function timberStatus()
    {
        $major = Timber::major();

        if (Timber::available()) {
            return sprintf(
                '<p class="sp-status sp-status--ok">%s</p>',
                esc_html(sprintf(
                    /* translators: %d: Timber major version */
                    __('Timber %d is loaded. The Twig functions are available.', 'schemapress'),
                    $major
                ))
            );
        }

        // not an error: the PHP API works either way. this only says which half
        // of the reading surface this install has
        if ($major > 0) {
            $message = sprintf(
                /* translators: 1: loaded major version, 2: required major version */
                __(
                    'Timber %1$d is loaded, but the Twig functions need Timber %2$d. The PHP API is unaffected.',
                    'schemapress'
                ),
                $major,
                Timber::REQUIRES
            );
        } else {
            $message = __(
                'Timber is not installed, so the Twig functions are not registered. The PHP API works without it.',
                'schemapress'
            );
        }

        return sprintf('<p class="sp-status sp-status--note">%s</p>', esc_html($message));
    }

    // --- presentation --------------------------------------------------------

    /**
     * styles for this screen.
     *
     * inline because the page loads no bundle of its own — and must still read
     * correctly when the bundle is exactly what is broken.
     *
     * @return void
     */
    private static function styles()
    {
        echo '<style>
        .schemapress-docs {
            --fg: #16181d; --muted: #5c6370; --faint: #8a909c;
            --line: #e6e8ec; --bg: #fff; --sunk: #f7f8fa;
            --accent: #3858e9; --radius: 10px;

            margin: 0 0 0 -20px; padding: 0 2.5rem 5rem;
            background: var(--bg); color: var(--fg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .schemapress-docs *, .schemapress-docs *::before, .schemapress-docs *::after {
            box-sizing: border-box;
        }

        /* --- header --- */
        .schemapress-docs .sp-docs-head {
            max-width: 46rem; padding: 3rem 0 2.25rem; margin-bottom: 2.5rem;
            border-bottom: 1px solid var(--line);
        }
        .schemapress-docs .sp-docs-head h1 {
            margin: 0 0 .6rem; padding: 0;
            font-size: 2.25rem; font-weight: 660; letter-spacing: -.028em; line-height: 1.1;
        }
        .schemapress-docs .sp-docs-head p {
            margin: 0; max-width: 40rem;
            font-size: 1.0625rem; line-height: 1.6; color: var(--muted);
        }

        /* --- layout --- */
        .schemapress-docs .sp-docs-layout {
            display: grid; gap: 3.5rem;
            grid-template-columns: minmax(0, 1fr);
        }
        @media (min-width: 1100px) {
            .schemapress-docs .sp-docs-layout {
                grid-template-columns: 15rem minmax(0, 1fr);
            }
        }

        /* --- sidebar --- */
        .schemapress-docs .sp-docs-nav { position: relative; }
        @media (min-width: 1100px) {
            .schemapress-docs .sp-docs-nav {
                position: sticky; top: 46px; align-self: start;
                max-height: calc(100vh - 5rem); overflow-y: auto;
                padding-right: .5rem;
            }
        }
        .schemapress-docs .sp-docs-nav ul { margin: 0; padding: 0; list-style: none; }
        .schemapress-docs .sp-docs-nav li { margin: 0; }
        .schemapress-docs .sp-docs-nav a {
            display: block; padding: .3rem 0 .3rem .85rem;
            border-left: 2px solid var(--line);
            color: var(--muted); font-size: .8125rem; line-height: 1.5;
            text-decoration: none; transition: color .12s, border-color .12s;
        }
        .schemapress-docs .sp-docs-nav a:hover { color: var(--fg); border-left-color: #c9cdd6; }
        .schemapress-docs .sp-docs-nav a:focus { outline: 2px solid var(--accent); outline-offset: -2px; box-shadow: none; }
        .schemapress-docs .sp-docs-nav a.is-current {
            color: var(--accent); border-left-color: var(--accent); font-weight: 550;
        }
        .schemapress-docs .sp-docs-nav__topic > a { font-weight: 550; color: var(--fg); }
        .schemapress-docs .sp-docs-nav__sub a { padding-left: 1.6rem; font-size: .78125rem; }

        /* --- body --- */
        .schemapress-docs .sp-docs-body {
            max-width: 46rem; font-size: .9375rem; line-height: 1.75; color: #2c303a;
        }
        .schemapress-docs .sp-docs-body > *:first-child { margin-top: 0; }
        .schemapress-docs .sp-docs-body p { margin: 0 0 1.1rem; }
        .schemapress-docs .sp-docs-body ul,
        .schemapress-docs .sp-docs-body ol { margin: 0 0 1.1rem; padding-left: 1.35rem; }
        .schemapress-docs .sp-docs-body li { margin: .3rem 0; }
        .schemapress-docs .sp-docs-body strong { font-weight: 600; color: var(--fg); }
        .schemapress-docs .sp-docs-body a { color: var(--accent); text-decoration: none; }
        .schemapress-docs .sp-docs-body a:hover { text-decoration: underline; }

        .schemapress-docs h2 {
            margin: 3.25rem 0 1rem; padding: 0;
            font-size: 1.5rem; font-weight: 640; letter-spacing: -.02em;
            line-height: 1.25; color: var(--fg); scroll-margin-top: 4rem;
        }
        .schemapress-docs h3 {
            margin: 2.25rem 0 .65rem; padding: 0;
            font-size: 1.0625rem; font-weight: 600; letter-spacing: -.01em;
            color: var(--fg); scroll-margin-top: 4rem;
        }

        /* --- code --- */
        .schemapress-docs code {
            padding: .13em .38em; border-radius: 5px;
            background: #eef0f4; color: #22262e;
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, monospace;
            font-size: .855em;
        }
        .schemapress-docs pre {
            margin: 0 0 1.35rem; padding: 1rem 1.15rem; overflow-x: auto;
            border: 1px solid var(--line); border-radius: var(--radius);
            background: var(--sunk);
        }
        .schemapress-docs pre code {
            padding: 0; background: none; border-radius: 0; color: #2c303a;
            font-size: .8125rem; line-height: 1.75;
        }
        .schemapress-docs .sp-docs-raw { white-space: pre-wrap; font-size: .75rem; }

        /* --- tables --- */
        .schemapress-docs table {
            width: 100%; margin: 0 0 1.35rem; border-collapse: separate; border-spacing: 0;
            border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden;
            font-size: .8125rem;
        }
        .schemapress-docs thead th {
            padding: .6rem .85rem; background: var(--sunk);
            border-bottom: 1px solid var(--line);
            font-size: .6875rem; font-weight: 600; letter-spacing: .06em;
            text-transform: uppercase; color: var(--faint); text-align: left;
        }
        .schemapress-docs tbody td {
            padding: .65rem .85rem; border-bottom: 1px solid #f0f1f4;
            vertical-align: top; line-height: 1.6;
        }
        .schemapress-docs tbody tr:last-child td { border-bottom: 0; }
        .schemapress-docs tbody td:first-child code { white-space: nowrap; }
        .schemapress-docs td code { background: #eef0f4; }

        /* --- callouts --- */
        .schemapress-docs .sp-status {
            display: flex; gap: .6rem; align-items: flex-start;
            margin: 0 0 1.35rem; padding: .8rem 1rem;
            border: 1px solid var(--line); border-radius: var(--radius);
            font-size: .875rem; line-height: 1.6;
        }
        .schemapress-docs .sp-status::before {
            content: ""; flex: none; width: 8px; height: 8px; margin-top: .48rem;
            border-radius: 50%;
        }
        .schemapress-docs .sp-status--ok {
            background: #f2fbf5; border-color: #cdeed8; color: #14562c;
        }
        .schemapress-docs .sp-status--ok::before { background: #1a9e4b; }
        .schemapress-docs .sp-status--error {
            background: #fef6f6; border-color: #f6d4d5; color: #7a1d1f;
        }
        .schemapress-docs .sp-status--error::before { background: #d63638; }

        .schemapress-docs blockquote {
            margin: 0 0 1.35rem; padding: .1rem 0 .1rem 1.1rem;
            border-left: 3px solid var(--line); color: var(--muted);
        }
        .schemapress-docs blockquote p:last-child { margin-bottom: 0; }
        .schemapress-docs hr { margin: 2.5rem 0; border: 0; border-top: 1px solid var(--line); }
        .schemapress-docs .notice { max-width: 46rem; margin: 0 0 1.5rem; border-radius: 6px; }
        </style>';
    }
}
