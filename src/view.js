import React, { useState, useEffect } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import ReactDOM from 'react-dom';
import DOMPurify from 'dompurify'; // Import DOMPurify

// Icons for open/closed states (you can replace with SVGs or custom icons)
const TriangleIcon = ({ isOpen }) => (
    <span style={{
        display: 'inline-block',
        transform: isOpen ? 'rotate(90deg)' : 'rotate(0deg)',
        transition: 'transform 0.3s',
        marginRight: '8px'
    }}>
        ▶
    </span>
);

const PrivateStudentNotesEditor = () => {
    const [isOpen, setIsOpen] = useState(false);
    const [saveStatus, setSaveStatus] = useState('idle');

    // Restore state from localStorage on component mount
    useEffect(() => {
        const savedState = localStorage.getItem('privateStudentNotesOpen');
        setIsOpen(savedState === 'true');
    }, []);

    // Save state to localStorage whenever it changes
    useEffect(() => {
        localStorage.setItem('privateStudentNotesOpen', isOpen);
    }, [isOpen]);

    // Initialize the TipTap editor with desired extensions
    const editor = useEditor({
        extensions: [StarterKit],
        content: '',
    });

    const toggleOpen = () => {
        setIsOpen(!isOpen);
    };

    // Sanitize content using DOMPurify
    const sanitizeNoteContent = (content) => {
        return DOMPurify.sanitize(content, {
            ALLOWED_TAGS: ['p', 'em', 'strong', 'ul', 'li'],
            ALLOWED_ATTR: [] // Disallow all attributes
        });
    };

    // Fetch the saved note when the component loads
    useEffect(() => {
        fetch('/wp-json/private-student-notes/v1/get-note', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce, // Include nonce for authentication
            },
        })
            .then(response => response.json())
            .then(data => {
                if (data && data.note) {
                    editor?.commands.setContent(sanitizeNoteContent(data.note)); // Load note into the editor
                }
            })
            .catch(error => console.error('Error fetching note:', error));
    }, [editor]);

    // Handle form submission via REST API
    const saveNoteToServer = () => {
        const noteContent = editor?.getHTML() || ''; // Get current editor content as HTML
        setSaveStatus('saving'); // Set status to saving

        fetch('/wp-json/private-student-notes/v1/save-note', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce,
            },
            body: JSON.stringify({ note: sanitizeNoteContent(noteContent) }),
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    setSaveStatus('saved'); // Set status to saved
                    setTimeout(() => setSaveStatus('idle'), 2000);
                    console.log('Note saved successfully!');
                } else {
                    console.error('Error saving note:', data);
                }
            })
            .catch(error => console.error('Error saving note:', error));
    };

    // Function to print the editor's content
    const printEditorContent = () => {
        if (!editor) return;

        const contents = sanitizeNoteContent(editor.getHTML()); // Get the editor content as HTML and sanitize

        const frame = document.createElement('iframe');

        frame.style.position = 'absolute';
        frame.style.top = '-10000px';
        document.body.appendChild(frame);

        const frameDoc = frame.contentWindow || frame.contentDocument.document || frame.contentDocument;
        frameDoc.document.open();
        frameDoc.document.write(`
      <html>
      <head>
        <title>Print Note</title>
        <style>
          @page { size: auto; margin: 30px; }
          body { font-family: Arial, sans-serif; padding: 20px; }
        </style>
      </head>
      <body>${contents}</body>
      </html>
    `);
        frameDoc.document.close();

        setTimeout(() => {
            frame.contentWindow.focus();
            frame.contentWindow.print();
            document.body.removeChild(frame);
        }, 500);
    };

    return (
        <div style={styles.editorContainer}>
            <div
                onClick={toggleOpen}
                style={{
                    cursor: 'pointer',
                    padding: '10px',
                    backgroundColor: '#f5f5f5',
                    display: 'flex',
                    alignItems: 'center'
                }}
            >
                <TriangleIcon isOpen={isOpen} />
                <strong>Private Student Notes</strong>
            </div>

            {isOpen && (
                <div>
                    {/* Toolbar */}
                    <div style={styles.toolbar}>
                        <button style={styles.button} onClick={() => editor?.chain().focus().toggleBold().run()}><strong>B</strong></button>
                        <button style={styles.button} onClick={() => editor?.chain().focus().toggleItalic().run()}><em>I</em></button>
                        <button style={styles.button} onClick={() => editor?.chain().focus().toggleBulletList().run()}>• List</button>
                    </div>

                    {/* Editor */}
                    <EditorContent
                        editor={editor}
                        style={styles.editorContent}
                        className="editor-content"
                    />

                    {/* Save Button */}
                    <div style={styles.toolbar}>
                        <button
                          onClick={saveNoteToServer}
                          disabled={saveStatus === 'saving'}
                          style={{
                            backgroundColor: saveStatus === 'saving' ? '#888' : '#333',
                            border: 'none',
                            borderRadius: '4px',
                            fontWeight: 'bold',
                            minWidth: '100px',
                            color: '#fff',
                            cursor: saveStatus === 'saving' ? 'not-allowed' : 'pointer',
                          }}
                          tabIndex={0}
                        >
                          {saveStatus === 'saving'
                            ? 'Saving...'
                            : saveStatus === 'saved'
                            ? '✓ Saved'
                            : 'Save Note'}
                        </button>
                        <button
                            onClick={printEditorContent}
                            style={styles.button}
                            tabIndex={0}
                        >
                            Print Note
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

const styles = {
    editorContainer: {
        maxWidth: '800px',
        margin: '0 auto',
    },
    toolbar: {
        display: 'flex',
        gap: '10px',
        marginBottom: '10px',
        marginTop: '10px',
    },
    button: {
        backgroundColor: '#333',
        color: '#fff',
        border: 'none',
        padding: '8px 12px',
        cursor: 'pointer',
        borderRadius: '4px',
        fontWeight: 'bold',
        minWidth: '100px'
    },
    editorContent: {
        minHeight: '300px',
        border: '1px solid #ccc',
        padding: '10px',
        borderRadius: '4px',
    },
};

// Render the React component in the placeholder
const renderEditor = () => {
    const editorElement = document.getElementById('private-student-note-editor');
    if (editorElement) {
        ReactDOM.render(<PrivateStudentNotesEditor />, editorElement);
    }
};

window.addEventListener('load', renderEditor);
