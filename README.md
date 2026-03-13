# kanopi/wp-core-installer

A Composer plugin that safely installs WordPress core files into a configured
`web-root` **without** overwriting your project's own files, and automatically
manages a `.gitignore` block for all Composer-managed WordPress packages.

## The problem it solves

The default `johnpbloch/wordpress-core-installer` maps the install path
directly to your project root or web-root, causing Composer's extractor to
**mirror / clobber the entire target directory** — wiping `composer.json`,
`composer.lock`, `.env`, and everything else that lives there.

This plugin instead:

1. Extracts WordPress core into a **private staging directory** inside `vendor`
   so Composer's internal tracking works correctly.
2. **Selectively copies** files from staging into your web-root.
3. **Skips** a built-in list of protected paths (and any extras you declare).
4. **Manages two `.gitignore` blocks** — one for WP core files, one for every
   Composer-managed plugin, theme, and the vendor directory — so you never
   accidentally commit them.

---

## Installation

```bash
composer require kanopi/wp-core-installer
```

Then require WordPress core:

```bash
composer require johnpbloch/wordpress-core
```

---

## Configuration

All configuration lives under `extra` in your **project's** `composer.json`:

```json
{
    "extra": {
        "wordpress-install-dir": ".",

        "wp-core-installer": {
            "protected-paths": [
                "my-custom-loader.php",
                "config"
            ],
            "skip-if-exists": [
                "robots.txt"
            ]
        }
    }
}
```

### `wordpress-install-dir`

| Value | Meaning |
|-------|---------|
| `"."` | Deploy WP files into the project root itself |
| `"public"` | Deploy into `<project-root>/public/` |
| `"public/wp"` | Deploy into `<project-root>/public/wp/` |
| *(omitted)* | Defaults to `"public"` |

### `wp-core-installer.protected-paths`

Additional paths **relative to the web-root** that must never be touched.
Extends the built-in list.

### `wp-core-installer.skip-if-exists`

Paths that are copied on **first** install only, never overwritten on update.
The built-in list covers `.htaccess`, `wp-config-sample.php`, and the
silence-is-golden `wp-content/index.php` stubs.

---

## Three-tier protection model

| Tier | Behaviour | Examples |
|------|-----------|---------|
| `protected-paths` | Never copied, never gitignored | `wp-config.php`, `wp-content/themes`, `wp-content/plugins` |
| `skip-if-exists` | Copied on first install only; not gitignored | `.htaccess`, `wp-content/index.php` |
| Everything else | Always synced from core; gitignored | `wp-admin/`, `wp-includes/`, `wp-login.php` |

---

## Managed `.gitignore` blocks

The plugin writes and maintains **two independent marked blocks** in your
project's `.gitignore`. Everything outside these blocks is untouched.

### Core block

Updated whenever `composer install` or `composer update` runs the WordPress
core installer:

```gitignore
# <kanopi/wp-core-installer:core:begin>
# Managed by kanopi/wp-core-installer — do not edit this block manually.

# WordPress core staging directory (Composer internal — do not commit)
/wp-content/mu-plugins/vendor/.wordpress-core-staging/

# WordPress core files (managed via Composer — do not commit)
/wp-admin/
/wp-includes/
/index.php
/wp-activate.php
…

# <kanopi/wp-core-installer:core:end>
```

### Packages block

Updated on every `composer install` / `composer update` run via a
`post-install-cmd` / `post-update-cmd` hook:

```gitignore
# <kanopi/wp-core-installer:packages:begin>
# Managed by kanopi/wp-core-installer — do not edit this block manually.

# Composer vendor directory
/wp-content/mu-plugins/vendor/

# Composer-managed WordPress plugins
/wp-content/plugins/akismet/
/wp-content/plugins/woocommerce/

# Composer-managed WordPress themes
/wp-content/themes/twentytwentyfive/

# <kanopi/wp-core-installer:packages:end>
```

Both blocks are **replaced wholesale** on each run (not appended), so adding
or removing a package automatically keeps the ignore list in sync.
Running `composer remove johnpbloch/wordpress-core` strips the **core** block.

---

## Built-in protected paths (never overwritten)

| Path | Reason |
|------|--------|
| `composer.json`, `composer.lock` | Project manifests |
| `wp-config.php` | WordPress runtime config |
| `wp-content/themes` | Project-owned themes |
| `wp-content/plugins` | Project-owned plugins |
| `wp-content/mu-plugins` | Project-owned must-use plugins |
| `wp-content/uploads` | User-uploaded media |
| `wp-content/upgrade`, `wp-content/languages` | WordPress-managed dirs |
| `.env` / `.env.*` | Environment / secrets |
| `.git`, `.gitignore`, `.gitattributes` | VCS metadata |
| `node_modules`, `vendor` | Other dependency trees |

---

## Typical project layout

```
my-wordpress-site/
├── composer.json               ← protected
├── composer.lock               ← protected
├── wp-config.php               ← protected (you create this)
├── wp-admin/                   ← deployed by this plugin; gitignored
├── wp-includes/                ← deployed by this plugin; gitignored
├── index.php                   ← deployed by this plugin; gitignored
├── .htaccess                   ← skip-if-exists (first install only)
└── wp-content/
    ├── index.php               ← skip-if-exists (first install only)
    ├── mu-plugins/
    │   └── vendor/             ← gitignored (Composer vendor dir)
    ├── plugins/
    │   ├── index.php           ← skip-if-exists
    │   └── akismet/            ← gitignored (Composer-managed plugin)
    └── themes/
        ├── index.php           ← skip-if-exists
        ├── twentytwentyfive/   ← gitignored (Composer-managed theme)
        └── my-custom-theme/    ← NOT gitignored (not Composer-managed)
```

---

## License

MIT