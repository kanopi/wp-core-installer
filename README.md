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
composer require kanopi/wordpress-core
```

Any package of type `wordpress-core` works. [`kanopi/wordpress-core`](https://packagist.org/packages/kanopi/wordpress-core)
is Kanopi's mirror of WordPress releases and brings no installer of its own.
`johnpbloch/wordpress-core` and `roots/wordpress-no-content` work too.

For a complete `composer.json` for WP Engine, Pantheon or Kinsta, see
[Host recipes](#host-recipes).

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
| `deploy-bundled` | object | `{}` | Themes and plugins that ship with core to deploy anyway, e.g. `{"themes": ["twentytwentyfive"], "plugins": ["akismet", "hello.php"]}`. See [below](#bundled-themes-and-plugins). |
| `manage-gitignore` | bool or object | `true` | `false` turns off both [managed blocks](#managed-gitignore-blocks). `{"core": false}` or `{"packages": false}` turns off one. |
| `scaffold-wp-config` | bool | `false` | Create a starter `wp-config.php` in the web-root when none exists. See [below](#starter-wp-configphp). |
| `wp-config-template` | string | *(built-in)* | Template for `scaffold-wp-config`, relative to the project root, or absolute. |
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

## Host recipes

The docroot your host serves decides three settings:
- `wordpress-install-dir`,
- the prefix on every `installer-paths` entry,
- where `vendor/` lives, because it has to be inside whatever your deploy
  ships.

These are the setups Kanopi uses. Copy the one for your host, then add your
own plugins and themes.

| Host | Docroot | `vendor-dir` | Deploy |
|---|---|---|---|
| [WP Engine](#wp-engine) | repository root (`.`) | `vendor` (default) | CI build, then rsync |
| [Pantheon](#pantheon) | `web/` | `web/wp-content/mu-plugins/vendor` | CI build, then git push of the artifact (Terminus build-tools) |
| [Kinsta](#kinsta) | `public/` | `public/wp-content/mu-plugins/vendor` | CI build, rsync, then cache purge |

In every case, CI runs `composer install --no-dev` and deploys the result,
not the git repository. So the files the managed `.gitignore` blocks keep out
of your repo (core, Composer-managed plugins, `vendor/`, the autoloader
mu-plugin) still reach the server.

### WP Engine

WP Engine serves WordPress from the repository root.

```json
{
    "require": {
        "composer/installers": "^2.0",
        "kanopi/wp-core-installer": "^1.3",
        "kanopi/wordpress-core": "^6.8"
    },
    "config": {
        "allow-plugins": {
            "composer/installers": true,
            "kanopi/wp-core-installer": true
        }
    },
    "extra": {
        "wordpress-install-dir": ".",
        "installer-paths": {
            "wp-content/plugins/{$name}/":    ["type:wordpress-plugin"],
            "wp-content/themes/{$name}/":     ["type:wordpress-theme"],
            "wp-content/mu-plugins/{$name}/": ["type:wordpress-muplugin"]
        }
    }
}
```

- **Deploy:** CI runs `composer install --no-dev`, then rsyncs the built tree
  to `*.ssh.wpengine.net`.
- **WP Engine's own mu-plugins** (`wpengine-common` and friends) live in
  `wp-content/mu-plugins/`. The plugin never touches that directory apart from
  its own `000-autoloader.php`. Keep your rsync from deleting WP Engine's
  files, either by excluding them or by not using `--delete` on
  `wp-content/mu-plugins/`.

### Pantheon

Pantheon serves the `web/` directory (`web_docroot: true` in `pantheon.yml`).
Pantheon itself doesn't run Composer (`build_step: false`), so CI builds the
site and pushes it.

```json
{
    "require": {
        "composer/installers": "^2.0",
        "kanopi/wp-core-installer": "^1.3",
        "kanopi/wordpress-core": "^6.8"
    },
    "config": {
        "vendor-dir": "web/wp-content/mu-plugins/vendor",
        "allow-plugins": {
            "composer/installers": true,
            "kanopi/wp-core-installer": true
        }
    },
    "extra": {
        "wordpress-install-dir": "web",
        "installer-paths": {
            "web/wp-content/plugins/{$name}/":    ["type:wordpress-plugin"],
            "web/wp-content/themes/{$name}/":     ["type:wordpress-theme"],
            "web/wp-content/mu-plugins/{$name}/": ["type:wordpress-muplugin"]
        }
    }
}
```

- **`vendor-dir` goes under `web/`.** Only the docroot ships to Pantheon, so
  the Composer autoloader and dependencies have to live inside it. The
  autoloader mu-plugin works out the path from `vendor-dir` on its own.
- **Deploy:** CI runs `composer install --no-dev`, then
  `terminus build:env:push`, which commits the built artifact to Pantheon's
  repository. Remove `.gitignore` in that CI step (`rm .gitignore`) so the
  artifact includes core, plugins and `vendor/`. Your own repository keeps the
  managed blocks. If your pipeline can't remove the file, set
  `"manage-gitignore": false` instead (see
  [Build-artifact deploys](#build-artifact-deploys-pantheon-and-similar)).
- **Pantheon's platform plugins**, such as
  `pantheon-systems/pantheon-mu-plugin`, `wpackagist-plugin/wp-redis`,
  `wpackagist-plugin/wp-native-php-sessions` and
  `wpackagist-plugin/pantheon-advanced-page-cache`, are regular Composer
  requirements. Add the ones you use.
- **Integrated Composer** (`build_step: true`, where Pantheon runs Composer
  itself) is a different setup and isn't covered here.

### Kinsta

Kinsta serves the `public/` directory of the site (`/www/<site>_<id>/public/`).

```json
{
    "require": {
        "composer/installers": "^2.0",
        "kanopi/wp-core-installer": "^1.3",
        "kanopi/wordpress-core": "^6.8"
    },
    "config": {
        "vendor-dir": "public/wp-content/mu-plugins/vendor",
        "allow-plugins": {
            "composer/installers": true,
            "kanopi/wp-core-installer": true
        }
    },
    "extra": {
        "wordpress-install-dir": "public",
        "installer-paths": {
            "public/wp-content/plugins/{$name}/":    ["type:wordpress-plugin"],
            "public/wp-content/themes/{$name}/":     ["type:wordpress-theme"],
            "public/wp-content/mu-plugins/{$name}/": ["type:wordpress-muplugin"]
        }
    }
}
```

- **Commit the Kinsta MU plugin; don't install it with Composer.** It's
  required, because it provides the cache purge used after each deploy. It
  has to sit directly in `mu-plugins/` (`kinsta-mu-plugins.php` plus a
  `kinsta-mu-plugins/` folder), and Composer can't safely install a package
  into a folder other files share (see the warning below). Download it from
  `https://kinsta.com/kinsta-tools/kinsta-mu-plugins.zip`, unzip it into
  `public/wp-content/mu-plugins/`, and commit both entries. To upgrade,
  repeat that and commit the change. A safe Composer-managed option is
  planned (#46).
- **Deploy:** CI runs `composer install --no-dev`, rsyncs `public/*` to the
  site's `public/` directory, then purges the cache over SSH:
  `cd /www/<site>_<id>/public && wp kinsta cache purge --all`. The deploy
  isn't finished until the purge runs, or visitors keep getting stale pages.

### Don't install packages into a shared folder

Never point an `installer-paths` entry at a folder that other files also live
in. That means `wp-content/`, `wp-content/plugins/`, `wp-content/themes/` or
`wp-content/mu-plugins/` itself, as opposed to a `{$name}` subfolder inside
them.

Composer treats a package's install folder as belonging entirely to that
package:

- **Updating or removing the package deletes the whole folder**, and then
  reinstalls the package if it's an update. For `mu-plugins/`, that deletes
  every mu-plugin you own, the autoloader mu-plugin, and `vendor/` too when
  `vendor-dir` sits inside it. The update then fails with "corrupted zip
  archive", because the download it just made was deleted along with
  `vendor/`.
- **Installing empties the folder first**, unless `vendor-dir` is inside it.

Packages that must sit directly in a shared folder, like the Kinsta MU
plugin, should be committed to the repository until #46 adds a safe way to
manage them.

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
| `wp-content/themes`, `wp-content/plugins`, `wp-content/mu-plugins` | Project-owned code (bundled default themes and plugins are only deployed if listed in [`deploy-bundled`](#bundled-themes-and-plugins)) |
| `wp-content/uploads` | User-uploaded media |
| `wp-content/upgrade`, `wp-content/languages` | Directories WordPress manages |
| `.env`, `.env.local`, `.env.staging`, `.env.production` | Environment and secrets |
| `.git`, `.gitignore`, `.gitattributes`, `.editorconfig` | VCS and editor files |
| `node_modules`, `vendor` | Other dependency trees |

### Bundled themes and plugins

WordPress ships default themes and plugins, such as `twentytwentyfive`,
Akismet and Hello Dolly. Because `wp-content/themes` and `wp-content/plugins`
are protected, none of them is deployed unless you ask:

```json
"extra": {
    "wp-core-installer": {
        "deploy-bundled": {
            "themes": ["twentytwentyfive"],
            "plugins": ["akismet", "hello.php"]
        }
    }
}
```

- **Naming:** use the name as it appears in `wp-content/themes` or
  `wp-content/plugins`. That's a directory, or a file for single-file
  plugins like `hello.php`.
- **Listed items** are always-synced like other core files. They're updated
  with core, and each one is gitignored as a single entry (for example
  `/web/wp-content/themes/twentytwentyfive/`).
- **Dropping an item** from the list deletes the files the plugin deployed
  for it on the next `composer install`. Files you added inside that folder
  are kept. Run `composer wp-core:deploy --dry-run` first to see exactly
  what will be removed.
- **Your own `protected-paths` win.** Listing
  `wp-content/plugins/akismet` there keeps Akismet untouched even if it's
  also bundled.

A common use is keeping the latest default theme available as a fallback,
so WordPress still has a theme to load if the active one goes missing.

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
it needs to ship.

The usual fix is to delete `.gitignore` in the CI step that builds the
artifact (see the [Pantheon recipe](#pantheon)). If that isn't an option,
turn the blocks off:

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

## Starter `wp-config.php`

With `"scaffold-wp-config": true`, the plugin creates
`<web-root>/wp-config.php` when there isn't one. It does this on the first
`composer install` for new projects, and again whenever the file is missing.

It **never overwrites** an existing `wp-config.php`. It also skips creating
one when a `wp-config.php` already sits **one directory above the
web-root**, because WordPress loads that file and a second one would shadow
it. After that, the file is yours: it's protected, never updated and never
gitignored, so commit it.

The built-in template **reads everything from the environment and contains
no secrets**:

| Variable | Default |
|---|---|
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | empty |
| `DB_HOST` | `localhost` |
| `DB_CHARSET` / `DB_COLLATE` | `utf8mb4` / empty |
| `WP_TABLE_PREFIX` | `wp_` |
| `WP_ENVIRONMENT_TYPE`, `WP_HOME`, `WP_SITEURL` | not defined |
| `WP_DEBUG` | `false` (accepts `true`, `1`, `yes`, `on`) |
| `AUTH_KEY` … `NONCE_SALT` (all eight) | not defined |

If a key or salt isn't set, WordPress generates it and stores it in the
database (see `wp_salt()`), so the site works either way. For stable
sessions across servers, set them in the environment.

If [`vlucas/phpdotenv`](https://github.com/vlucas/phpdotenv) is installed,
a `.env` file in the project root is loaded first. The generated file also
requires Composer's autoloader before WordPress starts.

To use your own template, set `wp-config-template`. The template can use
these placeholders, both relative to the web-root and meant for use after
`__DIR__ . '/'`:

| Placeholder | Example |
|---|---|
| `{{AUTOLOAD_RELATIVE_PATH}}` | `../vendor/autoload.php` |
| `{{PROJECT_ROOT_RELATIVE_PATH}}` | `../` (empty when the web-root is the project root) |

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
    ├── wp-config.php              ← protected (you create it, or scaffold-wp-config does)
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
