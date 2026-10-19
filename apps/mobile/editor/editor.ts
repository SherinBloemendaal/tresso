/**
 * The message editor inside the compose screen's web view: tiptap, as in the web client, so both write the same HTML.
 * It is bundled into `src/compose/editorBundle.generated.ts` by `npm run build:editor` and talks to the app through
 * JSON messages (see `src/compose/editorProtocol.ts`).
 */
import { Editor } from '@tiptap/core'
import { Link } from '@tiptap/extension-link'
import { Placeholder } from '@tiptap/extension-placeholder'
import { StarterKit } from '@tiptap/starter-kit'
import type { EditorCommand, EditorEvent } from '../src/compose/editorProtocol'

declare global {
  interface Window {
    ReactNativeWebView?: { postMessage(message: string): void }
    tresso?: { receive(message: string): void }
  }
}

function post(event: EditorEvent): void {
  window.ReactNativeWebView?.postMessage(JSON.stringify(event))
}

const root = document.getElementById('editor')
if (root === null) {
  throw new Error('The editor has no element to live in.')
}

/** Content set by the app is not echoed back as a change the user made. */
let applying = false

const editor = new Editor({
  element: root,
  extensions: [
    StarterKit.configure({ heading: false, codeBlock: false, code: false, horizontalRule: false, link: false }),
    Link.configure({ openOnClick: false, autolink: true, defaultProtocol: 'https', protocols: ['mailto'] }),
    Placeholder.configure({ placeholder: root.dataset.placeholder ?? '' }),
  ],
  editorProps: {
    attributes: { role: 'textbox', 'aria-multiline': 'true', 'aria-label': root.dataset.label ?? 'Message' },
  },
  onUpdate: ({ editor: current }) => {
    if (!applying) {
      post({ type: 'change', html: current.isEmpty ? '' : current.getHTML() })
    }
  },
  onSelectionUpdate: () => reportState(),
  onTransaction: () => reportState(),
  onFocus: () => post({ type: 'focus', focused: true }),
  onBlur: () => post({ type: 'focus', focused: false }),
})

let lastState = ''

function reportState(): void {
  const state: EditorEvent = {
    type: 'state',
    bold: editor.isActive('bold'),
    italic: editor.isActive('italic'),
    bulletList: editor.isActive('bulletList'),
    link: (editor.getAttributes('link').href as string | undefined) ?? null,
    canUndo: editor.can().undo(),
    canRedo: editor.can().redo(),
  }
  const serialized = JSON.stringify(state)
  if (serialized !== lastState) {
    lastState = serialized
    post(state)
  }
}

function run(command: EditorCommand): void {
  const chain = editor.chain().focus()
  switch (command.type) {
    case 'set':
      applying = true
      try {
        editor.commands.setContent(command.html, { emitUpdate: false })
      } finally {
        applying = false
      }
      reportState()
      return
    case 'focus':
      editor.commands.focus('end')
      return
    case 'bold':
      chain.toggleBold().run()
      return
    case 'italic':
      chain.toggleItalic().run()
      return
    case 'bulletList':
      chain.toggleBulletList().run()
      return
    case 'link':
      if (command.href === null) {
        chain.extendMarkRange('link').unsetLink().run()
      } else if (editor.state.selection.empty && !editor.isActive('link')) {
        // Nothing selected: the address itself becomes the linked text.
        chain.insertContent({ type: 'text', text: command.text ?? command.href, marks: [{ type: 'link', attrs: { href: command.href } }] }).run()
      } else {
        chain.extendMarkRange('link').setLink({ href: command.href }).run()
      }
      return
    case 'undo':
      chain.undo().run()
      return
    case 'redo':
      chain.redo().run()
      return
  }
}

window.tresso = {
  receive(message: string): void {
    try {
      run(JSON.parse(message) as EditorCommand)
    } catch (error) {
      post({ type: 'error', message: error instanceof Error ? error.message : String(error) })
    }
  },
}

new ResizeObserver(() => post({ type: 'height', value: Math.ceil(document.documentElement.scrollHeight) })).observe(document.body)
post({ type: 'ready' })
