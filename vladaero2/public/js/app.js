/**
 * VladAero — Main Application JS
 */
(function() {
    'use strict';

    // ─── Theme Toggle ──────────────────────────────────────────
    const ThemeManager = {
        init() {
            const saved = localStorage.getItem('theme') || 'dark';
            this.apply(saved);

            const btn = document.getElementById('themeToggle');
            if (btn) {
                btn.addEventListener('click', () => {
                    const current = document.documentElement.getAttribute('data-theme');
                    const next = current === 'dark' ? 'light' : 'dark';
                    this.apply(next);
                    localStorage.setItem('theme', next);
                    document.cookie = `theme=${next};path=/;max-age=31536000`;
                });
            }
        },
        apply(theme) {
            document.documentElement.setAttribute('data-theme', theme);
        }
    };

    // ─── Sound Manager ─────────────────────────────────────────
    const SoundManager = {
        enabled: true,
        clickSound: null,

        init() {
            this.enabled = localStorage.getItem('sound') !== 'off';

            const btn = document.getElementById('soundToggle');
            if (btn) {
                btn.addEventListener('click', () => {
                    this.enabled = !this.enabled;
                    localStorage.setItem('sound', this.enabled ? 'on' : 'off');
                    btn.style.opacity = this.enabled ? '1' : '.4';
                });
                btn.style.opacity = this.enabled ? '1' : '.4';
            }
        },
        play(type) {
            if (!this.enabled) return;
            // Aviation UI click sound (switch/toggle)
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = type === 'toggle' ? 800 : 1200;
                osc.type = 'sine';
                gain.gain.value = 0.05;
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.05);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.05);
            } catch(e) {}
        }
    };

    // ─── Mobile Menu ───────────────────────────────────────────
    const MobileMenu = {
        init() {
            const sidebar = document.getElementById('mobileSidebar');
            const toggle = document.getElementById('menuToggle');
            const overlay = document.getElementById('sidebarOverlay');
            const close = document.getElementById('sidebarClose');

            const openMenu = () => {
                if (sidebar) sidebar.classList.add('open');
                if (toggle) toggle.classList.add('active');
                document.body.style.overflow = 'hidden';
                SoundManager.play('toggle');
            };

            const closeMenu = () => {
                if (sidebar) sidebar.classList.remove('open');
                if (toggle) toggle.classList.remove('active');
                document.body.style.overflow = '';
            };

            if (toggle && sidebar) {
                toggle.addEventListener('click', () => {
                    if (sidebar.classList.contains('open')) {
                        closeMenu();
                    } else {
                        openMenu();
                    }
                });
            }
            if (overlay) overlay.addEventListener('click', closeMenu);
            if (close) close.addEventListener('click', closeMenu);

            // Close on ESC key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && sidebar && sidebar.classList.contains('open')) {
                    closeMenu();
                }
            });
        }
    };

    // ─── User Dropdown ─────────────────────────────────────────
    const UserMenu = {
        init() {
            const toggle = document.getElementById('userMenuToggle');
            const dropdown = document.getElementById('userDropdown');
            if (!toggle || !dropdown) return;

            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdown.classList.toggle('show');
            });

            document.addEventListener('click', (e) => {
                if (!dropdown.contains(e.target) && !toggle.contains(e.target)) {
                    dropdown.classList.remove('show');
                }
            });
        }
    };

    // ─── Search Modal (Ctrl+K) ─────────────────────────────────
    const Search = {
        init() {
            const modal = document.getElementById('searchModal');
            const input = document.getElementById('searchInput');
            const results = document.getElementById('searchResults');
            const toggleBtn = document.getElementById('searchToggle');

            if (!modal || !input) return;

            const open = () => {
                modal.classList.add('open');
                input.focus();
                SoundManager.play('toggle');
            };
            const close = () => {
                modal.classList.remove('open');
                input.value = '';
                if (results) results.innerHTML = '<div class="search-modal__hint">Введите запрос для поиска по сайту</div>';
            };

            // Ctrl+K
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); open(); }
                if (e.key === 'Escape' && modal.classList.contains('open')) close();
            });

            if (toggleBtn) toggleBtn.addEventListener('click', open);

            modal.querySelector('.search-modal__overlay')?.addEventListener('click', close);

            // Search input
            let debounce;
            input.addEventListener('input', () => {
                clearTimeout(debounce);
                const q = input.value.trim();
                if (q.length < 2) {
                    results.innerHTML = '<div class="search-modal__hint">Введите запрос</div>';
                    return;
                }
                debounce = setTimeout(() => this.doSearch(q, results), 250);
            });
        },

        async doSearch(query, container) {
            try {
                const resp = await fetch('/api/v1/search', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ q: query })
                });
                const data = await resp.json();

                if (!data.results || data.results.length === 0) {
                    container.innerHTML = '<div class="search-modal__hint">Ничего не найдено</div>';
                    return;
                }

                let html = '';
                for (const item of data.results) {
                    const url = item.url || '#';
                    const icon = item.type === 'aircraft' ? '✈️' : item.type === 'airport' ? '🛫' : item.type === 'airline' ? '🏢' : '📄';
                    html += `<a href="${url}" class="search-result">
                        <span class="search-result__icon">${icon}</span>
                        <div><strong>${item.title}</strong><br><small style="color:var(--text-dim)">${item.subtitle || ''}</small></div>
                    </a>`;
                }
                container.innerHTML = html;
            } catch(e) {
                container.innerHTML = '<div class="search-modal__hint">Ошибка поиска</div>';
            }
        }
    };

    // ─── Tabs ──────────────────────────────────────────────────
    const Tabs = {
        init() {
            document.querySelectorAll('.tabs').forEach(tabGroup => {
                const tabs = tabGroup.querySelectorAll('.tab');
                const containerId = tabGroup.dataset.target;
                const container = containerId ? document.getElementById(containerId) : tabGroup.parentElement;
                const contents = container?.querySelectorAll('.tab-content');

                tabs.forEach(tab => {
                    tab.addEventListener('click', () => {
                        tabs.forEach(t => t.classList.remove('active'));
                        tab.classList.add('active');

                        const target = tab.dataset.tab;
                        if (contents) {
                            contents.forEach(c => c.classList.remove('active'));
                            const targetContent = container.querySelector(`[data-content="${target}"]`);
                            if (targetContent) targetContent.classList.add('active');
                        }
                    });
                });
            });
        }
    };

    // ─── Smooth scroll for anchors ─────────────────────────────
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', (e) => {
            const target = document.querySelector(a.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // ─── Photo viewer ──────────────────────────────────────────
    document.querySelectorAll('[data-photo-view]').forEach(el => {
        el.addEventListener('click', () => {
            const src = el.dataset.photoView || el.querySelector('img')?.src;
            const title = el.dataset.photoTitle || '';
            if (src) openPhotoViewer(src, title);
        });
    });

    function openPhotoViewer(src, title) {
        const viewer = document.createElement('div');
        viewer.className = 'photo-viewer open';
        viewer.innerHTML = `
            <button class="photo-viewer__close" onclick="this.parentElement.remove()">✕</button>
            <img class="photo-viewer__img" src="${src}" alt="${title}">
            ${title ? `<div class="photo-viewer__info">${title}</div>` : ''}
        `;
        viewer.addEventListener('click', (e) => {
            if (e.target === viewer) viewer.remove();
        });
        document.body.appendChild(viewer);
    }
    window.openPhotoViewer = openPhotoViewer;

    // ─── Checklist sound ───────────────────────────────────────
    document.querySelectorAll('.checklist-item input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', () => {
            const item = cb.closest('.checklist-item');
            if (item) item.classList.toggle('checked', cb.checked);
            SoundManager.play('toggle');
        });
    });

    // ─── Spoiler / Accordion ───────────────────────────────────
    document.querySelectorAll('[data-spoiler]').forEach(spoiler => {
        const target = spoiler.dataset.spoiler;
        const content = document.getElementById(target);
        if (!content) return;

        spoiler.addEventListener('click', () => {
            const isOpen = content.style.display !== 'none';
            content.style.display = isOpen ? 'none' : 'block';
            spoiler.classList.toggle('active', !isOpen);
            SoundManager.play('click');
        });
    });

    // ─── Like button (photo) ───────────────────────────────────
    document.querySelectorAll('[data-like]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.like;
            try {
                const resp = await fetch(`/photos/${id}/like`, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await resp.json();
                if (data.liked) {
                    btn.classList.add('liked');
                    SoundManager.play('click');
                } else {
                    btn.classList.remove('liked');
                }
                const countEl = btn.querySelector('.like-count');
                if (countEl) countEl.textContent = data.count || '';
            } catch(e) {}
        });
    });

    // ─── Auto-resize textarea ──────────────────────────────────
    document.querySelectorAll('textarea[data-autoresize]').forEach(ta => {
        ta.addEventListener('input', () => {
            ta.style.height = 'auto';
            ta.style.height = ta.scrollHeight + 'px';
        });
    });

    // ─── Copy to clipboard ─────────────────────────────────────
    document.querySelectorAll('[data-copy]').forEach(btn => {
        btn.addEventListener('click', () => {
            const text = btn.dataset.copy || btn.textContent;
            navigator.clipboard.writeText(text).then(() => {
                const orig = btn.textContent;
                btn.textContent = '✓ Скопировано';
                setTimeout(() => btn.textContent = orig, 1500);
            });
        });
    });

    // ─── Intersection Observer for animations ──────────────────
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.animate-in').forEach(el => observer.observe(el));
    }

    // ─── Init all modules ──────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        ThemeManager.init();
        SoundManager.init();
        MobileMenu.init();
        UserMenu.init();
        Search.init();
        Tabs.init();
    });

})();
