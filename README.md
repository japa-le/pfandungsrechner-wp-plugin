# Pfändungsrechner WP Plugin

WordPress plugin to calculate the garnishable amount (**Pfändungsbetrag**) based on **§ 850c ZPO**.  
The plugin stores table values in the WordPress database, provides a frontend calculator via shortcode, and includes automatic yearly update logic.

---

## Features

- ✅ Frontend calculator for net income + dependents
- ✅ Shortcode integration: `[pfaendungsrechner]`
- ✅ Data stored in custom DB table (`wp_pfaendungstabellen` with site prefix)
- ✅ Automatic yearly update scheduling (1 July, or next workday)
- ✅ Manual admin trigger for testing/forced refresh
- ✅ Email notifications on success and error
- ✅ PDF parser dependency bundled via Composer (`smalot/pdfparser`)

---

## Requirements

- WordPress 6.x (recommended)
- PHP 7.4+ (8.x recommended)
- PHP extensions used by dependency: `iconv`, `zlib`

---

## Installation

### Option A: Manual (ZIP)

1. Download the repository as ZIP.
2. Extract to:
   `wp-content/plugins/pfaendungsrechner`
3. Ensure the `vendor/` directory is present.
4. Activate **Pfändungsrechner** in WP Admin → Plugins.

### Option B: Git clone

```bash
git clone https://github.com/japa-le/pfandungsrechner-wp-plugin.git pfaendungsrechner
```

Place the folder in `wp-content/plugins/` and activate it.

---

## Usage

Add the shortcode to a page/post:

```text
[pfaendungsrechner]
```

The calculator renders a form for:

- Net income (`Nettoeinkommen`)
- Number of dependents (`Unterhaltspflichtige Personen`)

Result is returned via AJAX.

---

## Automatic Update Behavior

On activation, the plugin:

1. Creates/updates the custom table.
2. Fills initial values.
3. Schedules yearly update event.

The scheduled updater:

- fetches source page/PDF
- parses values
- regenerates and imports table rows
- sends notification email to site admin

If update fails, current data remains active and an error email is sent.

---

## Manual Update Trigger (Admin)

For testing, use:

`/wp-admin/admin.php?page=pfaendungsrechner_trigger`

Only users with `manage_options` can access this endpoint.

---

## Project Structure

- `pfaendungsrechner.php` – main plugin logic
- `composer.json` / `composer.lock` – dependency definition
- `vendor/` – bundled Composer dependencies
- `.gitignore` – repo exclusions

---

## Security Notes

- AJAX requests are nonce-protected.
- Inputs are validated/sanitized before processing.
- Plugin exits early if `ABSPATH` is not defined.

---

## License

Private/client project by default unless explicitly licensed otherwise.


