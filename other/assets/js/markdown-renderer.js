/**
 * ShibaLingo - Lightweight Markdown & Rich Text Renderer
 * Supports Headings, Code Blocks, Tables, Lists, Quotes, Badges, Links, Bold, Italic
 */

const MarkdownRenderer = {
    render(text) {
        if (!text) return '';

        let html = String(text);

        // 1. Normalize line endings
        html = html.replace(/\r\n/g, '\n');

        // 2. Escape HTML entities (except when we generate our own tags)
        html = html
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        // 3. Fenced Code Blocks: ```lang\ncode\n```
        html = html.replace(/```([a-zA-Z0-9_\-]*)\n([\s\S]*?)```/g, (match, lang, code) => {
            const langLabel = lang ? `<span class="code-lang-tag">${lang}</span>` : '';
            return `<div class="md-code-block">
                <div class="md-code-header">${langLabel}<button class="md-code-copy" onclick="navigator.clipboard.writeText(this.closest('.md-code-block').querySelector('code').innerText); this.innerText='Скопировано!'; setTimeout(()=>this.innerText='Копировать', 2000);">Копировать</button></div>
                <pre><code class="language-${lang || 'plaintext'}">${code.trim()}</code></pre>
            </div>`;
        });

        // 4. Inline Code: `code`
        html = html.replace(/`([^`\n]+)`/g, '<code class="md-inline-code">$1</code>');

        // 5. Tables: | Header 1 | Header 2 |\n|---|---|\n| Cell 1 | Cell 2 |
        html = html.replace(/((?:\|[^\n]+\|\r?\n)+)/g, (tableMatch) => {
            const lines = tableMatch.trim().split('\n').map(l => l.trim()).filter(Boolean);
            if (lines.length < 2) return tableMatch;

            let tableHtml = '<div class="md-table-wrap"><table class="md-table">';
            let isHeader = true;

            lines.forEach((line, index) => {
                // Check separator row like |---|---|
                if (/^\|[\s\-:|]+\|$/.test(line)) {
                    isHeader = false;
                    return;
                }

                const cells = line.split('|').slice(1, -1).map(c => c.trim());
                if (isHeader && index === 0) {
                    tableHtml += '<thead><tr>';
                    cells.forEach(c => tableHtml += `<th>${c}</th>`);
                    tableHtml += '</tr></thead><tbody>';
                } else {
                    tableHtml += '<tr>';
                    cells.forEach(c => tableHtml += `<td>${c}</td>`);
                    tableHtml += '</tr>';
                }
            });

            tableHtml += '</tbody></table></div>';
            return tableHtml;
        });

        // 6. Blockquotes: > quote line
        html = html.replace(/^(?:&gt;|\>)[ ]?([^\n]+)(?:\n(?:&gt;|\>)[ ]?([^\n]+))*/gm, (match) => {
            const quoteText = match.replace(/^(?:&gt;|\>)[ ]?/gm, '').trim();
            return `<blockquote class="md-blockquote">${quoteText.replace(/\n/g, '<br>')}</blockquote>`;
        });

        // 7. Headings: #, ##, ###, ####
        html = html.replace(/^#### (.*?)$/gm, '<h4 class="md-h4">$1</h4>');
        html = html.replace(/^### (.*?)$/gm, '<h3 class="md-h3">$1</h3>');
        html = html.replace(/^## (.*?)$/gm, '<h2 class="md-h2">$1</h2>');
        html = html.replace(/^# (.*?)$/gm, '<h1 class="md-h1">$1</h1>');

        // 8. Horizontal rules: --- or ***
        html = html.replace(/^(?:---|\*\*\*|___)$/gm, '<hr class="md-hr">');

        // 9. Bold & Italic
        html = html.replace(/\*\*\*([^*]+)\*\*\*/g, '<strong><em>$1</em></strong>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__([^_]+)__/g, '<strong>$1</strong>');
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        html = html.replace(/_([^_]+)_/g, '<em>$1</em>');
        html = html.replace(/~~([^~]+)~~/g, '<del>$1</del>');

        // 10. Links: [text](url)
        html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" class="md-link">$1 ↗</a>');

        // 11. Unordered lists: - item, * item
        html = html.replace(/^[\*\-] (.*?)$/gm, '<li class="md-li">$1</li>');
        html = html.replace(/((?:<li class="md-li">.*?<\/li>\s*)+)/g, '<ul class="md-ul">$1</ul>');

        // 12. Ordered lists: 1. item, 2. item
        html = html.replace(/^\d+\. (.*?)$/gm, '<li class="md-oli">$1</li>');
        html = html.replace(/((?:<li class="md-oli">.*?<\/li>\s*)+)/g, '<ol class="md-ol">$1</ol>');

        // 13. Convert remaining line breaks
        // Avoid adding double breaks around block tags
        html = html.replace(/\n(?!(?:<\/?(?:ul|ol|li|h1|h2|h3|h4|blockquote|div|pre|table|thead|tbody|tr|th|td|hr)>))/g, '<br>');

        return html;
    }
};

// Global helper function
function renderMarkdown(text) {
    return MarkdownRenderer.render(text);
}
