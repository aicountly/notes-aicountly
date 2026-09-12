/**
 * The selection toolbar.
 *
 * There is no permanent ribbon in this editor: formatting appears where the
 * words are, when there are words to format, and goes away again. So this is
 * deliberately short — the seven marks people reach for while writing, and
 * nothing that belongs in the `/` menu.
 *
 * "Ask Pulse" is rendered only when the deployment has AI switched on. Not
 * greyed out and not with an explanation: a button that answers 503 is worse
 * than a button that was never there.
 */

import { BubbleMenu } from '@tiptap/react/menus'
import { useEditorState } from '@tiptap/react'
import type { Editor } from '@tiptap/core'
import type { ReactNode } from 'react'

import { Icon } from '../../shared/ui/Icon'
import type { IconName } from '../../shared/ui/Icon'
import './editor.css'

export interface BubbleToolbarProps {
  editor: Editor
  /** `useFeature('ai')` — the only thing that puts Pulse on the toolbar. */
  aiEnabled: boolean
  onAskPulse: () => void
  onEditLink: () => void
}

function ToolbarButton({
  label,
  icon,
  active,
  opensDialog = false,
  onClick,
  children,
  className = '',
}: {
  label: string
  icon: IconName
  active?: boolean
  /**
   * The press opens a dialog rather than toggling a mark. `aria-pressed` would
   * be a lie on one of those — it says "this is on", and the dialog is not.
   */
  opensDialog?: boolean
  onClick: () => void
  children?: ReactNode
  className?: string
}) {
  return (
    <button
      type="button"
      className={`bubble-toolbar__button ${active ? 'bubble-toolbar__button--active' : ''} ${className}`.trim()}
      aria-label={label}
      title={label}
      aria-pressed={opensDialog ? undefined : active}
      aria-haspopup={opensDialog ? 'dialog' : undefined}
      // Taking the press on mousedown keeps the text selection intact; letting
      // the button take focus first would collapse it before the command runs.
      onMouseDown={(event) => event.preventDefault()}
      onClick={onClick}
    >
      <Icon name={icon} size={16} />
      {children}
    </button>
  )
}

export function BubbleToolbar({ editor, aiEnabled, onAskPulse, onEditLink }: BubbleToolbarProps) {
  const marks = useEditorState({
    editor,
    selector: ({ editor: instance }) => ({
      bold: instance.isActive('bold'),
      italic: instance.isActive('italic'),
      underline: instance.isActive('underline'),
      strike: instance.isActive('strike'),
      highlight: instance.isActive('highlight'),
      code: instance.isActive('code'),
      link: instance.isActive('link'),
    }),
  })

  return (
    <BubbleMenu
      editor={editor}
      className="bubble-toolbar"
      shouldShow={({ editor: instance, from, to }) => {
        if (!instance.isEditable || from === to) return false
        // Inside a code block the marks would be meaningless, and over an
        // image the toolbar covers the thing being looked at.
        if (instance.isActive('codeBlock')) return false
        return instance.state.doc.textBetween(from, to, ' ').trim().length > 0
      }}
    >
      <ToolbarButton
        label="Bold"
        icon="bold"
        active={marks?.bold}
        onClick={() => editor.chain().focus().toggleBold().run()}
      />
      <ToolbarButton
        label="Italic"
        icon="italic"
        active={marks?.italic}
        onClick={() => editor.chain().focus().toggleItalic().run()}
      />
      <ToolbarButton
        label="Underline"
        icon="underline"
        active={marks?.underline}
        onClick={() => editor.chain().focus().toggleUnderline().run()}
      />
      <ToolbarButton
        label="Strikethrough"
        icon="strike"
        active={marks?.strike}
        onClick={() => editor.chain().focus().toggleStrike().run()}
      />
      <ToolbarButton
        label="Highlight"
        icon="highlight"
        active={marks?.highlight}
        onClick={() => editor.chain().focus().toggleHighlight().run()}
      />
      <ToolbarButton
        label="Inline code"
        icon="code"
        active={marks?.code}
        onClick={() => editor.chain().focus().toggleCode().run()}
      />

      <span className="bubble-toolbar__divider" aria-hidden />

      <ToolbarButton
        label={marks?.link ? 'Edit link' : 'Add link'}
        icon="link"
        active={marks?.link}
        opensDialog
        onClick={onEditLink}
      />

      {aiEnabled ? (
        <>
          <span className="bubble-toolbar__divider" aria-hidden />
          <ToolbarButton
            label="Ask Pulse about this selection"
            icon="pulse"
            className="bubble-toolbar__button--pulse"
            opensDialog
            onClick={onAskPulse}
          >
            <span>Ask Pulse</span>
          </ToolbarButton>
        </>
      ) : null}
    </BubbleMenu>
  )
}
