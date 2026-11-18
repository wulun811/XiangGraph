# Upstream source validation fixtures

These two files are verbatim copies of Symfony 6.4 test sources at merge commit
`b82dd4e6f9eef23fe12f5a5512bdb0a70462d1c0` (PR #67976, MIT). They are used only
to verify that the extractor sees real upstream import syntax and refuses to
guess unresolved placeholders or concatenations.

| File | Upstream path | SHA-256 |
|---|---|---|
| `FileLoaderTest.php` | `src/Symfony/Component/DependencyInjection/Tests/Loader/FileLoaderTest.php` | `feaece4d0920795995f4f0fbee148a9729239e8bebf604cc497a665ac88b3913` |
| `PhpFileLoaderTest.php` | `src/Symfony/Component/Routing/Tests/Loader/PhpFileLoaderTest.php` | `8ed8e58c26e9ee9696b3e8a98d91434fea27f92f2a82f0ec309369990df1dcd0` |

License: MIT. The upstream `LICENSE` is available in
`samples/upstream/symfony-routing-glob-67976/LICENSE`.

Observed expected behavior:

- `FileLoaderTest.php` line 68 has a literal glob with the unresolved `{F}`
  placeholder. The extractor reports it as unsupported; it does not resolve a
  fake fixture path.
- `PhpFileLoaderTest.php` line 268 uses string concatenation. The extractor
  reports it as unsupported; it does not treat `'/../Fixtures/glob/*.missing'`
  as the import resource.
