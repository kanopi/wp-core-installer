# kanopi/wp-core-installer

A Composer plugin that safely installs WordPress core into your web-root
**without** overwriting your project's own files, and keeps a `.gitignore`
block up to date for every Composer-managed WordPress package.

## The problem it solves

The default `johnpbloch/wordpress-core-installer` maps the install path
directly to your project root or web-root, so Composer's extractor
**mirrors / clobbers the entire target directory**, wiping `composer.json`,
`composer.lock`, `.env` and everything else that lives there.

This plugin instead:

1. Extracts WordPress core into a **private staging directory** inside
   `vendor/`, so Composer's own tracking works normally.
2. **Selectively copies** files from staging into your web-root.
3. **Never touches** a built-in list of protected paths (plus any you add).
4. **Removes core files** that a newer WordPress release no longer ships.
5. **Manages two `.gitignore` blocks**: one for core files, one for every
   Composer-managed plugin, theme, drop-in, language pack and the vendor
   directory. You can turn either off.
6. **Writes an autoloader mu-plugin** so WordPress loads Composer's
   `vendor/autoload.php`.

---

## Requirements

- Composer 2
- PHP 8.0 or newer **for the PHP that runs Composer**. The plugin only runs
  inside Composer. The one file it adds to your site (the autoloader
  mu-plugin) has no special PHP requirements.

## Installation

```bash
composer config allow-plugins.kanopi/wp-core-installer true
composer require kanopi/wp-core-installer
composer require johnpbloch/wordpress-core
```

Any package of type `wordpress-core` works, for example
`johnpbloch/wordpress-core` or `roots/wordpress-no-content`.

The plugin conflicts with `johnpbloch/wordpress-core-installer`,
`roots/wordpress-core-installer` and `fancyguy/webroot-installer`, which do
the same job destructively. Remove them first.

For plugins and themes, use [`composer/installers`](https://github.com/composer/installers)
with `installer-paths` that match your web-root:

```json
{
    "extra": {
        "wordpress-install-dir": "web",
        "installer-paths": {
            "web/wp-content/plugins/{$name}/": ["type:wordpress-plugin"],
            "web/wp-content/themes/{$name}/": ["type:wordpress-theme"],
            "web/wp-content/mu-plugins/{$name}/": ["type:wordpress-muplugin"]
        }
    }
}
```

---

## Configuration

Everything is optional. `wordpress-install-dir` sits directly under `extra`,
and all other settings go under `extra.wp-core-installer` in your
**project's** `composer.json`:

```json
{
    "extra": {
        "wordpress-install-dir": "web",
        "wp-core-installer": {
            "protected-paths": ["config", "my-custom-loader.php"],
            "skip-if-exists": ["robots.txt"],
            "manage-gitignore": true,
            "manage-mu-plugin-autoloader": true,
            "mu-plugins-dir": "wp-content/mu-plugins",
            "mu-plugin-autoloader-file": "000-autoloader.php"
        }
    }
}
```

| Setting | Type | Default | Description |
|---|---|---|---|
| `wordpress-install-dir` | string | `"public"` | Where core is deployed (the web-root). See [below](#wordpress-install-dir). |
| `protected-paths` | string[] | `[]` | Extra paths, relative to the web-root, that are never copied, deleted or gitignored. Adds to the [built-in list](#built-in-protected-paths). |
| `skip-if-exists` | string[] | `[]` | Extra paths that are copied on **first** install only and never overwritten or gitignored. Adds to the [built-in list](#built-in-skip-if-exists-paths). |
| `manage-gitignore` | bool or object | `true` | `false` turns off both [managed blocks](#managed-gitignore-blocks). `{"core": false}` or `{"packages": false}` turns off one. |
| `manage-mu-plugin-autoloader` | bool | `true` | `false` stops the plugin from writing (and gitignoring) the [autoloader mu-plugin](#autoloader-mu-plugin). |
| `mu-plugins-dir` | string | `"wp-content/mu-plugins"` | The mu-plugins directory, **relative to the web-root**, or absolute. |
| `mu-plugin-autoloader-file` | string | `"000-autoloader.php"` | Filename of the autoloader mu-plugin. |

Settings of the wrong type stop Composer with a message that names the
setting, for example:

```
WP Core Installer: extra.wp-core-installer.protected-paths in composer.json must be an array of strings.
```

### `wordpress-install-dir`

| Value | Web-root |
|---|---|
| *(omitted)* | `<project>/public` |
| `"."` | The project root itself |
| `"web"`, `"public/wp"` | That directory under the project root |
| `"/srv/www/site"` | That absolute path |

Values are normalised, so `./public`, `public/` and `public//` all mean
`public`. Relative paths are resolved from Composer's working directory,
which is the directory you run `composer` in (or the one passed to
`--working-dir`). Composer uses the same rule for `vendor-dir` and
`installer-paths`.

---

## How core is deployed

On `composer install` and `composer update`:

1. Composer extracts the core package to
   `<vendor-dir>/.wordpress-core-staging/<package-name>/`.
2. Files are copied from there into the web-root, following the
   [protection model](#three-tier-protection-model).
3. Files the **previous** deploy wrote, but the new release no longer ships,
   are deleted, and directories left empty are removed. WordPress's own
   updater does the same thing, because leftover core files have been a
   security problem in the past.
4. A deploy manifest is saved to
   `<vendor-dir>/.wordpress-core-staging/.deploy-manifest.json`. It records
   the package, version, web-root, protection settings and deployed files.

**Unchanged runs skip the copy.** Even during a deploy, files whose content
already matches are not rewritten. Core is only redeployed when:

- the core package is installed, updated or reinstalled;
- the manifest doesn't match the installed package, the web-root or the
  protection settings;
- any file the manifest lists is missing from the web-root (for example
  on a fresh checkout that reuses a cached `vendor/`).

Otherwise Composer reports `… is up to date in the web-root; skipping deploy.`
and the web-root is left alone.

**Deletion safety:**

- Only files that are in the previous manifest are ever deleted. Files you
  added to `wp-admin/` or `wp-includes/` are never listed, so they're
  never removed.
- Protected and skip-if-exists paths are never deleted.
- With no previous manifest (the first run, `vendor/` wiped, or an
  upgrade from 1.1.x), nothing is deleted.
- If the web-root has moved since the last deploy, nothing is deleted.

**Removing core** (`composer remove johnpbloch/wordpress-core`) removes the
staging directory and the core `.gitignore` block. It leaves the web-root
alone, because a live site may be running there.

---

## Commands

The plugin adds three Composer commands. Each one works on the installed
`wordpress-core` package and your configured web-root.

### `composer wp-core:status`

Checks whether the web-root matches the installed core package. It compares
the content of every always-synced file, and reports files that are missing,
changed or stale.

```
$ composer wp-core:status
  Package:               johnpbloch/wordpress-core 6.8.3
  Web-root:              /srv/site/web
  Last deploy:           6.8.3.0, 3021 files
Out of sync: 1 to update.
  Create:                0
  Update:                1
  …
Run composer wp-core:deploy to bring the web-root in line.
```

It exits **0** when the web-root is in sync and **1** when it has drifted or
core is missing, so it can gate a CI build. Add `-v` to list the affected
files.

### `composer wp-core:deploy [--dry-run] [--force]`

Deploys core outside of `composer install`, for example after someone has
edited a core file. Only new and changed files are written; unchanged files
keep their timestamps. Stale files are deleted, and the manifest and
`.gitignore` core block are refreshed.

- `--dry-run` prints what would change and writes nothing. Add `-v` to list
  the files.
- `--force` rewrites every core file, including unchanged ones.

### `composer wp-core:verify [--locale=en_US] [--checksums-file=PATH]`

Checks the deployed core files against the MD5 checksums WordPress.org
publishes for the installed release. It's the same check as
`wp core verify-checksums`, but it needs neither WP-CLI nor a database.

- **Modified or missing** core files fail the check (exit code 1).
- **Unexpected** files in `wp-admin/` or `wp-includes/`, meaning files the
  release doesn't ship, are listed as warnings.
- Protected and skip-if-exists paths are never checked.
- `--checksums-file` reads a saved API response instead of downloading one.
  That's useful offline or in locked-down CI:
  `curl -o checksums.json "https://api.wordpress.org/core/checksums/1.0/?version=6.8.3&locale=en_US"`.

---

## Three-tier protection model

| Tier | Copied | Deleted when core drops it | Gitignored |
|---|---|---|---|
| **Protected** | Never | Never | Never |
| **Skip-if-exists** | First install only | Never | Never |
| **Everything else** | Every deploy | Yes | Yes |

Skip-if-exists is checked first. That lets the `index.php` stubs inside
`wp-content/themes` and `wp-content/plugins` land on first install even
though those directories are protected.

### Built-in protected paths

Relative to the web-root. A directory protects everything inside it.

| Path | Reason |
|---|---|
| `composer.json`, `composer.lock` | Project manifests |
| `wp-config.php` | WordPress runtime config |
| `wp-content/themes`, `wp-content/plugins`, `wp-content/mu-plugins` | Project-owned code (bundled default themes and plugins are not deployed) |
| `wp-content/uploads` | User-uploaded media |
| `wp-content/upgrade`, `wp-content/languages` | Directories WordPress manages |
| `.env`, `.env.local`, `.env.staging`, `.env.production` | Environment and secrets |
| `.git`, `.gitignore`, `.gitattributes`, `.editorconfig` | VCS and editor files |
| `node_modules`, `vendor` | Other dependency trees |

### Built-in skip-if-exists paths

| Path | Reason |
|---|---|
| `.htaccess` | Server config you are likely to customise |
| `wp-config-sample.php` | Reference file |
| `wp-content/index.php`, `wp-content/themes/index.php`, `wp-content/plugins/index.php`, `wp-content/mu-plugins/index.php` | Silence-is-golden directory-listing guards |

---

## Managed `.gitignore` blocks

The plugin maintains **two independent marked blocks** in the project's
`.gitignore`, next to `composer.json`. Each block is replaced wholesale on
every run, so adding or removing a package keeps the list in sync.
Everything outside the blocks is left untouched.

This is the output for `"wordpress-install-dir": "web"`, the default
`vendor-dir`, one plugin and one theme:

```gitignore
# <kanopi/wp-core-installer:core:begin>
# Managed by kanopi/wp-core-installer — do not edit this block manually.

# WordPress core staging directory (Composer internal — do not commit)
/vendor/.wordpress-core-staging/

# WordPress core files (managed via Composer — do not commit)
/web/index.php
/web/wp-admin/
/web/wp-includes/
/web/wp-load.php
/web/wp-settings.php
…

# <kanopi/wp-core-installer:core:end>

# <kanopi/wp-core-installer:packages:begin>
# Managed by kanopi/wp-core-installer — do not edit this block manually.

# Composer vendor directory
/vendor/

# Composer autoloader mu-plugin (regenerated on every composer install)
/web/wp-content/mu-plugins/000-autoloader.php

# Composer-managed WordPress plugins
/web/wp-content/plugins/akismet/

# Composer-managed WordPress themes
/web/wp-content/themes/twentytwentyfive/

# <kanopi/wp-core-installer:packages:end>
```

### Core block

This block lists the core files the last deploy wrote. Top-level
directories such as `wp-admin/` get one rule each. Inside `wp-content/`,
which also holds your own code, only the individual core files are listed.
Skip-if-exists files are never included, so a customised `.htaccess` can be
committed.

### Packages block

This block lists the vendor directory, the autoloader mu-plugin and every
installed package of these types, at whatever path its installer put it:

| Package type | Section |
|---|---|
| `wordpress-plugin` | plugins |
| `wordpress-theme`, `wordpress-theme-custom` | themes |
| `wordpress-muplugin` | must-use plugins |
| `wordpress-dropin` | drop-ins |
| `wordpress-language` | language packs |

Two kinds of package are left out:

- Packages installed **inside `vendor/`**, which is already ignored. This is
  where packages end up when no installer handles their type.
- Packages installed **outside the project root**, which can't be expressed
  in the project's `.gitignore`.

### Build-artifact deploys (Pantheon and similar)

Some hosts deploy by committing the **built** site, including core and
plugins, to the host's git repository. Pantheon's `terminus build:env:push`
is one example. There, these blocks would strip the build of exactly what
it needs to ship. Turn them off:

```json
"extra": {
    "wp-core-installer": {
        "manage-gitignore": false
    }
}
```

A disabled block is **removed** from `.gitignore` if it's already there, so
switching the setting off on an existing project takes effect on the next
`composer install`. To keep just one block, use `{"core": false}` or
`{"packages": false}`.

---

## Autoloader mu-plugin

WordPress doesn't load files from subdirectories of `mu-plugins/`, so the
plugin writes a small bootstrap file that requires Composer's autoloader:
`<web-root>/wp-content/mu-plugins/000-autoloader.php` by default.

- It's regenerated on every run, and the path to `vendor/autoload.php` is
  computed from your actual `vendor-dir`.
- It's gitignored in the packages block, like `vendor/` itself.
- It's only written when a `wordpress-core` package is installed.
- **Your own file is never overwritten.** The generated file carries an
  `@generated kanopi/wp-core-installer` marker. If a file already at that
  path has no marker (and isn't a 1.1.x-generated file), it's left alone,
  isn't gitignored, and Composer prints a warning. To avoid that, use
  `mu-plugin-autoloader-file` to pick another name, or set
  `manage-mu-plugin-autoloader` to `false`.

---

## Typical project layout

With `"wordpress-install-dir": "web"`:

```
my-wordpress-site/
├── composer.json                  ← yours
├── composer.lock                  ← yours
├── .gitignore                     ← yours, plus the two managed blocks
├── vendor/                        ← gitignored (includes the staging dir + manifest)
└── web/
    ├── wp-config.php              ← protected (you create this)
    ├── .htaccess                  ← skip-if-exists (first install only)
    ├── wp-config-sample.php       ← skip-if-exists
    ├── index.php                  ← deployed; gitignored
    ├── wp-admin/                  ← deployed; gitignored
    ├── wp-includes/               ← deployed; gitignored
    └── wp-content/
        ├── index.php              ← skip-if-exists
        ├── mu-plugins/
        │   ├── 000-autoloader.php ← generated; gitignored
        │   └── my-mu-plugin.php   ← yours
        ├── plugins/
        │   ├── index.php          ← skip-if-exists
        │   ├── akismet/           ← Composer-managed; gitignored
        │   └── my-custom-plugin/  ← yours
        ├── themes/
        │   ├── index.php          ← skip-if-exists
        │   ├── twentytwentyfive/  ← Composer-managed; gitignored
        │   └── my-custom-theme/   ← yours
        └── uploads/               ← protected
```

---

## Upgrading from 1.1.x

- **PHP 8.0 now works.** 1.1.x declared PHP 8.0 support, but its code
  failed to parse on 8.0.
- **Omitted `wordpress-install-dir`:** the autoloader mu-plugin now goes to
  `public/wp-content/mu-plugins/`, next to core, where WordPress loads it.
  In 1.1.x it went to `./wp-content/mu-plugins/`. You can delete that stray
  copy.
- **`.htaccess`, `wp-config-sample.php` and the `index.php` stubs are no
  longer gitignored.** Commit them, or add your own ignore rules.
- **Stale core files are cleaned up from the second deploy onward.** The
  first 1.2 run has no manifest yet, so it deletes nothing.
- **Invalid settings now fail loudly.** A value of the wrong type, which
  1.1.x might have silently accepted, now stops Composer with a message.

---

## Development

```bash
composer install     # dev dependencies (the integration tests need vendor/)
composer check       # lint + static analysis + all tests
```

| Command | What it runs |
|---|---|
| `composer lint` / `composer lint:fix` | PHP_CodeSniffer (PSR-12) on `src/` and `tests/Unit/` |
| `composer analyse` | PHPStan at level max |
| `composer test:unit` | PHPUnit (`tests/Unit/`) |
| `composer test:integration` | [bats-core](https://github.com/bats-core/bats-core) (`tests/integration/`) |
| `composer test` | Unit and integration tests |

The integration suite drives the real `composer` binary against fixture
packages in throwaway projects. It works offline: `composer/installers` is
installed from this repo's `vendor/` as a path repository. It needs
`bats` and `composer` on your `PATH`.

CI (CircleCI) runs the `quality` checks once, and runs the unit and
integration tests on every supported PHP version, 8.0 to 8.5.

---

## License

MIT
