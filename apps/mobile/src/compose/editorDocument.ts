import { EDITOR_SCRIPT } from '@/compose/editorBundle.generated'
import type { Theme } from '@/theme'

function escapeAttribute(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;')
}

/**
 * The page the message editor runs in. Nothing loads from the network: the Content-Security-Policy allows only the
 * editor's own inline script and styles, and images the user's text holds (data: and https:), never a connection.
 */
export function editorDocument(theme: Theme, labels: { label: string; placeholder: string }): string {
  return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: https:">
<style>
html, body { margin: 0; padding: 0; height: auto; min-height: 0; background: ${theme.surface}; color: ${theme.ink}; }
body { font: 16px/24px -apple-system, system-ui, 'Segoe UI', Roboto, sans-serif; -webkit-text-size-adjust: 100%; }
#editor .tiptap { min-height: 96px; padding: 12px 16px 6px; outline: none; word-wrap: break-word; overflow-wrap: anywhere; }
#editor p { margin: 0 0 8px; }
#editor ul, #editor ol { margin: 0 0 8px; padding-left: 24px; }
#editor blockquote { margin: 0 0 8px; padding-left: 12px; border-left: 3px solid ${theme.line}; color: ${theme.ink2}; }
#editor a { color: ${theme.accent}; }
#editor p.is-editor-empty:first-child::before { content: attr(data-placeholder); color: ${theme.ink2}; float: left; height: 0; pointer-events: none; }
</style>
</head>
<body>
<div id="editor" data-label="${escapeAttribute(labels.label)}" data-placeholder="${escapeAttribute(labels.placeholder)}"></div>
<script>${EDITOR_SCRIPT.replace(/<\/script/gi, '<\\/script')}</script>
</body>
</html>`
}
