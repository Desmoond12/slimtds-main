<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\MacroExpander;

beforeEach(function (): void {
    $this->expander = new MacroExpander();
    $this->ctx = new Context('1.2.3.4', 'curl', 'demo', 1_714_000_000);
    $this->ctx->country = 'ru';
    $this->ctx->city = 'moscow';
    $this->ctx->clickId = '019dc137-724a-756c-923a-a392001e3d79';
    $this->ctx->utm['source'] = 'fb';
});

test('substitutes basic macros', function (): void {
    $u = 'https://example.com/?c={country}&city={city}&cid={click_id}&utm={utm_source}';
    $r = $this->expander->expand($u, $this->ctx);
    expect($r)->toBe('https://example.com/?c=ru&city=moscow&cid=019dc137-724a-756c-923a-a392001e3d79&utm=fb');
});

test('rand:1-100 yields integer in range', function (): void {
    for ($i = 0; $i < 50; $i++) {
        $r = $this->expander->expand('{rand:1-100}', $this->ctx);
        $n = (int)$r;
        expect($n)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(100);
    }
});

test('randstr:5 yields 5-char alphanumeric', function (): void {
    $r = $this->expander->expand('{randstr:5}', $this->ctx);
    expect($r)->toMatch('/^[A-Za-z0-9]{5}$/');
});

test('unknown macros pass through unchanged', function (): void {
    $u = 'https://example.com/?x={notamacro}';
    expect($this->expander->expand($u, $this->ctx))->toBe('https://example.com/?x={notamacro}');
});

test('known macro with a null value (e.g. no GeoIP DB loaded) degrades to empty string, not a leaked literal', function (): void {
    // $this->ctx never sets ->region, so it's null — unlike {notamacro}, this
    // IS a recognized macro name, just with no data behind it right now.
    $u = 'https://example.com/?r={region}&c={country}';
    expect($this->expander->expand($u, $this->ctx))->toBe('https://example.com/?r=&c=ru');
});

test('spin picks one of the listed values', function (): void {
    for ($i = 0; $i < 50; $i++) {
        $r = $this->expander->expand('{spin:slots|aviator|registration}', $this->ctx);
        expect($r)->toBeIn(['slots', 'aviator', 'registration']);
    }
});

test('spin keeps path-like values raw (no url-encoding)', function (): void {
    $r = $this->expander->expand('{spin:slots/game/52358/aviator}', $this->ctx);
    expect($r)->toBe('slots/game/52358/aviator');
});

test('spin trims whitespace and drops empty segments', function (): void {
    for ($i = 0; $i < 50; $i++) {
        $r = $this->expander->expand('{spin: slots | aviator | }', $this->ctx);
        expect($r)->toBeIn(['slots', 'aviator']);
    }
});

test('spin with no valid values expands to empty string', function (): void {
    expect($this->expander->expand('r={spin:}', $this->ctx))->toBe('r=');
    expect($this->expander->expand('r={spin: | }', $this->ctx))->toBe('r=');
});

test('spin works embedded in a full url alongside other macros', function (): void {
    $u = 'https://betlnk.net/go/HASH/?tid={click_id}&r={spin:slots|aviator}';
    $r = $this->expander->expand($u, $this->ctx);
    expect($r)->toMatch('#^https://betlnk\.net/go/HASH/\?tid=019dc137-724a-756c-923a-a392001e3d79&r=(slots|aviator)$#');
});

test('spin covers every value across many iterations', function (): void {
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $seen[$this->expander->expand('{spin:a|b|c}', $this->ctx)] = true;
    }
    expect(array_keys($seen))->toEqualCanonicalizing(['a', 'b', 'c']);
});

test('html escape mode neutralizes attacker-controlled macro values, leaves template untouched', function (): void {
    $this->ctx->referer = '"><script>alert(1)</script>';
    $r = $this->expander->expand('<p>from {referer}</p>', $this->ctx, 'html');
    expect($r)->toBe('<p>from &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;</p>');
    expect($r)->not->toContain('<script>');
});

test('html escape mode leaves default/raw mode behavior unaffected', function (): void {
    $r = $this->expander->expand('{country}', $this->ctx);
    expect($r)->toBe('ru');
});

test('json escape mode drops safely into an operator-authored JSON string literal', function (): void {
    $this->ctx->referer = 'quote " and backslash \\ and newline' . "\n" . 'end';
    $body = '{"ref":"{referer}"}';
    $r = $this->expander->expand($body, $this->ctx, 'json');
    $decoded = json_decode($r, true);
    expect($decoded)->toBeArray();
    expect($decoded['ref'])->toBe($this->ctx->referer);
});

test('json escape mode neutralizes a macro value that tries to break out of the string', function (): void {
    $this->ctx->utm['content'] = '","admin":true,"x":"';
    $body = '{"utm":"{utm_content}"}';
    $r = $this->expander->expand($body, $this->ctx, 'json');
    $decoded = json_decode($r, true);
    expect($decoded)->toBe(['utm' => '","admin":true,"x":"']);
    expect($decoded)->not->toHaveKey('admin');
});
