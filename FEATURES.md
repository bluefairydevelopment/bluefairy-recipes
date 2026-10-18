# BlueFairy Recipes — Features Guide

**In development: 1.0.0 is not released.** Sections are added as each milestone lands and
every claim is verified against the code before the release.

## Settings

Stored in one option, `bfrc_settings`. The settings screen arrives with a later milestone;
until then the values below are the defaults the plugin installs.

| Setting | Default | Notes |
|---|---|---|
| Enabled | On | |
| Rich snippets | On | |
| Archive slug | `recipes` | Changing it flushes rewrite rules on the next page load. Routes WordPress owns (`feed`, `page`, `wp-json`, a year, …) are refused |
| Archive title | Recipes | |
| Recipes per page | 12 | 1–100 |
| Social share | On | Facebook, X and Pinterest each have their own switch |
| Writer listing | On | |
| Grant core roles | On | Authors can write recipes and Editors can manage them; turning it off takes those capabilities back. Administrators can always do everything |
| Delete all data on uninstall | Off | |

## Roles

| Role | Can |
|---|---|
| Recipe Writer (`bfrc_writer`) | create, edit, publish and delete their own recipes; assign terms; upload files |
| Recipe Manager (`bfrc_recipe_manager`) | everything a writer can, for every recipe; manage the recipe taxonomies; change settings |

Administrators can always change settings.

## Extending

| Hook | Kind | Purpose |
|---|---|---|
| `bfrc_loaded` | action | Fired before the plugin binds anything; the Pro add-on attaches its filters here |
| `bfrc_booted` | action | Fired after every subsystem is wired |
| `bfrc_is_pro` | filter | Return true when a Pro add-on is running |

## Differences from the Magento extension

Written with the content model and front end (later milestones).
