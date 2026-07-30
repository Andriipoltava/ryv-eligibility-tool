import { useEffect, useRef } from '@wordpress/element';

/**
 * WordPress's classic (TinyMCE + Quicktags) editor, wired up as a
 * Visual/Text-tabbed replacement for a plain textarea - the same editing
 * experience as a normal post's content field, via wp.editor.initialize().
 *
 * TinyMCE/quicktags mutate the DOM around the textarea directly (wrapping it
 * in toolbar/iframe markup that React never rendered). If the textarea were
 * JSX returned by this component, React's own reconciliation of that JSX
 * would eventually try to remove/update a node whose actual parent had been
 * changed out from under it - "Failed to execute 'removeChild': the node to
 * be removed is not a child of this node" - and, because that crash happens
 * partway through our cleanup (before wp.editor.remove(id) runs), the next
 * mount reinitializes over TinyMCE/quicktags' left-behind internal state,
 * which is what the "Cannot read properties of undefined (reading 'canvas')"
 * quicktags error is. So instead, JSX only ever renders one empty, static
 * container div; the textarea itself is created and destroyed by hand inside
 * the effect, entirely inside that container. React never diffs anything
 * inside it, so nothing it does can conflict with TinyMCE's own DOM surgery.
 *
 * Pass a `key` (usually the node id) from the parent so switching to a
 * different node fully unmounts/remounts this component - the effect only
 * re-runs when `id` itself changes, not on every keystroke, so mid-edit
 * content and cursor position survive re-renders of the surrounding form.
 */
export default function ClassicEditor({ id, value, onChange }) {
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;
  const containerRef = useRef(null);

  useEffect(() => {
    const wp = window.wp;
    const container = containerRef.current;
    if (!wp || !wp.editor || !container) return undefined;

    const textarea = document.createElement('textarea');
    textarea.id = id;
    textarea.rows = 10;
    textarea.value = value || '';
    container.appendChild(textarea);

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
    textarea.addEventListener('input', handleTextareaInput);

    return () => {
      textarea.removeEventListener('input', handleTextareaInput);
      wp.editor.remove(id);
      // wp.editor.remove() unwraps TinyMCE/quicktags back down to the plain
      // textarea it started from - clear the container ourselves so no
      // trace of this instance's DOM or id survives into the next mount.
      container.innerHTML = '';
    };
  }, [id]);

  return <div ref={containerRef} className="ryv-builder-classic-editor" />;
}
