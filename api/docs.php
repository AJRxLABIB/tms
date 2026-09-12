<?php
/**
 * api/docs.php — interactive OpenAPI documentation for the REST API
 * (Round 71, master prompt item 42: "OpenAPI documentation").
 *
 * Renders api/openapi.yaml through Swagger UI (loaded from jsdelivr, the
 * same CDN this project already uses for Chart.js on admin/dashboard.php
 * — not a new external dependency pattern). This page itself requires no
 * login and makes no database queries; it's a static viewer over a static
 * spec file, so there's nothing here that can leak data or need auth.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>TMS API Documentation</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
<style>
  body { margin: 0; background: #fafafa; }
  .topbar { display: none !important; } /* Swagger UI's own topbar duplicates this page's <title> for no benefit here */
</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>
window.onload = function() {
  window.ui = SwaggerUIBundle({
    url: 'openapi.yaml',
    dom_id: '#swagger-ui',
    presets: [SwaggerUIBundle.presets.apis],
    layout: 'BaseLayout',
    deepLinking: true
  });
};
</script>
</body>
</html>
