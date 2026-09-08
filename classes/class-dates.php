<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the three date shapes, parsed and written back in one canonical form.
 *
 * a stored date is WALL-CLOCK, not an instant. "the event starts at 19:00"
 * means seven in the evening wherever the event is, and converting that to UTC
 * on the way in means an editor who types 19:00 sees 14:00 when they come back.
 * so nothing here converts anything: what was typed is what is stored, and the
 * site's timezone is the one it is read in.
 *
 * that is a different thing from `publishedAt`, which IS an instant — the
 * moment somebody pressed publish — and is kept in UTC by WordPress.
 *
 * the canonical forms are ISO-8601 local time, chosen because they sort:
 * comparing two of these as strings gives the same answer as comparing them as
 * dates, which is the whole reason the index can be a CHAR column and a
 * `sort=starts_at` can work at all.
 *
 *   date      2026-09-08
 *   time      19:00:00
 *   datetime  2026-09-08T19:00:00
 *
 * parsing is deliberately strict rather than handing the string to DateTime,
 * which would accept "tomorrow", "+1 week" and "now" and store whatever they
 * meant at the moment of the save. a value that is not a timestamp is stored as
 * nothing, which is the rule `email` already follows — see FieldTypes.
 */
class Dates
{
    /**
     * an INSTANT, as ISO-8601 in UTC.
     *
     * the exception to everything above, and the reason it is worth stating:
     * `publishedAt` and `updatedAt` record a moment — when somebody pressed
     * publish, when the row last changed — rather than a plan. WordPress keeps
     * those in UTC as "Y-m-d H:i:s", which is a real timestamp written in a
     * shape no client can read: `new Date("2026-09-08 09:35:00")` is an Invalid
     * Date in Safari and local time everywhere else, so the same response tells
     * two browsers different things.
     *
     * a stamp that already names its zone is passed through, so a row written
     * before this existed and one written after both come out the same.
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
     * accepts what `<input type="date">` sends.
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
        // March — a silently shifted date is worse than an empty one, because
        // nothing about it looks wrong later
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : '';
    }

    /**
     * accepts what `<input type="time">` sends, with or without seconds.
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

        // seconds are filled in rather than kept optional, so two stored times
        // are always the same length and so always compare as strings
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * accepts what `<input type="datetime-local">` sends, and a full ISO-8601
     * string besides, so an API client can write one.
     *
     * a trailing Z or offset is READ and then dropped: the wall clock in front
     * of it is the value, because that is what the field means. a client that
     * sends 19:00+02:00 is saying seven in the evening, and seven in the
     * evening is what is kept.
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
