# MainWP Field Manager

Adds configurable custom fields to MainWP sites, overview widgets, and bulk editing tools.

## Overview

MainWP Field Manager lets you create your own custom metadata fields for sites managed in MainWP, then use those fields across the dashboard.

It supports:

- custom field definitions
- text, textarea, and dropdown field types
- showing selected fields in the Manage Sites table
- overview widgets with counts by field value
- bulk updates across multiple sites
- import and export of plugin settings
- a stable/dev release channel setting for prerelease updates

The plugin is designed to make it easier to track additional information for client sites, internal operations, hosting details, notes, statuses, providers, and other custom labels directly inside MainWP.

---

## Features

### Custom field definitions

Create and manage custom fields from the plugin admin page.

Each field includes:

- **Label** — the visible name shown in the UI
- **Key** — the internal saved reference
- **Type** — text, textarea, or dropdown
- **Sites Table** — optionally show the field as a column in Manage Sites
- **Overview Widget** — optionally create a dashboard widget showing counts by value

### Supported field types

- **Text**
- **Textarea**
- **Dropdown**

Dropdown fields support:

- one option per line
- safe option rename rules
- protection against removing options that are still in use

### MainWP site integration

Custom fields appear on the MainWP site edit screen and values are saved per website using MainWP website options.

### Manage Sites table integration

Fields marked for table display are added as custom columns in the MainWP Manage Sites table.

### Site information widget integration

Saved field values are shown in the site information area for each site.

### Overview widgets

Fields marked for overview counting can generate a MainWP overview widget showing how many sites use each value.

### Bulk update tools

Bulk update lets you update one custom field across many selected sites.

Supported actions include:

#### Text fields

- set
- clear
- replace old text with new text

#### Textarea fields

- set
- clear
- append
- prepend
- replace old text with new text

#### Dropdown fields

- set selected option
- clear
- replace one option with another

---

## Settings and release channel

The plugin includes a settings page with a **Stable / Dev** release channel option.

- **Stable**: normal release channel
- **Dev**: allows prerelease/dev updates when used with the updater

---

## Import / Export

Plugin configuration can be exported and imported as JSON.

---

## License

This plugin is licensed under the GNU General Public License v3.0.

See https://www.gnu.org/licenses/gpl-3.0.html for details.
