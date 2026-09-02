<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * English singular/plural forms.
 *
 * a collection is named for one of the things in it — Team Member, not Team
 * Members — because every other name follows from that: the machine key, the
 * post type, the "New Team Member" button. The plural is derived rather than
 * asked for, and only ever used where a plural genuinely reads better: the
 * sidebar, a listing heading, and later a REST route.
 *
 * the rules are ordered, most specific first, the way Rails' inflector does it.
 * they are not complete English — nothing short of a dictionary is — but they
 * cover the shapes content types actually get named, and anything they get
 * wrong is a label, not data.
 */
class Inflector
{
    /**
     * words whose plural is not formed by rule.
     *
     * @var array<string, string>
     */
    private static $irregular = [
        'person' => 'people',
        'child' => 'children',
        'man' => 'men',
        'woman' => 'women',
        'tooth' => 'teeth',
        'foot' => 'feet',
        'mouse' => 'mice',
        'goose' => 'geese',
        'ox' => 'oxen',
        'datum' => 'data',
        'medium' => 'media',
        'index' => 'indices',
        'matrix' => 'matrices',
        'vertex' => 'vertices',
        'analysis' => 'analyses',
        'criterion' => 'criteria',
    ];

    /**
     * words that are the same in both forms, or have no plural.
     *
     * @var string[]
     */
    private static $uncountable = [
        'news', 'series', 'species', 'equipment', 'information', 'staff',
        'content', 'fish', 'sheep', 'deer', 'aircraft', 'software', 'research',
        'feedback', 'evidence', 'furniture',
    ];

    /**
     * plural rules, most specific first.
     *
     * @var array<string, string>
     */
    private static $plural = [
        '/(quiz)$/i' => '$1zes',
        '/([^aeiouy]|qu)y$/i' => '$1ies',
        '/(x|ch|ss|sh|s|z)$/i' => '$1es',
        '/(?:([^f])fe|([lr])f)$/i' => '$1$2ves',
        '/([^aeiou])o$/i' => '$1oes',
        '/$/' => 's',
    ];

    /**
     * singular rules, most specific first.
     *
     * @var array<string, string>
     */
    private static $singular = [
        '/(quiz)zes$/i' => '$1',
        '/([^aeiouy]|qu)ies$/i' => '$1y',
        '/([^f])ves$/i' => '$1fe',
        '/([lr])ves$/i' => '$1f',
        '/(x|ch|ss|sh|z)es$/i' => '$1',
        '/([^aeiou])oes$/i' => '$1o',
        '/([^s])s$/i' => '$1',
    ];

    /**
     * words that end in "s" but are already singular, so the trailing-s rule
     * must not strip it.
     *
     * @var string[]
     */
    private static $singularEndingInS = [
        'status', 'campus', 'bonus', 'focus', 'virus', 'census', 'bias',
        'canvas', 'atlas', 'lens', 'address', 'process', 'class', 'business',
    ];

    /**
     * the plural of a word.
     *
     * @param string $word
     *
     * @return string
     */
    public static function pluralize($word)
    {
        $word = (string) $word;

        if ($word === '' || self::isUncountable($word)) {
            return $word;
        }

        $lower = strtolower($word);

        if (isset(self::$irregular[$lower])) {
            return self::match(self::$irregular[$lower], $word);
        }

        // already plural: pluralizing twice is how you get "peoples"
        if (self::isPlural($word)) {
            return $word;
        }

        foreach (self::$plural as $rule => $replacement) {
            if (preg_match($rule, $word)) {
                return preg_replace($rule, $replacement, $word);
            }
        }

        return $word;
    }

    /**
     * the singular of a word.
     *
     * @param string $word
     *
     * @return string
     */
    public static function singularize($word)
    {
        $word = (string) $word;

        if ($word === '' || self::isUncountable($word)) {
            return $word;
        }

        $lower = strtolower($word);

        foreach (self::$irregular as $singular => $plural) {
            if ($lower === $plural) {
                return self::match($singular, $word);
            }
        }

        if (in_array($lower, self::$singularEndingInS, true)) {
            return $word;
        }

        foreach (self::$singular as $rule => $replacement) {
            if (preg_match($rule, $word)) {
                return preg_replace($rule, $replacement, $word);
            }
        }

        return $word;
    }

    /**
     * whether a word already reads as a plural.
     *
     * used to spot "Team Members" typed where "Team Member" was meant, so the
     * interface can say so rather than silently naming the post type
     * `spc_team_members` and every button "New Team Members".
     *
     * @param string $word
     *
     * @return boolean
     */
    public static function isPlural($word)
    {
        $word = (string) $word;
        $lower = strtolower($word);

        if ($word === '' || self::isUncountable($word)) {
            return false;
        }

        if (in_array($lower, self::$irregular, true)) {
            return true;
        }

        if (isset(self::$irregular[$lower]) || in_array($lower, self::$singularEndingInS, true)) {
            return false;
        }

        // the honest test: if singularizing changes it, it was plural
        return strtolower(self::singularize($word)) !== $lower;
    }

    /**
     * whether a word has no separate plural.
     *
     * @param string $word
     *
     * @return boolean
     */
    public static function isUncountable($word)
    {
        return in_array(strtolower((string) $word), self::$uncountable, true);
    }

    /**
     * applies a form to the last word of a phrase, leaving the rest alone.
     *
     * "Team Member" pluralizes on "Member"; "News Article" on "Article". Only
     * the head noun changes, which is what makes multi-word names work.
     *
     * @param string   $phrase
     * @param callable $transform
     *
     * @return string
     */
    public static function lastWord($phrase, callable $transform)
    {
        $phrase = trim((string) $phrase);

        if ($phrase === '') {
            return $phrase;
        }

        $parts = preg_split('/(\s+|_)/', $phrase, -1, PREG_SPLIT_DELIM_CAPTURE);
        $last = count($parts) - 1;

        $parts[$last] = $transform($parts[$last]);

        return implode('', $parts);
    }

    /**
     * carries the casing of the original onto a replacement word.
     *
     * @param string $replacement
     * @param string $original
     *
     * @return string
     */
    private static function match($replacement, $original)
    {
        if ($original === strtoupper($original)) {
            return strtoupper($replacement);
        }

        if ($original === ucfirst(strtolower($original))) {
            return ucfirst($replacement);
        }

        return $replacement;
    }
}
