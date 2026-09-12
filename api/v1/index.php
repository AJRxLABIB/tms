<?php
/**
 * /api/v1/ — versioned alias for the REST API (Round 71, master prompt
 * item 42: "Eventually support: /api/v1/ ... Do not break existing
 * consumers.").
 *
 * This file intentionally contains nothing but a `require` of the real,
 * unversioned entry point at api/index.php. That file's own magic
 * constants (__DIR__/__FILE__) always resolve to its own real location
 * regardless of which script required it, so every path it builds off
 * `dirname(__DIR__)` (backend/config.php, etc.) still resolves correctly
 * here. The practical effect: /api/v1/?action=X behaves 100% identically
 * to /api/?action=X, by construction, not by keeping two copies in sync
 * by hand -- there is no second implementation to drift.
 *
 * Why not a "real" path-based v1 (e.g. /api/v1/packages instead of
 * /api/v1/?action=packages)? That would mean either duplicating every
 * handler's routing logic here (two copies to keep in sync, exactly the
 * drift risk this file avoids) or rewriting api/index.php's routing
 * itself, which risks the two other files that already depend on this
 * directory's existing layout: api/ai.php and api/explore.php. A future
 * real v2 (if request/response shapes ever need an actual breaking
 * change) is expected to live at api/v2/ as its own real implementation,
 * with this v1 alias and the legacy unversioned path both continuing to
 * serve existing consumers unchanged, per this same master-prompt item's
 * instruction not to break them.
 */
require __DIR__ . '/../index.php';
