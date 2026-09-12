<?php
/**
 * Minimal audio player page for embedding in contenteditable iframes.
 * Chrome does not render <audio> controls inside contenteditable="true" zones,
 * so we embed audio in an iframe whose src points to this page.
 */

// Use the same session/auth infrastructure as the main app
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Verify the user is authenticated (uses the centralized auth system with
// session, remember-me cookies, and OIDC support)
if (!isAuthenticated()) {
    http_response_code(401);
    exit('Unauthorized');
}

// Sanitize parameters
$noteId = intval($_GET['note'] ?? 0);
$attachmentId = htmlspecialchars($_GET['attachment'] ?? '', ENT_QUOTES);
$workspace = htmlspecialchars($_GET['workspace'] ?? '', ENT_QUOTES);

if (!$noteId || !$attachmentId) {
    http_response_code(400);
    exit('Missing parameters');
}

// Build the attachment URL
$src = '/api/v1/notes/' . $noteId . '/attachments/' . urlencode($attachmentId);
if ($workspace) {
    $src .= '?workspace=' . urlencode($workspace);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body {
    width: 100%;
    height: 100%;
    overflow: hidden;
    background: transparent;
  }
  body {
    display: flex;
    align-items: center;
  }
  audio {
    width: 100%;
    height: 100%;
    display: block;
    margin: 0;
    border: 0;
    background: transparent;
    border-radius: 8px;
  }
</style>
</head>
<body>
<audio controls preload="metadata" src="<?php echo $src; ?>"></audio>
<script>
// A right-click in this frame never reaches the note around it: hand it to the
// attachment menu there (js/note-attachment-menu.js), and let any other click
// close that menu. Hosts without the menu keep the browser's own.
(function () {
  function host(name) {
    try {
      return (window.frameElement && window.parent && typeof window.parent[name] === 'function') ? window.parent[name] : null;
    } catch (e) {
      return null;
    }
  }
  document.addEventListener('contextmenu', function (event) {
    var open = host('openNoteAttachmentMenuFromFrame');
    if (open && open(window.frameElement, event.clientX, event.clientY)) {
      event.preventDefault();
    }
  });
  document.addEventListener('mousedown', function (event) {
    var close = event.button !== 2 ? host('closeNoteAttachmentMenu') : null;
    if (close) close();
  });
  document.addEventListener('keydown', function (event) {
    var close = event.key === 'Escape' ? host('closeNoteAttachmentMenu') : null;
    if (close) close();
  });
})();
</script>
</body>
</html>
