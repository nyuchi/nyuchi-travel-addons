# Contributing to Nyuchi Travel Addons

Changes go to `staging` by pull request; `main` is released from `staging`.
CI must pass: it lints every PHP file on the supported PHP versions, checks
that the plugin header, `WTA_VERSION` and the readme stable tag agree, and the
org lint checks (actionlint, prettier, markdownlint, yamllint) run on the PR.

Releases follow the org versioning policy
([nyuchi/.github#80](https://github.com/nyuchi/.github/issues/80)): a merge into
`staging` is a patch, a release to `main` is the next minor, and a major is
only ever made by hand. See the Versioning section of the README.

## Version policy: floors, no ceilings

Every version constraint states a minimum we must not go under and never an
upper limit.

- **PHP**: the plugin requires PHP 8.2 or later (`Requires PHP` in the plugin
  header and `readme.txt`). There is no maximum. CI lints on the floor (8.2),
  the newest stable release (8.5) and the versions between them, and release
  jobs run on 8.3. When a new PHP release ships, add it to the matrix in
  `.github/workflows/ci.yml`; do not pin a ceiling.
- **Dependencies**: never add an upper bound such as `<9` to a `composer.json`
  or `package.json` constraint, and never add a `Tested up to` style cap that
  blocks anything. Raise a floor only when the code needs it.
- **GitHub Actions** stay pinned to a full commit SHA with the release tag in a
  comment, for example `actions/checkout@<sha>  # v7.0.1`. That pin is how
  Actions are secured, and Dependabot proposes the newer pin.
- **Dependabot** (`.github/dependabot.yml`) targets `staging` weekly, groups
  minor and patch updates, and opens major updates as separate PRs. Do not add
  `ignore` rules or version holds, so the newest version and majors can always
  be proposed.
- Raising a floor is a breaking requirement change for sites on older
  versions: it ships in the next minor release and the changelog says so.
