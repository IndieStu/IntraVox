# Testing

IntraVox has two test suites with deliberately opposite setups.

| | Unit | Integration |
|---|---|---|
| Runs | anywhere (`vendor/bin/phpunit`) | inside the nc-dev container only |
| Nextcloud | stubbed (`tests/Stubs/OCP.php`) | the real thing |
| Groupfolders | not involved | real, version-pinned by the server |
| Config | `phpunit.xml` | `phpunit-integration.xml` |
| Speed | ~0.2s | ~4s (plus deploy) |

## Unit suite

```bash
vendor/bin/phpunit --testsuite Unit      # or: composer test:unit
```

Runs against OCP stubs, so it never needs a server. This is the suite that
gates a release and the one to run while developing.

Many tests build a `PageService` **without its constructor** and wire only the
collaborators the path under test needs, via a reflective auto-fill loop. Two
things to know when adding a service dependency:

- If a collaborator is `final`, it cannot be mocked. `doubleOrBuild()` builds a
  real one instead, recursing for its own final dependencies.
- If a collaborator can be safely synthesised, give `PageService` a lazy
  accessor (`locator()`, `news()`, …) and add it to `$lazySeamServices` so the
  auto-fill loop leaves it unset. `PageShapeSanitizer` is the exception that
  cannot: two of its widget rules read admin config, so a synthesised instance
  would apply different security rules than the injected one. Tests that reach a
  sanitizing path wire it explicitly.

## Integration suite

```bash
scripts/run-integration-tests.sh                # deploy, then run
scripts/run-integration-tests.sh --no-deploy    # run what is already deployed
scripts/run-integration-tests.sh --filter Foo   # pass through to phpunit
```

The script deploys the working tree to nc-dev, copies `tests/` and
`phpunit-integration.xml` in separately (`deploy.sh` ships production files
only), and runs the suite as `www-data` inside the container.

### Why it exists

IntraVox resolves the folder its content lives in through several layers — a
member's mounted view, falling back to a raw `__groupfolders` walk, with a mount
point *name* match in between. Every one of those layers is there because of a
real production breakage, and none of them is reachable from a unit test,
because unit tests stub the filesystem away.

That means the code deciding *where the intranet lives* had no automated
coverage at all. It is also exactly the code the multi-site seam replaces, so
these tests are the before/after witness for that work.

### Isolation

Most classes create their **own throwaway groupfolder** (mount point prefixed
`IntraVoxITest`), plus a group and a member user, and remove all three in
`tearDownAfterClass`. Stray objects from a crashed run are cleaned up at the
start of the next one, so the suite is repeatable without manual work.

`PageLifecycleTest` is the exception and has to be: `PageService::getIntraVoxFolder()`
hardcodes the name `IntraVox` — that hardcoding *is* the single-site assumption.
So it writes into the real groupfolder, but only into pages it creates itself,
with a unique id, and it deletes them in `tearDown()` even when a test fails.

### The suite must bite

A test floor that cannot fail is worse than none, because it reports safety it
does not provide. To check, mutate the constant everything resolves through —
**in the container only**, never in the working tree:

```bash
# in nc-dev: set SetupService::GROUPFOLDER_NAME to a name that does not exist
scripts/run-integration-tests.sh --no-deploy    # must FAIL
```

Two tests in `GroupFolderResolutionTest` fail on a wrong constant. Note that
mutating it in the *working tree* and deploying does **not** work as a check:
the `SetupDemoData` migration runs on upgrade and provisions a groupfolder
matching whatever the constant says, so the suite finds one and passes. That is
how an earlier, weaker version of this test slipped through — it compared two
calls to each other instead of asserting which folder was found.

### Requirements

- SSH access to nc-dev (override with `INTRAVOX_DEV_SSH`, `INTRAVOX_DEV_CONTAINER`)
- the groupfolders app enabled on that instance
- an IntraVox groupfolder with at least one page, for the listing assertions
