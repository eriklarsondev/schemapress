<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The three date shapes, parsed and written back in one canonical form.
 *
 * A stored date is WALL-CLOCK, not an instant. "The event starts at 19:00" means
 * seven in the evening wherever the event is, and converting that to UTC on the
 * way in means an editor who types 19:00 sees 14:00 when they come back. So
 * nothing here converts anything.
 *
 *   date      2026-09-08
 *   time      19:00:00
 *   datetime  2026-09-08T19:00:00
 *
 * The forms are ISO-8601 local time because they sort: comparing two as strings
 * gives the same answer as comparing them as dates, which is why the index can
 * be a CHAR column and `sort=starts_at` works at all.
 *
 * Parsing is strict rather than handing the string to DateTime, which would
 * accept "tomorrow", "+1 week" and "now" and store whatever they meant at the
 * moment of the save.
 */
class Dates
{
    /**
     * An INSTANT, as ISO-8601 in UTC — the exception to everything above.
     *
     * `publishedAt` and `updatedAt` record a moment rather than a plan.
     * WordPress keeps those as "Y-m-d H:i:s", which is a real timestamp in a
     * shape no client can read: `new Date("2026-09-08 09:35:00")` is an Invalid
     * Date in Safari and local time everywhere else.
     *
     * A stamp that already names its zone is passed through.
     *
     * @param mixed $value
     *
     * @return string YYYY-MM-DDTHH:MM:SSZ, or ''
     */
    public static function iso($value)
    {
        $value = trim((string) $value);

        // WordPress's own empty date, which is not a moment
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return '';
        }

        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return str_replace(' ', 'T', $value);
        }

        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $value, $parts)) {
            return '';
        }

        return $parts[1] . 'T' . $parts[2] . 'Z';
    }

    /**
     * A unix timestamp as ISO-8601 in UTC.
     *
     * `_wp_trash_meta_time` is a unix timestamp rather than the "Y-m-d H:i:s"
     * every other date on a post uses, so iso() cannot read it.
     *
     * @param mixed $value
     *
     * @return string YYYY-MM-DDTHH:MM:SSZ, or ''
     */
    public static function instant($value)
    {
        $stamp = is_numeric($value) ? (int) $value : 0;

        return $stamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $stamp) : '';
    }

    /**
     * Accepts what `<input type="date">` sends.
     *
     * @param mixed $value
     *
     * @return string YYYY-MM-DD, or ''
     */
    public static function date($value)
    {
        $value = trim((string) $value);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            return '';
        }

        // checkdate, so 2026-02-31 is refused rather than rolled forward into
        // March — a silently shifted date is worse than an empty one
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : '';
    }

    /**
     * Accepts what `<input type="time">` sends, with or without seconds.
     *
     * @param mixed $value
     *
     * @return string HH:MM:SS, or ''
     */
    public static function time($value)
    {
        $value = trim((string) $value);

        if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $parts)) {
            return '';
        }

        $hours = (int) $parts[1];
        $minutes = (int) $parts[2];
        $seconds = isset($parts[3]) ? (int) $parts[3] : 0;

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return '';
        }

        // filled in rather than optional, so two stored times are always the
        // same length and so always compare as strings
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Accepts what `<input type="datetime-local">` sends, and a full ISO-8601
     * string besides.
     *
     * A trailing Z or offset is read and then dropped: the wall clock in front
     * of it is the value. A client that sends 19:00+02:00 is saying seven in the
     * evening, and that is what is kept.
     *
     * @param mixed $value
     *
     * @return string YYYY-MM-DDTHH:MM:SS, or ''
     */
    public static function datetime($value)
    {
        $value = trim((string) $value);

        $pattern = '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2})?)'
            . '(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/';

        if (!preg_match($pattern, $value, $parts)) {
            return '';
        }

        $date = self::date($parts[1]);
        $time = self::time($parts[2]);

        return $date !== '' && $time !== '' ? $date . 'T' . $time : '';
    }
}
