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
 * the same compiled text is served two ways. normally it is a screen inside the
 * React app, which is where the reader already is — the sidebar stays, and
 * nothing about reading the docs means leaving the builder. when the bundle has
 * not been built there is no app to put it in, so the standalone page below
 * renders it server-side instead: that is exactly the state whose way out the
 * documentation describes, so it must not depend on the thing that is missing.
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
     * registers the fallback page, when there is a reason to have one.
     *
     * normally there is not: the docs are a screen in the app, reached from the
     * app's own sidebar, and a second entry in wp-admin's menu would only be a
     * different way into the same text. without a bundle there is no app to
     * hold it, so the page below is registered instead — that is exactly the
     * state whose way out the documentation describes.
     *
     * @return void
     */
    public function registerMenu()
    {
        if (Assets::built('admin')) {
            return;
        }

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
     * the documentation as the app consumes it: one entry per source file, in
     * the order the directory names, each already rendered to HTML.
     *
     * the split is by file rather than by heading because a file is the unit
     * the docs are written in — a new topic is a new file, and the contents
     * list picks it up without anything here being edited.
     *
     * @return array
     */
    public static function forClient()
    {
        return [
            'sections' => self::sections(),
            'parser' => self::parserAvailable(),
        ];
    }

    /**
     * every source file as {id, title, html, headings}.
     *
     * the leading heading of a file is lifted out of its body: the app renders
     * it as the section's own title, and leaving it in the HTML would print it
     * twice.
     *
     * @return array
     */
    public static function sections()
    {
        $sections = [];

        foreach (self::files() as $path) {
            $markdown = trim((string) file_get_contents($path));

            if ($markdown === '') {
                continue;
            }

            $group = self::meta($markdown, 'group');
            $description = self::meta($markdown, 'description');

            // the declaration is metadata, not content. left in, it renders as
            // an HTML comment ahead of the heading — which is invisible on the
            // page and still enough to stop the title being found
            $body = preg_replace('/<!--\s*(?:group|description):.*?-->\s*/is', '', $markdown);

            $html = self::anchors(self::parse(strtr($body, [
                '%%timber_status%%' => self::timberStatus(),
            ])));

            $name = preg_replace('/^\d+[-_]/', '', basename($path, '.md'));
            $id = sanitize_title($name);
            $title = ucfirst(str_replace('-', ' ', $name));

            if (preg_match('/^\s*<h2 id="([^"]+)">(.*?)<\/h2>/s', $html, $match)) {
                $id = $match[1];
                $title = self::text($match[2]);
                $html = substr($html, strlen($match[0]));
            }

            $sections[] = [
                'id' => $id,
                'title' => $title,
                'group' => $group,
                'description' => $description,
                'headings' => self::headings($html),
                'html' => $html,
            ];
        }

        return $sections;
    }

    /**
     * the readable text of a fragment of HTML.
     *
     * tags off and entities back: a heading reaches the app as a string in a
     * JSON payload, which is printed rather than parsed, so `&amp;` left in it
     * shows up as `&amp;` on the screen.
     *
     * @param string $html
     *
     * @return string
     */
    private static function text($html)
    {
        return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * a value a file declares about itself, on a line of its own:
     *
     *   <!-- group: Content API -->
     *   <!-- description: The addresses, and what may be asked of them. -->
     *
     * in the file rather than in a list here, so adding a topic stays a matter
     * of adding a file — the same bargain the ordering makes with its numeric
     * prefixes.
     *
     * the description is written rather than lifted from the prose. the first
     * paragraph of a page is written to follow its heading, not to stand alone
     * on a card somewhere else, and on a page that opens with a table or a
     * callout there is no first paragraph to lift at all.
     *
     * @param string $markdown
     * @param string $name
     *
     * @return string
     */
    private static function meta($markdown, $name)
    {
        $pattern = '/<!--\s*' . preg_quote($name, '/') . ':\s*([^>]+?)\s*-->/i';

        return preg_match($pattern, $markdown, $match) ? trim($match[1]) : '';
    }

    /**
     * the subheadings of one section, for the contents list.
     *
     * only ids anchors() added are matched, and it leaves code samples alone —
     * so an <h3> inside a sample is not mistaken for a heading of the page.
     *
     * @param string $html
     *
     * @return array
     */
    private static function headings($html)
    {
        preg_match_all('/<h3 id="([^"]+)">(.*?)<\/h3>/s', $html, $matches, PREG_SET_ORDER);

        $headings = [];

        foreach ($matches as $match) {
            $headings[] = [
                'id' => $match[1],
                'title' => self::text($match[2]),
            ];
        }

        return $headings;
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

        // both are lifted out first and put back after, so the fences never
        // reach the parser and what is inside one is still rendered properly.
        // a raw HTML block would have made its contents literal.
        //
        // tabs before callouts: a tab group holds code fences, and lifting it
        // first keeps those fences from being seen by anything else
        $blocks = [];
        $markdown = self::liftTabs($markdown, $blocks);
        $markdown = self::liftCallouts($markdown, $blocks);

        $html = (string) self::converter()->convert($markdown);

        return self::trimSamples(strtr($html, $blocks));
    }

    /**
     * drops the newline a fenced block leaves at the end of its code.
     *
     * CommonMark keeps the fence's final line break inside the <code>, and a
     * <pre> renders it — so every sample sat on a blank line it did not ask
     * for. the browser only ignores a newline immediately AFTER the opening
     * tag, never one before the closing one, which is why this has to be
     * removed rather than left to the renderer.
     *
     * @param string $html
     *
     * @return string
     */
    private static function trimSamples($html)
    {
        return (string) preg_replace('/\n+(<\/code><\/pre>)/', '$1', $html);
    }

    /**
     * a configured converter.
     *
     * @return \League\CommonMark\GithubFlavoredMarkdownConverter
     */
    private static function converter()
    {
        return new \League\CommonMark\GithubFlavoredMarkdownConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * the callout kinds a page may use, and the word each is labelled with.
     *
     * the syntax is Docusaurus's, which is what the documentation this reads
     * like is written in:
     *
     *   :::note
     *   Something worth knowing.
     *   :::
     *
     * @var array<string, string>
     */
    private static function callouts()
    {
        return [
            'note' => __('Note', 'schemapress'),
            'tip' => __('Tip', 'schemapress'),
            'info' => __('Info', 'schemapress'),
            'caution' => __('Caution', 'schemapress'),
            'warning' => __('Warning', 'schemapress'),
        ];
    }

    /**
     * replaces every tab group with a placeholder holding its rendered panes.
     *
     * one operation, shown the way each surface spells it — the reader picks the
     * one they are working in rather than reading past two that do not apply:
     *
     *   :::tabs
     *   ```php PHP
     *   Content::collection('team_member')->get();
     *   ```
     *   ```twig Twig
     *   {% for p in sp_collection('team_member') %}
     *   ```
     *   :::
     *
     * the word after the language is the tab's label, and it is optional — the
     * language's own display name is used when it is left off. the fences are
     * read here rather than handed to the parser because CommonMark keeps only
     * the first word of an info string, which is exactly the word that is not
     * the label.
     *
     * @param string $markdown
     * @param array  $map placeholder => html, filled by reference
     *
     * @return string
     */
    private static function liftTabs($markdown, array &$map)
    {
        return (string) preg_replace_callback(
            '/^:::tabs[ \t]*\n(.*?)^:::[ \t]*$/ms',
            function ($match) use (&$map) {
                $panes = self::panes($match[1]);

                if (!$panes) {
                    return '';
                }

                $token = '<!--sp-block-' . count($map) . '-->';
                $map[$token] = '<div class="sp-tabs">' . implode('', $panes) . '</div>';

                return $token;
            },
            $markdown
        );
    }

    /**
     * the panes of one tab group.
     *
     * @param string $body the markdown between the :::tabs fences
     *
     * @return string[] one div per pane
     */
    private static function panes($body)
    {
        preg_match_all(
            '/^```([A-Za-z0-9_+-]+)[ \t]*([^\n]*)\n(.*?)^```[ \t]*$/ms',
            $body,
            $matches,
            PREG_SET_ORDER
        );

        $panes = [];

        foreach ($matches as $fence) {
            $language = strtolower($fence[1]);
            $label = trim($fence[2]) !== '' ? trim($fence[2]) : self::languageName($language);

            $panes[] = sprintf(
                '<div class="sp-tab" data-label="%1$s"><pre><code class="language-%2$s">%3$s</code></pre></div>',
                esc_attr($label),
                esc_attr($language),
                esc_html(rtrim($fence[3], "\n"))
            );
        }

        return $panes;
    }

    /**
     * a language's display name, for a tab that did not name itself.
     *
     * @param string $language
     *
     * @return string
     */
    private static function languageName($language)
    {
        $names = [
            'php' => 'PHP',
            'twig' => 'Twig',
            'js' => 'JavaScript',
            'javascript' => 'JavaScript',
            'json' => 'JSON',
            'bash' => 'Shell',
            'sh' => 'Shell',
            'http' => 'REST',
        ];

        return $names[$language] ?? strtoupper($language);
    }

    /**
     * replaces every callout fence with a placeholder, rendering its contents.
     *
     * @param string $markdown
     * @param array  $map placeholder => html, filled by reference
     *
     * @return string the markdown with placeholders in place of the fences
     */
    private static function liftCallouts($markdown, array &$map)
    {
        $kinds = self::callouts();
        $names = implode('|', array_keys($kinds));

        return (string) preg_replace_callback(
            '/^:::(' . $names . ')(?:[ \t]+([^\n]*))?\n(.*?)^:::[ \t]*$/ms',
            function ($match) use (&$map, $kinds) {
                $kind = $match[1];
                $title = trim($match[2] ?? '') !== '' ? trim($match[2]) : $kinds[$kind];
                $token = '<!--sp-block-' . count($map) . '-->';

                $map[$token] = sprintf(
                    '<div class="sp-callout sp-callout--%1$s">'
                        . '<p class="sp-callout__title">%2$s</p>'
                        . '<div class="sp-callout__body">%3$s</div>'
                        . '</div>',
                    esc_attr($kind),
                    esc_html($title),
                    (string) self::converter()->convert($match[3])
                );

                return $token;
            },
            $markdown
        );
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
            /* the same palette the app uses, written as hex because this page
               has no bundle to read variables from — the hues and lightnesses
               are the tokens in src/shared/style.css, converted. keep the two
               in step: a docs page in last season\'s greys reads as a different
               product. every pair here is measured, including --faint on
               --sunk, which is the small uppercase type in table headers and
               the one that had been failing */
            --fg: #10141e; --muted: #535c6e; --faint: #667085;
            --line: #cfd4de; --hairline: #e0e4eb; --bg: #fff; --sunk: #f3f4f7;
            --code: #e6e9ef; --accent: #214dc4; --radius: 10px;

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
        .schemapress-docs .sp-docs-nav a:hover { color: var(--fg); border-left-color: #abb3c4; }
        .schemapress-docs .sp-docs-nav a:focus { outline: 2px solid var(--accent); outline-offset: -2px; box-shadow: none; }
        .schemapress-docs .sp-docs-nav a.is-current {
            color: var(--accent); border-left-color: var(--accent); font-weight: 550;
        }
        .schemapress-docs .sp-docs-nav__topic > a { font-weight: 550; color: var(--fg); }
        .schemapress-docs .sp-docs-nav__sub a { padding-left: 1.6rem; font-size: .78125rem; }

        /* --- body --- */
        .schemapress-docs .sp-docs-body {
            max-width: 46rem; font-size: .9375rem; line-height: 1.75; color: var(--fg);
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
            background: var(--code); color: var(--fg);
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, monospace;
            font-size: .855em;
        }
        .schemapress-docs pre {
            margin: 0 0 1.35rem; padding: 1rem 1.15rem; overflow-x: auto;
            border: 1px solid var(--line); border-radius: var(--radius);
            background: var(--sunk);
        }
        .schemapress-docs pre code {
            padding: 0; background: none; border-radius: 0; color: var(--fg);
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
            padding: .65rem .85rem; border-bottom: 1px solid var(--hairline);
            vertical-align: top; line-height: 1.6;
        }
        .schemapress-docs tbody tr:last-child td { border-bottom: 0; }
        .schemapress-docs tbody td:first-child code { white-space: nowrap; }
        .schemapress-docs td code { background: var(--code); }

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
            background: #ecfdf5; border-color: #6ee7b7; color: #064e3b;
        }
        .schemapress-docs .sp-status--ok::before { background: #059669; }
        .schemapress-docs .sp-status--error {
            background: #fef2f2; border-color: #fca5a5; color: #7f1d1d;
        }
        .schemapress-docs .sp-status--error::before { background: #dc2626; }

        /* emitted by summary() and, until now, styled nowhere: a note fell
           through to the bare bordered paragraph with a grey dot */
        .schemapress-docs .sp-status--note {
            background: #f0f9ff; border-color: #7dd3fc; color: #0c4a6e;
        }
        .schemapress-docs .sp-status--note::before { background: #0284c7; }

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
