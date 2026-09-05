# RC9 Messenger composition audit — 2026-09-05

## Verdict and scope

The original RC9 reproduction is retained below. The resumed investigation
implemented and compared A (constructor-iterable composition), B (bus
decoration), and a corrected A variant. Corrected A satisfies the tested
ordering relations on Symfony 7.4, 8.0 and 8.1; the initial design stop is
superseded. No private API or reflection is needed.

The implementation is prepared on `fix/preserve-messenger-bus-middlewares`.
The dependency decision is resolved: Messenger remains mandatory, preserving
RC9 compatibility; the minimal installation disables its integration explicitly.
Publication remains subject to the candidate and release checks below.

## Verified baseline

- Public package: `v1.0.0-rc.9`, MIT, source and ZIP dist both at
  `9af00b6903803b627dd5b08119bcb5ab49d7a713`.
- A non-destructive fetch confirmed `origin/develop` at
  `26fd6e4c4e3a17b5fa1a02c3c66423fbcfe7a710` and `origin/main` at the RC9 SHA.
- Both branch CI runs succeeded; no open bundle or demo PR was found during
  the initial inspection. The public RC9 release is non-draft and prerelease.
- Four new consumers installed the public ZIP through Packagist, without an
  alternative Composer repository. All 143 installed source/manifest/license
  files checked matched the RC9 Git tree byte for byte. The installed package
  reference was also asserted in every behavioral run.

The release remains the existing [RC9 prerelease](https://github.com/Zhortein/multi-tenant-bundle/releases/tag/v1.0.0-rc.9).
The baseline checks are [develop CI](https://github.com/Zhortein/multi-tenant-bundle/actions/runs/33890349504)
and [main CI](https://github.com/Zhortein/multi-tenant-bundle/actions/runs/33890658836).

## Executed reproduction

The fixture loads this real YAML fragment through Symfony's YamlFileLoader:

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - validation
```

The compiled constructor still contains `ValidationMiddleware`,
`SendMessageMiddleware` and `HandleMessageMiddleware`. It contains none of
`TenantWorkerMiddleware`, `TenantSendingMiddleware` or
`TenantMessengerTransportResolver`.

The real bus sends an unclassified application message to a Doctrine
persistent transport. Reading and acknowledging it exercises real
deserialization. An invalid consumer payload is still rejected by the actual
Symfony validation middleware before persistence. A tenant-aware message
carrying a tenant A stamp is then persisted and consumed by a real Worker; the
handler executes with `NONE` rather than A.

| PHP | FrameworkBundle | PostgreSQL | Result |
| --- | --- | --- | --- |
| 8.5.9 | 7.4.18 | 16 | Both RC9 defects reproduced |
| 8.5.9 | 8.0.15 | 16 | Both RC9 defects reproduced |
| 8.5.9 | 8.1.6 | 16 | Both RC9 defects reproduced |
| 8.5.9 | 8.1.5, exact reference graph | 16 | Both RC9 defects reproduced |
| 8.5.9 | 8.1.5, exact reference graph | 18 | Both RC9 defects reproduced |

Each final run passed the diagnostic's 11 assertions, with no PHPUnit notice,
deprecation, or skip reported. The PostgreSQL images actually used were 16.10
and 18.6. The delivered preparation script was independently replayed from a
fifth fresh fixture export and public installation, with the same 11-assertion
result on Symfony 8.1.6 and PostgreSQL 16.10.

The exact graph additionally pins ORM 3.6.8, DBAL 4.4.4, DoctrineBundle 3.3.1,
DoctrineMigrationsBundle 4.0.1 and migrations core 3.9.7. The existing version
assertion script confirmed these pins. This establishes a **RC9 reproduction**
on that graph; it does not establish RC10 installability or correctness.

The [standalone reproducer](../reproducers/messenger-rc9/README.md) records the
preparation and execution recipe. Initial PHP-array configuration runs passed
on all three Symfony lines. The first literal YAML runs correctly reported the
fixture's missing `symfony/yaml`; the aligned component was then installed and
the literal YAML runs passed. No required YAML test was left skipped.

## Official Symfony source findings

1. `ContainerBuilder::prependExtensionConfig()` places a fragment before the
   application's configuration. Application values retain precedence; it is
   not a mechanism for enforcing required middleware. See the
   [official prepend documentation](https://symfony.com/doc/7.4/bundles/prepend_extension.html).
2. `framework.messenger.buses` is keyed by bus name. Each bus's `middleware`
   node uses `performNoDeepMerging()`. `ArrayNode::mergeValues()` therefore
   returns the later whole list instead of appending it. The behavior appears
   in the inspected official 7.4, 8.0 and 8.1 sources. See
   [Framework configuration](https://github.com/symfony/symfony/blob/v8.1.6/src/Symfony/Bundle/FrameworkBundle/DependencyInjection/Configuration.php),
   [ArrayNodeDefinition](https://github.com/symfony/symfony/blob/v8.1.6/src/Symfony/Component/Config/Definition/Builder/ArrayNodeDefinition.php)
   and [ArrayNode](https://github.com/symfony/symfony/blob/v8.1.6/src/Symfony/Component/Config/Definition/ArrayNode.php).
3. FrameworkExtension composes the default before/after middleware with the
   processed application list, stores it in `<bus-id>.middleware`, and
   registers the tagged bus definition. Boolean `default_middleware` values,
   the historical `allow_no_handlers` string, and the map with `enabled`,
   `allow_no_handlers` and `allow_no_senders` are normalized by the configuration
   tree. The string normalizer also maps other strings to disabled defaults;
   a correction must preserve Symfony's actual normalization rather than
   inventing an accepted-value list. See
   [FrameworkExtension](https://github.com/symfony/symfony/blob/v8.1.6/src/Symfony/Bundle/FrameworkBundle/DependencyInjection/FrameworkExtension.php).
4. FrameworkBundle registers the Scheduler pass, then MessengerPass, in the
   before-optimization phase at default priority zero on all three inspected
   lines. MessengerPass reads `messenger.bus` tags, consumes the parameter,
   resolves middleware services/factories, replaces constructor argument zero
   with an `IteratorArgument`, and removes the parameter. Its middleware
   registration method is private. It does not automatically insert services
   merely because they carry the bundle's `messenger.middleware` priority tag.
   See [FrameworkBundle](https://github.com/symfony/symfony/blob/v8.1.6/src/Symfony/Bundle/FrameworkBundle/FrameworkBundle.php)
   and [MessengerPass](https://github.com/symfony/symfony/blob/v8.1.6/src/Symfony/Component/Messenger/DependencyInjection/MessengerPass.php).

The actual integration is FrameworkBundle plus the Messenger component's
compiler pass; there is no separate MessengerBundle in these consumers.

## Functional ordering constraints

RC9 intends `TenantWorkerMiddleware` before `TenantSendingMiddleware`, followed
by `TenantMessengerTransportResolver`, then the application middleware and
Symfony's send/handle tail. The worker first establishes the received context;
the sending middleware requires that context and validates/adds stamps; the
resolver retains the historical routing behavior. All three are needed for
compatibility, although two implement the main classification/context guards.

Simply prepending the guards to constructor argument zero is insufficient:

- `AddDefaultStampsMiddleware` can add classification-relevant tenant metadata.
- `DispatchAfterCurrentBusMiddleware` saves the downstream stack and later
  resumes it; placement relative to a receive boundary needs behavioral proof.
- Symfony 8.1's `DecodeFailedMessageMiddleware` may replace a decoding-failure
  envelope with the actual decoded application envelope. Classifying the
  failure object first changes the normal replay path.
- `FailedMessageProcessingMiddleware` restores the original receiver metadata.
- The tenant receive boundary must still precede validation, Doctrine, tenant
  caches/logging, and handlers, and perform cleanup after their exceptions.

These roles are visible in the official
[middleware sources](https://github.com/symfony/symfony/tree/v8.1.6/src/Symfony/Component/Messenger/Middleware).
The audit does not claim that a final RC10 order has been implemented or proven
for explicit default-free chains, delayed dispatch, retries, or failure replay.

## Resumed A/B experiments

Both temporary implementations used a real FrameworkBundle kernel, its real
`MessengerPass`, production RC9 middleware, Symfony Validator and handlers.
Each ran on FrameworkBundle 7.4.18, 8.0.15 and 8.1.6 with standard configuration,
an explicit historical guard, a complete default-free chain and the profiler.
Every kernel also declared a second bus with a different application chain.
No prototype was committed; the non-selected code remains outside the package.

| Experiment | Result on all three Symfony lines |
| --- | --- |
| A1: insert guards after the existing Symfony preamble | Normal and nested dispatch work, but deferred tenant dispatch throws `MissingTenantContextException`: the parent boundary already cleaned context before Symfony resumes the saved stack. |
| B: decorate each tagged bus through `MessageBusInterface` and `setDecoratedService()` | Normal, nested, delayed and second-bus dispatch work. However, default tenant stamps are not yet present at classification; an explicit historical worker guard clears the decorator's context before a deferred handler (observed `NONE`). On 8.1, classification rejects the decoding-failure object before Symfony can recover its payload. |
| A2: compose after MessengerPass, retain envelope preparation before the guards and enclose deferred dispatch inside the receive boundary | Normal, nested, delayed, second-bus, explicit guard, default stamps and profiler cases pass. Symfony 8.1 failure redecoding reaches validation and handler under the correct tenant. All boundaries end at `NONE`. |

The first matrix runner reused container-cache names across Docker processes;
that fixture mistake produced a misleading 8.1 decoder failure using a 7.4
cached chain. The final runs use random cache names per kernel and dependency
graph. Their emitted original references confirmed the version-appropriate
presence of `DecodeFailedMessageMiddleware`. The permanent suite separately
checks a deliberate second boot of the same version's dumped container.

B could only recover the observed cases by supplementing its outer decoration
with inner interception/normalization and duplicate-guard handling. A2 already
reuses Symfony's actual middleware and requires no second dispatch mechanism,
so no such hybrid was retained.

## Selected architecture and public APIs

`ComposeTenantMessengerPass` is internal. It runs in the public
before-optimization compiler phase at priority -100, after Symfony's
`MessengerPass` and before service decoration/optimization. It discovers
`messenger.bus` services and reads argument zero of their `MessageBus`
definitions as an `IteratorArgument`. It preserves original `Reference`
objects, including middleware factory arguments and explicitly configured
bundle services, and writes the composed iterable back to that constructor.

The APIs used are `CompilerPassInterface`, `PassConfig`,
`ContainerBuilder::findTaggedServiceIds()`/`findDefinition()`, definition
class/argument accessors, `ChildDefinition::getParent()`, `Reference`,
`IteratorArgument::getValues()` and the public `MessageBus` iterable
constructor. Middleware roles are identified by their public class names,
not generated service-ID suffixes. No `<bus>.middleware` parameter is read or
written. An unsupported tagged-bus definition fails compilation explicitly.
This is a bounded generated-definition integration contract protected by the
matrix, not a claim that all Symfony internals are frozen.

Required relations proved by behavior:

- default stamps and available failure decoding/receiver restoration precede
  classification;
- received context and outgoing preparation precede application validation,
  application middleware, sending and handlers;
- delayed continuations finish before tenant cleanup, including exceptions;
- application middleware preserve relative order and execute once;
- Symfony routing, transport stamps, handlers, profiler and interface aliases
  remain operative on all buses.

The remaining chain retains its relative order. The bundle does not invent a
complete Symfony ordering contract or a new public message-classification API.
Public RC9 middleware classes and constructor signatures are unchanged.

## Executed candidate checks (working tree)

- Reused the public RC9 diagnostic on Symfony 8.1.6/PostgreSQL 16.10:
  both defects reconfirmed, 11 assertions.
- Permanent compiled-container composition suite: 26 tests / 172 assertions
  on Symfony 7.4.18 and 8.0.15; 26 / 174 on 8.1.6. No notice or skip.
- Coverage includes implicit buses, validation, multiple application
  middleware, several buses, full explicit default-free chains, split YAML,
  disabled integration, accidental explicit guards, repeat composition,
  cached containers, profiler/interface aliases, both routing strategies,
  invalid classification/stamps, before/after/handler exceptions, nested and
  delayed dispatch, real serialized Worker A/global/B/failure/global/A,
  retries and failure replay, default stamps and available 8.1 redecoding.
- Real Scheduler/Doctrine Worker coexistence: success and controlled handler
  exception proofs pass on all three Symfony lines and on the exact graph
  with PostgreSQL 16/18: 2 tests / 33 assertions per run. Validator callbacks record
  the wrapper, outgoing application and subsequently received application
  dispatches; the Scheduler Worker does not invoke the application handler.
- Full local PHPUnit with PostgreSQL 16.10 and 18.6: 639 tests / 2,697
  assertions, zero failures/errors/risky tests; 295 PHPUnit notices and 11
  existing explicit skips (seven repository-unit placeholders, two disabled
  storage integrations, two resolver placeholders). Required RLS/multi-db
  recipes were executed, not skipped.
- PHPStan at maximum level, strict Composer manifest validation, dependency
  audit (no advisory), full CS Fixer dry run (323 files), documentation links
  (53 files), Actionlint, ShellCheck and `git diff --check` pass. PHP 8.3
  syntax validation of the new compiler pass passes. Composer cache/root
  version warnings and CS Fixer's newer-runtime warning are recorded; they
  did not fail the final checks.
- Exact reference graph on PostgreSQL 16 and 18: exact-version assertions,
  shared/multi-db migration dry-run/normal/idempotence/A-B-A/failure/empty/
  unknown-tenant recipe, ordinary migration up/down/up, then Consumer App
  pass: 23 tests / 123 assertions each. Initial direct PHPUnit invocation
  before the migration recipe lacked the fixture tables; replaying the
  documented complete recipe resolved that environment error.

These are working-tree proofs, not candidate-archive or public-package proofs.
Full remote CI, reviewed candidate SHA, publication and demo update remain
unclaimed until completed.

## Minimal-install dependency experiment and decision

Two isolated Composer experiments establish a separate compatibility issue:

1. Moving `symfony/messenger` from runtime requirements to development/
   suggestions, while conditionally registering the unchanged public legacy
   session adapter, allows a fresh production install with neither Messenger
   nor Scheduler. The minimal container compilation succeeds. HTTP/CLI RLS
   uses the independent core `DoctrineTenantRlsStateSynchronizer`.
2. A fresh RC9 consumer requiring FrameworkBundle and the bundle, without an
   explicit Messenger requirement, initially receives Messenger transitively.
   Updating only the bundle to that candidate without editing the consumer
   manifest removes `symfony/messenger`, `symfony/clock` and `psr/clock`.
   Composer succeeds, but the consumer's Messenger integration is gone.
   Loading the unchanged public `TenantSessionConfigurator` then fails with
   `Interface "Symfony\Component\Messenger\Middleware\MiddlewareInterface" not found`.

Therefore an actual no-Messenger installation and unconditional preservation
of RC9's transitive installation contract cannot both be claimed. Keeping the
RC9 dependency preserves consumers and permits a minimal integration-disabled
installation; making it optional requires transitively dependent consumers to
add Messenger explicitly. This decision is independent of middleware order.
The maintainer confirmed that RC10 must retain `symfony/messenger` as a runtime
dependency. The optional-dependency prototype was removed, including its
conditional registration of the legacy adapter. The minimal production gate
now requires Messenger to be installed, explicitly disables integration, and
checks that integration services are absent. Consumers need no manifest or
application changes. This resolves the dependency stop without changing A2.

The retained manifest passed a fresh production-only installation with Messenger
present and integration disabled, strict Composer validation and an advisory-free
audit. A second unchanged RC9 consumer was upgraded to the retained manifest:
Composer updated only the bundle, removed no packages, and both
`MessageBusInterface` and `TenantSessionConfigurator` remained loadable.

The working-tree results above precede the frozen candidate CI and publication
gates; they do not substitute for those release checks.
No command was executed inside the Services Locaux repository and none of its
files was modified or removed. The bundle's initial seven audit/reproducer
files are preserved; this report and the reproducer's navigation text were
updated to reflect the resumed investigation.

## Original audit-artifact checks

- `make docs-validate`: 52 documentation files and their local links validated.
- `make composer-validate`: the unchanged bundle manifest passed strict
  validation. Composer reported its usual undetected-root-version fallback;
  this did not create or publish any version.
- PHP 8.3 `php -l`: all four new PHP files passed.
- PHP-CS-Fixer with the repository configuration and an explicit reproducer
  path: final dry run passed, zero files needing changes. The first multi-path
  invocation required an explicit configuration and was corrected. The tool
  warns that its PHP 8.5.9 runtime exceeds the package's PHP 8.3 floor; the
  separate PHP 8.3 syntax checks passed.
- Public exact-consumer `composer audit --abandoned=report`: no advisory found.
- Exact-consumer `composer validate --strict`: exited 1 because the deliberate
  exact dependency pins trigger Composer's general constraint warnings. The
  manifest is schema-valid. Those warnings were retained, not silently ignored
  or resolved by relaxing the required graph.
- `git diff --check`: passed; tracked and staged diffs are empty. The only
  additions are this audit and the six standalone reproducer files.

The preceding artifact checks describe the initial audit. Resumed candidate
checks and remaining publication gates are recorded separately above.
