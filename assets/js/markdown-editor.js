(function () {
    const editor = document.querySelector('[data-markdown-editor]');
    const preview = document.getElementById('markdownPreview');
    if (!editor || !preview) return;

    function escapeHtml(value) {
        return value.replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
    }

    function inline(text) {
        const codes = [];
        text = text.replace(/`([^`]+)`/g, (_, code) => {
            const token = `@@MD_CODE_${codes.length}@@`;
            codes.push(`<code>${escapeHtml(code)}</code>`);
            return token;
        });
        text = escapeHtml(text)
            .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+|\/[^\s)]*)\)/gi, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/__([^_]+)__/g, '<strong>$1</strong>')
            .replace(/~~([^~]+)~~/g, '<del>$1</del>')
            .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
        codes.forEach((code, index) => { text = text.replace(`@@MD_CODE_${index}@@`, code); });
        return text;
    }

    function renderMarkdown(source) {
        const lines = source.replace(/\r\n?/g, '\n').trim().split('\n');
        const output = [];
        let paragraph = [], list = null, inCode = false, code = [];
        const flushParagraph = () => {
            if (paragraph.length) output.push(`<p>${inline(paragraph.join('\n'))}</p>`);
            paragraph = [];
        };
        const closeList = () => {
            if (list) output.push(`</${list}>`);
            list = null;
        };
        lines.forEach(line => {
            if (/^```/.test(line)) {
                flushParagraph(); closeList();
                if (inCode) { output.push(`<pre><code>${escapeHtml(code.join('\n'))}</code></pre>`); code = []; }
                inCode = !inCode; return;
            }
            if (inCode) { code.push(line); return; }
            if (!line.trim()) { flushParagraph(); closeList(); return; }
            let match = line.match(/^(#{1,6})\s+(.+)$/);
            if (match) { flushParagraph(); closeList(); const level = match[1].length; output.push(`<h${level}>${inline(match[2])}</h${level}>`); return; }
            match = line.match(/^>\s?(.*)$/);
            if (match) { flushParagraph(); closeList(); output.push(`<blockquote>${inline(match[1])}</blockquote>`); return; }
            match = line.match(/^\s*[-+*]\s+(.+)$/);
            const ordered = line.match(/^\s*\d+[.)]\s+(.+)$/);
            if (match || ordered) {
                flushParagraph(); const type = ordered ? 'ol' : 'ul';
                if (list !== type) { closeList(); output.push(`<${type}>`); list = type; }
                output.push(`<li>${inline((ordered || match)[1])}</li>`); return;
            }
            closeList(); paragraph.push(line);
        });
        if (inCode) output.push(`<pre><code>${escapeHtml(code.join('\n'))}</code></pre>`);
        flushParagraph(); closeList();
        return output.join('\n');
    }

    function updatePreview() { preview.innerHTML = renderMarkdown(editor.value); }

    document.querySelectorAll('.markdown-toolbar button').forEach(button => {
        button.addEventListener('click', () => {
            const start = editor.selectionStart, end = editor.selectionEnd;
            const selected = editor.value.slice(start, end);
            if (button.dataset.mdLine !== undefined) {
                const lineStart = editor.value.lastIndexOf('\n', start - 1) + 1;
                editor.setRangeText(button.dataset.mdLine, lineStart, lineStart, 'end');
            } else {
                const prefix = button.dataset.mdPrefix || '', suffix = button.dataset.mdSuffix || '';
                editor.setRangeText(prefix + selected + suffix, start, end, 'select');
                editor.selectionStart = start + prefix.length;
                editor.selectionEnd = start + prefix.length + selected.length;
            }
            editor.focus(); editor.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
    editor.addEventListener('input', updatePreview);
    updatePreview();
})();
