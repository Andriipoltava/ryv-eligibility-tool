import { useEffect, useRef } from '@wordpress/element';

/**
 * WordPress's classic (TinyMCE + Quicktags) editor, wired up as a
 * Visual/Text-tabbed replacement for a plain textarea - the same editing
 * experience as a normal post's content field, via wp.editor.initialize().
 *
 * wp.editor.initialize() wraps the actual <textarea id={id}> already in the
 * DOM at call time (it doesn't render its own textarea), so the element
 * below has to exist before the effect runs, and its `defaultValue` is only
 * the *initial* content - after that, everything is owned by TinyMCE/the
 * textarea itself.
 *
 * Pass a `key` (usually the node id) from the parent so switching to a
 * different node fully unmounts/remounts this component - the effect only
 * re-runs when `id` itself changes, not on every keystroke, so mid-edit
 * content and cursor position survive re-renders of the surrounding form.
 */
export default function ClassicEditor({ id, value, onChange }) {
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  useEffect(() => {
    const wp = window.wp;
    if (!wp || !wp.editor) return undefined;

    const textarea = document.getElementById(id);

    wp.editor.initialize(id, {
      tinymce: {
        wpautop: true,
        plugins: 'lists,link,paste,wordpress',
        toolbar1: 'bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
        setup(editor) {
          editor.on('change keyup undo redo', () => {
            onChangeRef.current(editor.getContent());
          });
        },
      },
      quicktags: {
        buttons: 'strong,em,link,ul,ol,li,close',
      },
      mediaButtons: false,
    });

    // Quicktags/Text-tab edits happen directly on the textarea and don't go
    // through TinyMCE's 'change' event at all.
    const handleTextareaInput = () => onChangeRef.current(textarea.value);
    if (textarea) textarea.addEventListener('input', handleTextareaInput);

    return () => {
      if (textarea) textarea.removeEventListener('input', handleTextareaInput);
      wp.editor.remove(id);
    };
  }, [id]);

  return <textarea id={id} defaultValue={value} rows={10} className="ryv-builder-classic-editor" />;
}
