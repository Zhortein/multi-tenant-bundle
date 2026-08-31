<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$documents = array_merge([$root.'/README.md', $root.'/CONTRIBUTING.md', $root.'/CHANGELOG.md'], glob($root.'/docs/*.md') ?: [], glob($root.'/docs/examples/*.md') ?: []);
$failures = [];
$messengerContract = file_get_contents($root.'/docs/messenger.md');
if (false === $messengerContract) {
    $failures[] = 'Cannot read docs/messenger.md.';
} else {
    foreach ([
        'Every application message must implement exactly one public marker interface',
        'tenant-aware message without an active tenant context is rejected',
        'tenant-aware message without a `TenantStamp`, or whose tenant is unknown, is rejected before its handler',
        'global message is accepted only without a `TenantStamp`',
    ] as $requiredContract) {
        if (!str_contains($messengerContract, $requiredContract)) {
            $failures[] = sprintf('docs/messenger.md is missing the fail-closed contract: %s.', $requiredContract);
        }
    }
    foreach ([
        'Messages without tenant context process normally',
        'message processes without tenant context',
    ] as $legacyClaim) {
        if (str_contains($messengerContract, $legacyClaim)) {
            $failures[] = sprintf('docs/messenger.md contains the legacy fail-open claim: %s.', $legacyClaim);
        }
    }
}
foreach ($documents as $document) {
    $contents = file_get_contents($document);
    if (false === $contents) {
        $failures[] = sprintf('Cannot read %s.', $document);
        continue;
    }
    preg_match_all("/```ya?ml\s*\n(.*?)```/s", $contents, $yamlBlocks);
    foreach ($yamlBlocks[1] as $yaml) {
        try {
            Symfony\Component\Yaml\Yaml::parse($yaml);
        } catch (Symfony\Component\Yaml\Exception\ParseException $exception) {
            $failures[] = sprintf('%s contains invalid YAML: %s', substr($document, strlen($root) + 1), $exception->getMessage());
        }
        if (preg_match("/^\s*(?:resolver\s*:\s*\n\s+type|resolution\s*:\s*\n\s+strategy)\s*:/m", $yaml)) {
            $failures[] = sprintf('%s contains obsolete resolver configuration.', substr($document, strlen($root) + 1));
        }
    }

    preg_match_all("/\[[^]]+]\(([^)]+)\)/", $contents, $links);
    foreach ($links[1] as $link) {
        $target = explode('#', $link, 2)[0];
        if ('' === $target || preg_match('{^(?:https?://|mailto:)}', $target)) {
            continue;
        }
        if (!file_exists(dirname($document).'/'.rawurldecode($target))) {
            $failures[] = sprintf('%s links to missing local target %s.', substr($document, strlen($root) + 1), $link);
        }
    }
    if (preg_match('/production[ -]ready/i', $contents) && !str_contains($document, 'audit-2026-07.md')) {
        $failures[] = sprintf('%s contains an unqualified production-ready claim.', substr($document, strlen($root) + 1));
    }
}
if ([] !== $failures) {
    fwrite(STDERR, implode("\n", array_map(static fn (string $failure): string => 'ERROR: '.$failure, $failures))."\n");
    exit(1);
}
fwrite(STDOUT, sprintf("Validated %d documentation files and their local links.\n", count($documents)));
