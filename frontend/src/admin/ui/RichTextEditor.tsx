import { EditorContent, useEditor, useEditorState } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { useEffect, useId, useState } from 'react'
import type { ReactNode } from 'react'
import { borderClass, controlClass, FieldWrap } from './Fields'

/**
 * Formatting is limited to what the server-side HTML sanitiser keeps (headings h2–h4, emphasis,
 * lists, quotes, rules and links), so what editors see is what gets published.
 */
export function RichTextEditor({ label, value, onChange, error, hint, required }: { label: ReactNode; value: string; onChange: (html: string) => void; error?: string; hint?: ReactNode; required?: boolean }) {
  const id = useId()
  const [source, setSource] = useState(false)
  const editor = useEditor({
    extensions: [StarterKit.configure({ code: false, codeBlock: false, heading: { levels: [2, 3, 4] }, link: { openOnClick: false, autolink: true, protocols: ['mailto'] } })],
    content: value || '',
    onUpdate: ({ editor: e }) => onChange(e.isEmpty ? '' : e.getHTML()),
    editorProps: {
      attributes: {
        id,
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-label': typeof label === 'string' ? label : 'Rich text',
        class: 'prose-admin min-h-56 px-4 py-3 focus:outline-none',
      },
    },
  })

  useEffect(() => {
    // useEditor replaces its instance on remount, so effects can briefly see the destroyed one.
    if (!editor || editor.isDestroyed) return
    if (!editor.isFocused && value !== (editor.isEmpty ? '' : editor.getHTML())) {
      editor.commands.setContent(value || '', { emitUpdate: false })
    }
  }, [editor, value])

  const state = useEditorState({
    editor,
    selector: ({ editor: e }) =>
      e && !e.isDestroyed
        ? {
            bold: e.isActive('bold'),
            italic: e.isActive('italic'),
            underline: e.isActive('underline'),
            h2: e.isActive('heading', { level: 2 }),
            h3: e.isActive('heading', { level: 3 }),
            h4: e.isActive('heading', { level: 4 }),
            bullet: e.isActive('bulletList'),
            ordered: e.isActive('orderedList'),
            quote: e.isActive('blockquote'),
            link: e.isActive('link'),
          }
        : null,
  })

  if (!editor || editor.isDestroyed) return null

  const setLink = () => {
    const previous = editor.getAttributes('link').href as string | undefined
    const url = window.prompt('Link address (https://… or mailto:…). Leave empty to remove.', previous ?? 'https://')
    if (url === null) return
    if (url.trim() === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run()
      return
    }
    if (!/^(https?:\/\/|mailto:|\/)/i.test(url.trim())) {
      window.alert('Use an address beginning with https://, mailto: or / for pages on this site.')
      return
    }
    editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run()
  }

  const tools: { label: string; active?: boolean; run: () => void; text: string }[] = [
    { label: 'Heading 2', text: 'H2', active: state?.h2, run: () => editor.chain().focus().toggleHeading({ level: 2 }).run() },
    { label: 'Heading 3', text: 'H3', active: state?.h3, run: () => editor.chain().focus().toggleHeading({ level: 3 }).run() },
    { label: 'Heading 4', text: 'H4', active: state?.h4, run: () => editor.chain().focus().toggleHeading({ level: 4 }).run() },
    { label: 'Bold', text: 'B', active: state?.bold, run: () => editor.chain().focus().toggleBold().run() },
    { label: 'Italic', text: 'I', active: state?.italic, run: () => editor.chain().focus().toggleItalic().run() },
    { label: 'Underline', text: 'U', active: state?.underline, run: () => editor.chain().focus().toggleUnderline().run() },
    { label: 'Bulleted list', text: '•', active: state?.bullet, run: () => editor.chain().focus().toggleBulletList().run() },
    { label: 'Numbered list', text: '1.', active: state?.ordered, run: () => editor.chain().focus().toggleOrderedList().run() },
    { label: 'Quote', text: '❝', active: state?.quote, run: () => editor.chain().focus().toggleBlockquote().run() },
    { label: 'Divider', text: '—', run: () => editor.chain().focus().setHorizontalRule().run() },
    { label: 'Link', text: 'Link', active: state?.link, run: setLink },
  ]

  return (
    <FieldWrap id={id} label={label} hint={hint} error={error} required={required}>
      <div className={`border bg-midnight-950/70 ${borderClass(error)}`}>
        <div role="toolbar" aria-label="Formatting" className="flex flex-wrap items-center gap-1 border-b border-[var(--line)] px-2 py-1.5">
          {!source &&
            tools.map((t) => (
              <button
                key={t.label}
                type="button"
                title={t.label}
                aria-label={t.label}
                aria-pressed={t.active ?? undefined}
                onClick={t.run}
                className={`min-w-7 px-1.5 py-1 text-xs transition-colors ${t.active ? 'bg-champagne-400/20 text-champagne-200' : 'text-ivory-200 hover:text-champagne-200'}`}
              >
                {t.text}
              </button>
            ))}
          <button type="button" onClick={() => setSource((s) => !s)} aria-pressed={source} className="ml-auto px-1.5 py-1 text-[0.65rem] uppercase tracking-[0.14em] text-slate-400 hover:text-champagne-200">
            {source ? 'Visual' : 'HTML'}
          </button>
        </div>
        {source ? (
          <textarea
            aria-label={`${typeof label === 'string' ? label : 'Content'} (HTML source)`}
            value={value}
            onChange={(e) => {
              onChange(e.target.value)
              editor.commands.setContent(e.target.value, { emitUpdate: false })
            }}
            rows={14}
            className={`${controlClass} border-0 font-mono text-xs`}
          />
        ) : (
          <EditorContent editor={editor} />
        )}
      </div>
    </FieldWrap>
  )
}
