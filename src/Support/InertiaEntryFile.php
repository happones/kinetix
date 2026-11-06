<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Edits to the host's Inertia entry file (`resources/js/app.ts`) that need to
 * understand its JavaScript: a JS-aware scanner that skips strings, template
 * literals (with their `${…}` expressions), comments and regex literals, and
 * tracks `()[]{}` depth. Every edit targets the properties at the top level of
 * the FIRST `createInertiaApp({ … })` object. It never guesses: anything it
 * cannot read unambiguously returns null, and the caller writes nothing.
 *
 * @internal
 */
final class InertiaEntryFile
{
    private const PUNCTUATION = '()[]{},:;';

    /** Tokens after which a `/` starts a regex literal rather than a division. */
    private const REGEX_PRECEDERS = ['(', ',', '=', ':', '[', '!', '&', '|', '?', '{', '}', ';', '+', '-', '*', '%', '<', '>', '~', '^'];

    /** Keywords after which a `/` starts a regex literal. */
    private const REGEX_KEYWORDS = ['return', 'typeof', 'case', 'do', 'else', 'in', 'of', 'new', 'delete', 'void', 'throw', 'await', 'yield'];

    /**
     * @var list<array{type: string, start: int, end: int, text: string}>
     */
    private array $tokens = [];

    private function __construct(private readonly string $source) {}

    /**
     * Which of `resolve`, `setup` and `withApp` the first
     * `createInertiaApp({ … })` object declares (each at most once). Null when
     * the file can't be scanned, there's no such call, one of them is
     * declared twice, or any property can't be read for sure — a spread
     * (`...base` may hold a `setup`), an `async setup()` or a getter, a
     * computed key: what the object really declares is then unknown.
     *
     * @return array{resolve: bool, setup: bool, withApp: bool}|null
     */
    public static function describe(string $source): ?array
    {
        $object = self::scan($source)?->appObject();

        if ($object === null) {
            return null;
        }

        $found = ['resolve' => 0, 'setup' => 0, 'withApp' => 0];

        foreach ($object['properties'] as $property) {
            if ($property['key'] === null || $property['kind'] === 'other') {
                return null;
            }

            if (array_key_exists($property['key'], $found)) {
                $found[$property['key']]++;
            }
        }

        if (max($found) > 1) {
            return null;
        }

        return ['resolve' => $found['resolve'] === 1, 'setup' => $found['setup'] === 1, 'withApp' => $found['withApp'] === 1];
    }

    /**
     * Whether the code calls `.use({$name})` (`app.use(pinia)`,
     * `createApp(…).use(pinia)`), in comments and strings excluded.
     */
    public static function callsUse(string $source, string $name): bool
    {
        $scanner = self::scan($source);

        if ($scanner === null) {
            return false;
        }

        $count = count($scanner->tokens);

        for ($i = 0; $i + 3 < $count; $i++) {
            if ($scanner->is($i, 'ident', 'use') && $scanner->is($i + 1, 'punct', '(') && $scanner->is($i + 2, 'ident', $name) && $scanner->is($i + 3, 'punct', ')')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wrap the value of the `resolve:` property in `$wrapper(…)`. Null when
     * there is no `resolve: <value>` (a method or shorthand `resolve` doesn't
     * count), or more than one.
     */
    public static function wrapResolve(string $source, string $wrapper): ?string
    {
        $property = self::scan($source)?->property('resolve');

        if ($property === null || $property['kind'] !== 'value') {
            return null;
        }

        return substr($source, 0, $property['valueStart'])
            .$wrapper.'('.substr($source, $property['valueStart'], $property['valueEnd'] - $property['valueStart']).')'
            .substr($source, $property['valueEnd']);
    }

    /**
     * Add a property (a method such as `withApp(app) { … }`) after the last
     * one of the object, indented like the others. `$code` is written with no
     * base indentation. Null when the object is empty or its properties don't
     * start their own lines.
     */
    public static function addProperty(string $source, string $code): ?string
    {
        $scanner = self::scan($source);
        $object  = $scanner?->appObject();

        if ($scanner === null || $object === null || $object['properties'] === []) {
            return null;
        }

        $indent = $scanner->lineIndent($object['properties'][0]['start']);

        if ($indent === null) {
            return null;
        }

        $last = $object['properties'][count($object['properties']) - 1];

        return substr($source, 0, $last['end']).",\n".self::indent($code, $indent).substr($source, $last['end']);
    }

    /**
     * Insert `$code` at the start of the block body of the function property
     * `$key` (`setup(…) { … }`, `withApp: (app) => { … }`), indented like the
     * body's first line; a trailing "\n" in `$code` leaves a blank line. Null when the property isn't a function with a block
     * body, or the body's first statement shares the brace's line.
     */
    public static function prependToBody(string $source, string $key, string $code): ?string
    {
        $scanner  = self::scan($source);
        $property = $scanner?->property($key);

        if ($scanner === null || $property === null || $property['body'] === null) {
            return null;
        }

        [$open, $close] = $property['body'];
        $brace          = $scanner->tokens[$open];

        $suffix = '';

        if ($open + 1 === $close) {
            // An empty body: the closing brace moves to its own line.
            $outer  = $scanner->lineIndent($property['start']);
            $indent = $outer === null ? null : $outer.'    ';
            $suffix = "\n".$outer;
        } else {
            $indent = $scanner->lineIndent($scanner->tokens[$open + 1]['start']);
        }

        if ($indent === null) {
            return null;
        }

        // The body's own line break follows, so a `$code` ending in "\n"
        // leaves one blank line before what was there.
        return substr($source, 0, $brace['end'])."\n".self::indent(rtrim($code, "\n").(str_ends_with($code, "\n") ? "\n" : ''), $indent).$suffix.substr($source, $brace['end']);
    }

    /**
     * Append `$chain` (`.use(i18n)`) right after the one `createApp(…)` /
     * `createSSRApp(…)` call in the body of the `setup` property. Null when
     * there isn't exactly one such call.
     */
    public static function chainOntoCreateApp(string $source, string $chain): ?string
    {
        $scanner  = self::scan($source);
        $property = $scanner?->property('setup');

        if ($scanner === null || $property === null || $property['body'] === null) {
            return null;
        }

        [$open, $close] = $property['body'];
        $calls          = [];

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $scanner->tokens[$i];

            if ($token['type'] !== 'ident' || ! in_array($token['text'], ['createApp', 'createSSRApp'], true) || ! $scanner->is($i + 1, 'punct', '(')) {
                continue;
            }

            // A method of something else (`foo.createApp(`) isn't Vue's.
            if ($i > 0 && $scanner->tokens[$i - 1]['text'] === '.') {
                continue;
            }

            $calls[] = $i + 1;
        }

        if (count($calls) !== 1) {
            return null;
        }

        $closing = $scanner->matching($calls[0]);

        if ($closing === null || $closing >= $close) {
            return null;
        }

        $end = $scanner->tokens[$closing]['end'];

        return substr($source, 0, $end).$chain.substr($source, $end);
    }

    /**
     * The expression of Inertia's setup `props` (`props` from a destructured
     * `{ props }`, `options.props` from a plain `options` parameter). Null
     * when `setup` isn't a function or doesn't receive them in a form this
     * can read.
     */
    public static function setupPropsExpression(string $source): ?string
    {
        return self::scan($source)?->firstParameterMember('setup', 'props');
    }

    /**
     * The names `withApp(app, { page })` receives: the app and an expression
     * for the page. Null when the parameters aren't `(name, { page })` or
     * `(name, context)`.
     *
     * @return array{app: string, page: string}|null
     */
    public static function withAppParameters(string $source): ?array
    {
        $scanner  = self::scan($source);
        $property = $scanner?->property('withApp');

        if ($scanner === null || $property === null || $property['params'] === null || $property['body'] === null) {
            return null;
        }

        [$open, $close] = $property['params'];
        $groups         = $scanner->parameterGroups($open, $close);

        if (count($groups) !== 2 || $groups[0][0] !== $groups[0][1] || $scanner->tokens[$groups[0][0]]['type'] !== 'ident') {
            return null;
        }

        $page = $scanner->memberOfParameter($groups[1], 'page');

        return $page === null ? null : ['app' => $scanner->tokens[$groups[0][0]]['text'], 'page' => $page];
    }

    private static function scan(string $source): ?self
    {
        $scanner = new self($source);

        return $scanner->tokenize() ? $scanner : null;
    }

    private static function indent(string $code, string $indent): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $indent.$line,
            explode("\n", $code),
        ));
    }

    /**
     * The only top-level property named `$key`, or null (absent or repeated).
     *
     * @return array{key: string|null, kind: string, start: int, end: int, valueStart: int, valueEnd: int, params: array{0: int, 1: int}|null, body: array{0: int, 1: int}|null}|null
     */
    private function property(string $key): ?array
    {
        $object = $this->appObject();

        if ($object === null) {
            return null;
        }

        $matches = array_values(array_filter($object['properties'], static fn (array $property): bool => $property['key'] === $key));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The top-level properties of the first `createInertiaApp({ … })` object.
     *
     * @return array{open: int, close: int, properties: list<array{key: string|null, kind: string, start: int, end: int, valueStart: int, valueEnd: int, params: array{0: int, 1: int}|null, body: array{0: int, 1: int}|null}>}|null
     */
    private function appObject(): ?array
    {
        $count = count($this->tokens);
        $open  = null;

        for ($i = 0; $i + 2 < $count; $i++) {
            if ($this->is($i, 'ident', 'createInertiaApp') && $this->is($i + 1, 'punct', '(') && $this->is($i + 2, 'punct', '{')) {
                $open = $i + 2;

                break;
            }
        }

        if ($open === null) {
            return null;
        }

        $properties = [];
        $i          = $open + 1;

        while ($i < $count) {
            if ($this->is($i, 'punct', '}')) {
                return ['open' => $open, 'close' => $i, 'properties' => $properties];
            }

            if ($this->is($i, 'punct', ',')) {
                $i++;

                continue;
            }

            $property = $this->propertyAt($i);

            if ($property === null) {
                return null;
            }

            [$record, $next] = $property;
            $properties[]    = $record;
            $i               = $next;
        }

        return null;
    }

    /**
     * Read the property starting at token `$i`; the record and the index of
     * the `,` or `}` that ends it.
     *
     * @return array{0: array{key: string|null, kind: string, start: int, end: int, valueStart: int, valueEnd: int, params: array{0: int, 1: int}|null, body: array{0: int, 1: int}|null}, 1: int}|null
     */
    private function propertyAt(int $i): ?array
    {
        $token  = $this->tokens[$i];
        $key    = null;
        $record = ['key' => null, 'kind' => 'other', 'start' => $token['start'], 'end' => $token['end'], 'valueStart' => 0, 'valueEnd' => 0, 'params' => null, 'body' => null];

        if ($token['type'] === 'ident') {
            $key = $token['text'];
        } elseif ($token['type'] === 'string') {
            $key = substr($token['text'], 1, -1);
        }

        $record['key'] = $key;

        if ($key !== null && $this->is($i + 1, 'punct', ':')) {
            $end = $this->delimiterAfter($i + 2);

            if ($end === null || $end === $i + 2) {
                return null;
            }

            $record['kind']                      = 'value';
            $record['valueStart']                = $this->tokens[$i + 2]['start'];
            $record['valueEnd']                  = $this->tokens[$end - 1]['end'];
            $record['end']                       = $record['valueEnd'];
            [$record['params'], $record['body']] = $this->functionParts($i + 2, $end - 1);

            return [$record, $end];
        }

        if ($token['type'] === 'ident' && $this->is($i + 1, 'punct', '(')) {
            $closeParams = $this->matching($i + 1);

            if ($closeParams !== null && $this->is($closeParams + 1, 'punct', '{')) {
                $closeBody = $this->matching($closeParams + 1);

                if ($closeBody === null) {
                    return null;
                }

                $record['kind']   = 'method';
                $record['params'] = [$i + 1, $closeParams];
                $record['body']   = [$closeParams + 1, $closeBody];
                $record['end']    = $this->tokens[$closeBody]['end'];

                $end = $this->delimiterAfter($closeBody + 1);

                return $end === $closeBody + 1 ? [$record, $end] : null;
            }
        }

        if ($key !== null && ($this->is($i + 1, 'punct', ',') || $this->is($i + 1, 'punct', '}'))) {
            $record['kind'] = 'shorthand';

            return [$record, $i + 1];
        }

        // Anything else (a spread, a getter, `async setup()`): known to be
        // there, but never edited.
        $end = $this->delimiterAfter($i);

        if ($end === null) {
            return null;
        }

        $record['end'] = $this->tokens[$end - 1]['end'];

        return [$record, $end];
    }

    /**
     * The parameter and block-body token ranges of a function expression
     * spanning tokens `$from`…`$to` (`(a) => { … }`, `a => { … }`,
     * `function (a) { … }`); nulls for anything else, an expression body
     * included.
     *
     * @return array{0: array{0: int, 1: int}|null, 1: array{0: int, 1: int}|null}
     */
    private function functionParts(int $from, int $to): array
    {
        $params = null;
        $after  = null;

        if ($this->is($from, 'ident', 'function') && $this->is($from + 1, 'punct', '(')) {
            $close = $this->matching($from + 1);

            if ($close !== null) {
                $params = [$from + 1, $close];
                $after  = $close + 1;
            }
        } elseif ($this->is($from, 'punct', '(')) {
            $close = $this->matching($from);

            if ($close !== null && $this->isArrow($close + 1)) {
                $params = [$from, $close];
                $after  = $close + 3;
            }
        } elseif ($this->tokens[$from]['type'] === 'ident' && $this->isArrow($from + 1)) {
            $params = [$from, $from];
            $after  = $from + 3;
        }

        if ($params === null || $after === null || ! $this->is($after, 'punct', '{') || $this->matching($after) !== $to) {
            return [null, null];
        }

        return [$params, [$after, $to]];
    }

    private function isArrow(int $index): bool
    {
        return $this->is($index, 'other', '=')
            && $this->is($index + 1, 'other', '>')
            && $this->tokens[$index]['end'] === $this->tokens[$index + 1]['start'];
    }

    /**
     * The index of the first `,` or object-closing `}` at depth 0 from `$i`.
     */
    private function delimiterAfter(int $i): ?int
    {
        $count = count($this->tokens);

        while ($i < $count) {
            $token = $this->tokens[$i];

            if ($token['type'] === 'punct' && ($token['text'] === ',' || $token['text'] === '}')) {
                return $i;
            }

            if ($token['type'] === 'punct' && str_contains('([{', $token['text'])) {
                $close = $this->matching($i);

                if ($close === null) {
                    return null;
                }

                $i = $close + 1;

                continue;
            }

            if ($token['type'] === 'punct' && str_contains(')]', $token['text'])) {
                return null;
            }

            $i++;
        }

        return null;
    }

    /**
     * The index of the bracket closing the one at `$open`.
     */
    private function matching(int $open): ?int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $stack = [];
        $count = count($this->tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if ($token['type'] !== 'punct') {
                continue;
            }

            if (isset($pairs[$token['text']])) {
                $stack[] = $pairs[$token['text']];

                continue;
            }

            if (str_contains(')]}', $token['text'])) {
                if (array_pop($stack) !== $token['text']) {
                    return null;
                }

                if ($stack === []) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The token ranges of each top-level parameter between `$open` and
     * `$close` (its type annotation excluded).
     *
     * @return list<array{0: int, 1: int}>
     */
    private function parameterGroups(int $open, int $close): array
    {
        $groups = [];
        $start  = $open + 1;
        $end    = null;
        $i      = $open + 1;

        while ($i < $close) {
            $token = $this->tokens[$i];

            if ($token['type'] === 'punct' && str_contains('([{', $token['text'])) {
                $i = ($this->matching($i) ?? $close) + 1;

                continue;
            }

            if ($token['type'] === 'punct' && $token['text'] === ':' && $end === null) {
                $end = $i - 1;
            }

            if ($token['type'] === 'punct' && $token['text'] === ',') {
                $groups[] = [$start, $end ?? $i - 1];
                $start    = $i + 1;
                $end      = null;
            }

            $i++;
        }

        if ($start < $close) {
            $groups[] = [$start, $end ?? $close - 1];
        }

        return $groups;
    }

    /**
     * An expression for member `$member` of a parameter: `member` when the
     * parameter destructures it as is, `name.member` when the parameter is a
     * plain name; null otherwise (renamed, defaulted, nested).
     *
     * @param array{0: int, 1: int} $group
     */
    private function memberOfParameter(array $group, string $member): ?string
    {
        [$from, $to] = $group;

        if ($from === $to && $this->tokens[$from]['type'] === 'ident') {
            return $this->tokens[$from]['text'].'.'.$member;
        }

        if (! $this->is($from, 'punct', '{') || $this->matching($from) !== $to) {
            return null;
        }

        for ($i = $from + 1; $i < $to; $i++) {
            if (! $this->is($i, 'ident', $member)) {
                continue;
            }

            $before = $this->tokens[$i - 1]['text'];
            $after  = $this->tokens[$i + 1]['text'];

            if (in_array($before, ['{', ','], true) && in_array($after, [',', '}'], true)) {
                return $member;
            }

            return null;
        }

        return null;
    }

    private function firstParameterMember(string $key, string $member): ?string
    {
        $property = $this->property($key);

        if ($property === null || $property['params'] === null || $property['body'] === null) {
            return null;
        }

        $groups = $this->parameterGroups($property['params'][0], $property['params'][1]);

        if ($property['params'][0] === $property['params'][1]) {
            $groups = [[$property['params'][0], $property['params'][0]]];
        }

        return count($groups) === 1 ? $this->memberOfParameter($groups[0], $member) : null;
    }

    /**
     * The whitespace before the token at byte `$offset`, when it starts its
     * line; null when something precedes it on that line.
     */
    private function lineIndent(int $offset): ?string
    {
        $lineStart = strrpos(substr($this->source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $prefix    = substr($this->source, $lineStart, $offset - $lineStart);

        return trim($prefix) === '' ? $prefix : null;
    }

    private function is(int $index, string $type, string $text): bool
    {
        $token = $this->tokens[$index] ?? null;

        return $token !== null && $token['type'] === $type && $token['text'] === $text;
    }

    /**
     * Split the whole source into tokens. False when something never closes.
     */
    private function tokenize(): bool
    {
        $end = $this->lex(0, false);

        return $end !== null;
    }

    /**
     * Lex from `$position`. In `$untilBrace` mode (a template literal's
     * `${…}`) stop after the `}` that closes it and return the offset after
     * it, without recording tokens. Null on an unterminated construct.
     */
    private function lex(int $position, bool $untilBrace): ?int
    {
        $source   = $this->source;
        $length   = strlen($source);
        $depth    = 0;
        $previous = null;

        while ($position < $length) {
            $char = $source[$position];
            $next = $source[$position + 1] ?? '';

            if (ctype_space($char)) {
                $position++;

                continue;
            }

            if ($char === '/' && $next === '/') {
                $newline  = strpos($source, "\n", $position);
                $position = $newline === false ? $length : $newline + 1;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $close = strpos($source, '*/', $position + 2);

                if ($close === false) {
                    return null;
                }

                $position = $close + 2;

                continue;
            }

            $start = $position;

            if ($char === '"' || $char === "'") {
                $position = $this->skipString($position, $char);
                $type     = 'string';
            } elseif ($char === '`') {
                $position = $this->skipTemplate($position);
                $type     = 'template';
            } elseif ($char === '/' && $this->startsRegex($previous)) {
                $position = $this->skipRegex($position);
                $type     = 'regex';
            } elseif (preg_match('/[A-Za-z_$]/', $char) === 1) {
                preg_match('/[A-Za-z0-9_$]+/A', $source, $match, 0, $position);
                $position += strlen($match[0]);
                $type = 'ident';
            } elseif (ctype_digit($char)) {
                preg_match('/[0-9A-Za-z_.]+/A', $source, $match, 0, $position);
                $position += strlen($match[0]);
                $type = 'other';
            } elseif (str_contains(self::PUNCTUATION, $char)) {
                $position++;
                $type = 'punct';
            } else {
                $position++;
                $type = 'other';
            }

            if ($position === null) {
                return null;
            }

            $token    = ['type' => $type, 'start' => $start, 'end' => $position, 'text' => substr($source, $start, $position - $start)];
            $previous = $token;

            if (! $untilBrace) {
                $this->tokens[] = $token;

                continue;
            }

            if ($type === 'punct' && $char === '{') {
                $depth++;
            } elseif ($type === 'punct' && $char === '}') {
                if ($depth === 0) {
                    return $position;
                }

                $depth--;
            }
        }

        return $untilBrace ? null : $position;
    }

    /**
     * @param array{type: string, start: int, end: int, text: string}|null $previous
     */
    private function startsRegex(?array $previous): bool
    {
        if ($previous === null) {
            return true;
        }

        if ($previous['type'] === 'ident') {
            return in_array($previous['text'], self::REGEX_KEYWORDS, true);
        }

        if ($previous['type'] === 'punct' || $previous['type'] === 'other') {
            return in_array($previous['text'], self::REGEX_PRECEDERS, true);
        }

        return false;
    }

    private function skipString(int $position, string $quote): ?int
    {
        $length = strlen($this->source);

        for ($i = $position + 1; $i < $length; $i++) {
            $char = $this->source[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === $quote) {
                return $i + 1;
            }

            if ($char === "\n") {
                return null;
            }
        }

        return null;
    }

    private function skipTemplate(int $position): ?int
    {
        $length = strlen($this->source);
        $i      = $position + 1;

        while ($i < $length) {
            $char = $this->source[$i];

            if ($char === '\\') {
                $i += 2;

                continue;
            }

            if ($char === '`') {
                return $i + 1;
            }

            if ($char === '$' && ($this->source[$i + 1] ?? '') === '{') {
                $after = $this->lex($i + 2, true);

                if ($after === null) {
                    return null;
                }

                $i = $after;

                continue;
            }

            $i++;
        }

        return null;
    }

    private function skipRegex(int $position): ?int
    {
        $length  = strlen($this->source);
        $inClass = false;

        for ($i = $position + 1; $i < $length; $i++) {
            $char = $this->source[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === "\n") {
                return null;
            }

            if ($char === '[') {
                $inClass = true;
            } elseif ($char === ']') {
                $inClass = false;
            } elseif ($char === '/' && ! $inClass) {
                preg_match('/[a-z]*/A', $this->source, $flags, 0, $i + 1);

                return $i + 1 + strlen($flags[0]);
            }
        }

        return null;
    }
}
