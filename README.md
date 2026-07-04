# Potts Narrative Ancestor Book

A custom webtrees module that creates a personalised narrative ancestor book from GEDCOM facts, family events, notes and media.

## Version

0.8.1

## What it does

- Adds a **Your Book** menu item with a custom icon.
- Lets visitors search for an individual in the active tree and use that person as the root of the book.
- Generates a readable ancestor book across 1 to 8 generations.
- Supports personal wording such as **you**, **your father** and **your great-grandmother**, or neutral wording such as **the selected person’s father**.
- Builds the story in chronological order using births, christenings, education, residences, occupations, migration, military service, marriages, children, deaths, burials and selected life events.
- Adds ages at major life events where useful without overloading the story.
- Groups children into natural family paragraphs.
- Includes thumbnail photographs and compact photo panels where dated events have attached media.
- Provides a print-friendly layout for browser PDF export.
- Provides a HTML download that can be opened in Microsoft Word and saved as DOCX.
- Applies webtrees privacy checks before showing people, suggestions and media.

## Requirements

- webtrees 2.2 is recommended.
- PHP 8.3 or later is recommended for current webtrees 2.2 installations.
- No Composer install step is required.

## Installation

### Manual installation

1. Download the release ZIP named `potts_narrative_ancestor_book_v0.8.1.zip` from the GitHub release assets.
2. Do **not** use GitHub’s automatic `Source code.zip` download for installation.
3. Unzip the release package.
4. Copy the included `potts_narrative_ancestor_book` folder into your webtrees `modules_v4` folder.
5. In webtrees, go to **Control panel → Modules**.
6. Enable **Potts Narrative Ancestor Book**.
7. Use the **Your Book** menu item to generate a book.

### Custom Module Manager

This module includes the public module metadata needed by webtrees and Custom Module Manager:

- `customModuleVersion()` returns the installed version.
- `customModuleLatestVersionUrl()` points to `latest-version.txt` in the GitHub repository.
- `customModuleSupportUrl()` points to the GitHub repository.
- The release asset contains the module folder at the top level, ready for `modules_v4`.

After the GitHub repository and release are public, the Custom Module Manager can use the repository/release information to check versions and install the release asset.

## Upgrade notes

Before upgrading, remove or disable older folders such as `narrative_ancestor_book` or `pots_narrative_ancestor_book` if they are still installed. The folder name for this module should be:

```text
potts_narrative_ancestor_book
```

This module does not store generated books in the module folder, so replacing the module folder should not overwrite generated books.

## Release process

For a new release:

1. Update `CUSTOM_VERSION` in `module.php`.
2. Update `latest-version.txt`.
3. Update `README.md` and `CHANGELOG.md`.
4. Commit the changes.
5. Create and push a tag such as `v0.8.1`.
6. Attach the generated ZIP asset named `potts_narrative_ancestor_book_v0.8.1.zip` to the GitHub release.

If GitHub Actions is enabled, the included workflow will build the release ZIP when a `v*` tag is pushed.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Support

Use GitHub issues for bug reports and feature requests:

https://github.com/PottsNet/potts-narrative-ancestor-book/issues

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
