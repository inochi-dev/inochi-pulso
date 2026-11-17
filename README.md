# Inochi Pulso

Find which plugin is slowing your WordPress site: a cost per plugin table (autoload, own tables, cron events and scheduled actions) plus database, server, security basics and WooCommerce checks, in one read-only scan.

This is the development repository of the **Inochi Pulso** plugin for WordPress.org. Install it from WordPress.org once it is published; use this repository to read the code or report issues.

- Read-only: the scan changes nothing on your site.
- Works without any external service by default. The optional WordPress.org check sends only plugin slugs, and only after you enable it.
- Attribution without guessing: when more than one plugin could own an item, it is listed as "Unattributed".
- Exports: printable HTML, CSV and JSON, without usernames or email addresses.
- Site Health tests and Spanish translation (es_ES) included.

## Requirements

WordPress 6.6+ and PHP 8.0+.

## Releases

Each version is tagged here (`vX.Y.Z`) and published to the WordPress.org SVN repository (`trunk` and `tags/X.Y.Z`). Directory assets (banner, icon, screenshots) live in `.wordpress-org/`.

## License

GPLv2 or later. Made by [Inochi](https://inochi.dev/).
