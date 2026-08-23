# E — setlist.fm test debt: phpstan and one flake

**Branch:** `bugfix/setlist-tests-phpstan` · **Priority:** Low · **Independent of feature 14**

phpstan level 9 reports 26 errors. All of them are pre-existing, all in prompt 09's setlist.fm
**test** files, and **zero are in `src/`**. Kept separate from feature 14 deliberately: this is
prompt 09's code and should not ride along in that branch's diff.

```
bugfix/setlist-tests-phpstan

phpstan level 9 reports 26 errors, all pre-existing, all in prompt 09's setlist.fm TEST files —
zero in src/. Clear them. This is deliberately separate from feature 14 because it is prompt 09's
code and should not ride along in that branch's diff.

  tests/Setlist/SetlistNormalizerTest.php               7  json_decode returns mixed, then indexed
                                                           and passed to typed params
  tests/Functional/Setlist/SetlistRefreshCommandTest.php 8  redundant assert(instanceof);
                                                           KernelInterface variance at L62/L89
  tests/Setlist/SetlistIntegrationTestCase.php          4  redundant assert($redis instanceof Redis)
  tests/Functional/Setlist/SetlistApiWebTestCase.php    2  same
  tests/Functional/Admin/SetlistBackofficeTest.php      1  cannot cast mixed to string
  tests/Functional/Setlist/BandSetlistsApiTest.php      1  count() given mixed
  tests/Setlist/SetlistFmLiveSmokeTest.php              1  cannot cast mixed to string
  tests/Setlist/BudgetExhaustionDegradesHonestlyTest.php 1 closure returns MockResponse|null
  tests/Unit/Service/Setlist/SetlistFmClientRetryTest.php 1 same

Two patterns cover most of it: delete the redundant assert(instanceof) lines, and narrow mixed
with a typed `/** @var */` or an is_string()/is_array() guard rather than a blind cast.

Do not weaken phpstan config, add baselines, or add ignoreErrors. Do not change production code.
Verify: phpstan reports 0 errors, and the suite still passes at its current count.

While you are in these files: `SetlistFmBudgetTest::testRateLimitDegradesWithinBoundedWaitInstead
OfBlockingForever` fails intermittently in full runs but passes in isolation — timing-sensitive
against shared Redis. Give it a proper clock/sleep seam so it is deterministic. Do not paper over
it with a retry annotation.
```

## Note on running the suite

The full suite needs an explicit memory limit — the container's default 128M dies partway through:

```bash
docker compose exec -T backend php -d memory_limit=512M vendor/bin/phpunit
```

Worth folding into `phpunit.xml.dist` or the image at some point, so nobody has to remember it.
