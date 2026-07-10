<?php

declare(strict_types=1);

/*
 * Local developer dashboard for the Flute PHP SDK.
 *
 * Lists the runnable examples and renders their CLI output in the browser.
 * Tracked in git for development but export-ignored, so it never ships in
 * the Composer package. Examples run as `php examples/<name>.php`
 * subprocesses — exactly the documented CLI invocation.
 */

$projectRoot = __DIR__;
$autoload = $projectRoot . '/vendor/autoload.php';

$sdkVersion = null;
if (file_exists($autoload)) {
    require $autoload;
    if (class_exists(\Flute\Sdk\Flute::class)) {
        $sdkVersion = \Flute\Sdk\Flute::VERSION;
    }
}

$envChecks = [
    ['label' => 'PHP ' . PHP_VERSION, 'ok' => PHP_VERSION_ID >= 80100],
    ['label' => 'autoloader', 'ok' => $sdkVersion !== null],
];

// Requested view; refined to the run example's audience further down.
$view = (isset($_GET['view']) && $_GET['view'] === 'partner') ? 'partner' : 'merchant';

// Titles and blurbs for the known examples; new files fall back to filename.
$catalogs = [
    'merchant' => [
        '01-sale' => ['Sale', 'Charge a card and print the result'],
        '02-token-caching' => ['Token Caching', 'Reuse an access token across clients'],
        '03-webhook-verification' => ['Webhook Verification', 'Verify a signed webhook delivery'],
        '04-list-transactions' => ['List Transactions', 'Page through recent transactions'],
        '05-void-transaction' => ['Void Transaction', 'Authorize, then void the authorization'],
        '06-refund-transaction' => ['Refund Transaction', 'Refund a settled transaction by id'],
        '07-handling-errors' => ['Handling Errors', 'Catch typed exceptions and retry transient failures'],
    ],
    'partner' => [
        'partner/01-list-merchants' => ['List Merchants', "Page through the partner's merchants"],
        'partner/02-onboard-merchant' => ['Onboard a Merchant', 'Mint an API key — self-cleaning, safe to run'],
        'partner/03-rotate-merchant-key' => ['Rotate a Merchant Key', 'Mint a replacement, revoke it — self-cleaning'],
    ],
];

$examples = [];
foreach (['merchant' => '/examples/*.php', 'partner' => '/examples/partner/*.php'] as $audience => $pattern) {
    foreach (glob($projectRoot . $pattern) ?: [] as $file) {
        $base = basename($file, '.php');
        $name = ($audience === 'partner' ? 'partner/' : '') . $base;
        [$title, $blurb] = $catalogs[$audience][$name]
            ?? [ucfirst(str_replace('-', ' ', (string) preg_replace('/^\d+-/', '', $base))), ''];
        $examples[$name] = ['file' => $file, 'title' => $title, 'blurb' => $blurb, 'audience' => $audience];
    }
}

/**
 * Run one example as a CLI subprocess and capture the outcome.
 *
 * @return array{stdout: string, stderr: string, exit: int, seconds: float}
 */
function runExample(string $file): array
{
    $start = microtime(true);
    // "php" from PATH — under FPM, PHP_BINARY is the php-fpm binary.
    $process = proc_open(['php', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return [
            'stdout' => '',
            'stderr' => 'Could not start the php subprocess.',
            'exit' => -1,
            'seconds' => 0.0,
        ];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit' => proc_close($process),
        'seconds' => microtime(true) - $start,
    ];
}

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

/** Escape for HTML, then color transaction status words the way a CLI tool would. */
function colorize(string $text): string
{
    // Sandbox statuses are mixed-case ("Voided"); examples may print uppercase.
    $tones = [
        'approved' => 'ok',
        'captured' => 'ok',
        'settled' => 'ok',
        'authorized' => 'ok',
        'created' => 'ok',
        'minted' => 'ok',
        'voided' => 'warn',
        'revoked' => 'warn',
        'pending' => 'warn',
        'declined' => 'bad',
        'failed' => 'bad',
        'error' => 'bad',
    ];

    return (string) preg_replace_callback(
        '/\b(' . implode('|', array_keys($tones)) . ')\b/i',
        static fn (array $m): string => '<span class="' . $tones[strtolower($m[1])] . '">' . $m[1] . '</span>',
        e($text),
    );
}

$runName = isset($_GET['run']) && is_string($_GET['run']) ? $_GET['run'] : null;
$unknownRun = $runName !== null && !isset($examples[$runName]);
// Examples have side effects (sandbox charges, key minting), so refuse runs
// triggered cross-site (e.g. a drive-by <img src>). Direct navigation sends
// Sec-Fetch-Site: none, same-origin links send same-origin, and curl sends
// nothing — all of those stay allowed.
$crossSiteRun = $runName !== null && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site';
$result = null;
$source = null;
if ($runName !== null && !$crossSiteRun && isset($examples[$runName])) {
    // A direct run link lands on the tab the example belongs to.
    if (!isset($_GET['view'])) {
        $view = $examples[$runName]['audience'];
    }
    $result = runExample($examples[$runName]['file']);
    $source = (string) file_get_contents($examples[$runName]['file']);
} else {
    $runName = null;
}

// Credential checks are per-view; the PHP/autoloader chips are shared.
$credentialVars = $view === 'partner'
    ? ['FLUTE_PARTNER_CLIENT_ID', 'FLUTE_PARTNER_CLIENT_SECRET']
    : ['FLUTE_CLIENT_ID', 'FLUTE_CLIENT_SECRET', 'FLUTE_WEBHOOK_SECRET'];
$missingCredential = false;
foreach ($credentialVars as $var) {
    $ok = (string) getenv($var) !== '';
    $missingCredential = $missingCredential || !$ok;
    $envChecks[] = ['label' => $var, 'ok' => $ok];
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Flute PHP SDK — Developer Dashboard</title>
<style>
:root {
    --canvas: #0d1117; --panel: #161b22; --raised: #21262d; --border: #30363d;
    --ink: #e6edf3; --muted: #8b949e; --faint: #484f58;
    --accent: #2dd4bf; --ok: #3fb950; --warn: #d29922; --bad: #f85149;
    --sans: system-ui, -apple-system, "Segoe UI", sans-serif;
    --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--canvas); color: var(--ink); font: 14px/1.55 var(--sans); }
.wrap { max-width: 1040px; margin: 0 auto; padding: 40px 24px 32px; }
a { color: var(--accent); text-decoration: none; }
a:visited { color: var(--accent); }
a:hover { text-decoration: underline; }
a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 2px; }
header a, header a:visited { color: inherit; text-decoration: none; }
header a:hover { text-decoration: none; cursor: pointer; }
header a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 2px; }

header { display: flex; align-items: center; gap: 12px; }
.logo {
    width: 36px; height: 36px; border-radius: 8px; text-align: center;
    background: linear-gradient(135deg, #2dd4bf, #0ea5e9);
    color: #06222b; font: 800 18px/36px var(--sans);
}
h1 { margin: 0; font-size: 17px; font-weight: 650; letter-spacing: -0.01em; }
.chip {
    font: 11px var(--mono); color: var(--muted); background: var(--panel);
    border: 1px solid var(--border); border-radius: 999px; padding: 2px 9px;
    vertical-align: 2px; margin-left: 4px;
}
.sub { color: var(--muted); font-size: 12px; }
.tabs { display: flex; margin-left: 18px; }
.tabs a, .tabs a:visited {
    font-size: 12px; font-weight: 600; padding: 4px 14px;
    border: 1px solid var(--border); color: var(--muted);
}
.tabs a:first-child { border-radius: 6px 0 0 6px; }
.tabs a:last-child { border-radius: 0 6px 6px 0; border-left: 0; }
.tabs a.active { background: var(--accent); color: #06222b; border-color: var(--accent); }
.tabs a:hover { text-decoration: none; }
.sandbox {
    margin-left: auto; font: 11px var(--mono); color: var(--warn);
    background: #221c12; border: 1px solid #3a3022; border-radius: 999px; padding: 3px 10px;
}

.env { display: flex; flex-wrap: wrap; gap: 8px; margin: 20px 0 0; }
.env span {
    font: 11px var(--mono); color: var(--muted); background: var(--panel);
    border: 1px solid var(--border); border-radius: 6px; padding: 4px 10px;
}
.dot-ok::before { content: "● "; color: var(--ok); }
.dot-bad::before { content: "● "; color: var(--bad); }
.hint { color: var(--warn); font-size: 12px; margin: 8px 0 0; }
.hint code { font-family: var(--mono); }

.grid { display: grid; grid-template-columns: 5fr 6fr; gap: 20px; align-items: start; margin-top: 24px; }
@media (max-width: 860px) { .grid { grid-template-columns: 1fr; } }

.panel { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.panel + .panel { margin-top: 14px; }
.panel-head {
    display: flex; align-items: center; gap: 8px; padding: 9px 14px;
    background: var(--raised); font-size: 11px; font-weight: 700;
    letter-spacing: 0.1em; color: var(--muted);
}
.panel-head .path { margin-left: auto; font: 11px var(--mono); font-weight: 400; letter-spacing: 0; }

.example { display: flex; gap: 10px; padding: 11px 16px; border-top: 1px solid var(--raised); align-items: baseline; }
.example.active { background: #1a2230; box-shadow: inset 2px 0 var(--accent); }
.example .num { font: 12px var(--mono); color: var(--accent); }
.example .what { flex: 1; min-width: 0; }
.example .blurb { color: var(--muted); font-size: 12px; }
.example a { font: 12px var(--mono); white-space: nowrap; }

.term-head { display: flex; align-items: center; gap: 6px; padding: 8px 14px; background: var(--raised); }
.light { width: 10px; height: 10px; border-radius: 50%; }
.cmd { font: 11px var(--mono); color: var(--muted); margin-left: 8px; }
.exit { margin-left: auto; font: 10px var(--mono); }
.ok { color: var(--ok); } .warn { color: var(--warn); } .bad { color: var(--bad); }

pre { margin: 0; padding: 14px 16px; font: 12px/1.6 var(--mono); white-space: pre; overflow-x: auto; }
pre.src { color: var(--muted); font-size: 11.5px; }
.stderr { border-top: 1px solid var(--border); }
.stderr pre { color: var(--bad); }
.quiet { padding: 28px 16px; color: var(--muted); text-align: center; font-size: 13px; }

footer { margin-top: 24px; color: var(--faint); font-size: 11px; }
<?php if ($view === 'partner') : ?>
:root { --accent: #c084fc; }
<?php endif ?>
</style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="logo"><a href="/">F</a></div>
        <div>
            <h1><a href="/">Flute PHP SDK</a><?php if ($sdkVersion !== null) : ?><span class="chip">v<?= e($sdkVersion) ?></span><?php endif ?></h1>
            <div class="sub">Developer Dashboard</div>
        </div>
        <nav class="tabs">
            <a href="?view=merchant" class="<?= $view === 'merchant' ? 'active' : '' ?>">Merchant</a>
            <a href="?view=partner" class="<?= $view === 'partner' ? 'active' : '' ?>">Partner</a>
        </nav>
        <span class="sandbox">sandbox</span>
    </header>

    <div class="env">
        <?php foreach ($envChecks as $check) : ?>
            <span class="<?= $check['ok'] ? 'dot-ok' : 'dot-bad' ?>"><?= e($check['label']) ?></span>
        <?php endforeach ?>
    </div>
    <?php if ($missingCredential) : ?>
        <p class="hint"><?= $view === 'partner'
            ? 'Partner credentials are missing — add FLUTE_PARTNER_CLIENT_ID and FLUTE_PARTNER_CLIENT_SECRET to <code>.ddev/config.local.yaml</code>, then restart ddev. Optional: FLUTE_MERCHANT_ID pins the demo merchant.'
            : 'Credentials are missing — add them to <code>.ddev/config.local.yaml</code> (see CONTRIBUTING.md), then restart ddev.' ?></p>
    <?php endif ?>

    <div class="grid">
        <div class="panel">
            <div class="panel-head"><?= $view === 'partner' ? 'PARTNER EXAMPLES' : 'MERCHANT EXAMPLES' ?></div>
            <?php foreach ($examples as $name => $example) : ?>
                <?php if ($example['audience'] !== $view) {
                    continue;
                } ?>
                <div class="example<?= $name === $runName ? ' active' : '' ?>">
                    <span class="num"><?= e(substr(basename($name), 0, 2)) ?></span>
                    <span class="what">
                        <?= e($example['title']) ?><br>
                        <span class="blurb"><?= e($example['blurb']) ?></span>
                    </span>
                    <a href="?view=<?= e($view) ?>&amp;run=<?= e(urlencode($name)) ?>"><?= $name === $runName ? 'run again ↻' : 'run ▸' ?></a>
                </div>
            <?php endforeach ?>
        </div>

        <div>
            <?php if ($result !== null && $runName !== null) : ?>
                <div class="panel">
                    <div class="term-head">
                        <span class="light" style="background:#ff5f56"></span>
                        <span class="light" style="background:#ffbd2e"></span>
                        <span class="light" style="background:#27c93f"></span>
                        <span class="cmd">php examples/<?= e($runName) ?>.php</span>
                        <span class="exit <?= $result['exit'] === 0 ? 'ok' : 'bad' ?>">exit <?= $result['exit'] ?> · <?= sprintf('%.2f', $result['seconds']) ?>s</span>
                    </div>
                    <?php if ($result['stdout'] !== '') : ?>
                        <pre><?= colorize($result['stdout']) ?></pre>
                    <?php elseif ($result['stderr'] === '') : ?>
                        <div class="quiet">The example finished without printing anything.</div>
                    <?php endif ?>
                    <?php if ($result['stderr'] !== '') : ?>
                        <div class="stderr"><pre><?= e($result['stderr']) ?></pre></div>
                    <?php endif ?>
                </div>
                <div class="panel">
                    <div class="panel-head">SOURCE<span class="path">examples/<?= e($runName) ?>.php</span></div>
                    <pre class="src"><?= e((string) $source) ?></pre>
                </div>
            <?php elseif ($crossSiteRun) : ?>
                <div class="panel"><div class="quiet">Blocked a cross-site run request — examples only run from this dashboard's own links.</div></div>
            <?php elseif ($unknownRun) : ?>
                <div class="panel"><div class="quiet">That example doesn't exist — pick one from the list.</div></div>
            <?php else : ?>
                <div class="panel"><div class="quiet">Pick an example and hit run — its output shows up here, exactly as the CLI prints it.</div></div>
            <?php endif ?>
        </div>
    </div>

    <footer>Local dev tool — runs the examples against the Flute sandbox. Never deploy this file.</footer>
</div>
</body>
</html>
