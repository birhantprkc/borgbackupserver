(function() {
    const agentId = window.RESTORE_AGENT_ID;
    if (!agentId) return;

    // DOM refs
    const archiveSelect = document.getElementById('archive-select');
    const searchInput = document.getElementById('restore-search');
    const searchBtn = document.getElementById('restore-search-btn');
    const searchModeBtn = document.getElementById('search-mode-btn');
    const searchModeMenu = document.getElementById('search-mode-menu');
    const browsePanel = document.getElementById('browse-panel');
    const searchPanel = document.getElementById('search-panel');
    const historyPanel = document.getElementById('history-panel');
    const treeRoot = document.getElementById('tree-root');
    const treeLoading = document.getElementById('tree-loading');
    const treeEmpty = document.getElementById('tree-empty');
    const backBtn = document.getElementById('back-to-browse');
    const selectedList = document.getElementById('selected-list');
    const selectedCountEl = document.getElementById('selected-count');
    const noSelection = document.getElementById('no-selection');
    const restoreBtn = document.getElementById('restore-btn');
    const downloadBtn = document.getElementById('download-btn');

    // State
    let selectedPaths = new Set();           // Set of path strings (single-archive mode)
    let selectedVersions = new Map();        // Map<path, archiveId> (cross-archive mode)
    let currentMode = 'browse';              // browse | search-current | search-all
    let searchMode = 'current';              // current | all
    let searchPage = 1;

    // Utilities
    function formatSize(bytes) {
        if (bytes === null || bytes === undefined || bytes === '') return '';
        bytes = Number(bytes);
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0, size = bytes;
        while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
        return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function statusBadge(s) {
        const map = { A: ['success','New'], M: ['warning','Mod'], U: ['secondary','Same'], E: ['danger','Err'] };
        const [color, label] = map[s] || ['secondary', s];
        return '<span class="badge bg-' + color + '" style="font-size:0.65em;">' + label + '</span>';
    }

    function formatDate(d) {
        if (!d) return '';
        const dt = new Date(d);
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
               ' ' + dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    // Path selection helpers
    function isPathSelected(path) {
        if (selectedPaths.has(path)) return true;
        for (const sel of selectedPaths) {
            if (sel.endsWith('/') && path.startsWith(sel)) return true;
        }
        return false;
    }

    function togglePath(path, checked) {
        if (checked) {
            if (path.endsWith('/')) {
                for (const sel of selectedPaths) {
                    if (sel.startsWith(path) && sel !== path) selectedPaths.delete(sel);
                }
            }
            selectedPaths.add(path);
        } else {
            selectedPaths.delete(path);
        }
        updateSelectionUI();
    }

    function toggleVersion(path, archiveId, checked) {
        if (checked) {
            selectedVersions.set(path, archiveId);
        } else {
            selectedVersions.delete(path);
        }
        updateSelectionUI();
    }

    // Selection UI
    function updateSelectionUI() {
        const isCrossArchive = (currentMode === 'search-all' && selectedVersions.size > 0);
        const count = isCrossArchive ? selectedVersions.size : selectedPaths.size;

        selectedCountEl.textContent = count;
        restoreBtn.disabled = count === 0;
        downloadBtn.disabled = count === 0;
        noSelection.style.display = count === 0 ? 'block' : 'none';

        selectedList.innerHTML = '';

        if (isCrossArchive) {
            selectedVersions.forEach((archiveId, path) => {
                const div = document.createElement('div');
                div.className = 'restore-selection-item';
                const isDir = path.endsWith('/');
                div.innerHTML =
                    '<div class="small font-monospace text-truncate"><i class="bi bi-' + (isDir ? 'folder-fill text-warning' : 'file-earmark') + ' me-1"></i>' + esc(path) +
                    '<br><span class="text-muted" style="font-size:0.75em;">Archive #' + archiveId + '</span></div>' +
                    '<button class="btn btn-sm p-0 btn-remove" data-remove-version="' + esc(path) + '"><i class="bi bi-x-lg text-danger"></i></button>';
                selectedList.appendChild(div);
            });
        } else {
            selectedPaths.forEach(path => {
                const div = document.createElement('div');
                div.className = 'restore-selection-item';
                const isDir = path.endsWith('/');
                div.innerHTML =
                    '<div class="small font-monospace text-truncate"><i class="bi bi-' + (isDir ? 'folder-fill text-warning' : 'file-earmark') + ' me-1"></i>' + esc(path) + '</div>' +
                    '<button class="btn btn-sm p-0 btn-remove" data-remove="' + esc(path) + '"><i class="bi bi-x-lg text-danger"></i></button>';
                selectedList.appendChild(div);
            });
        }
    }

    // Remove from selection
    selectedList.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-remove]');
        if (btn) {
            selectedPaths.delete(btn.dataset.remove);
            const cb = treeRoot.querySelector('input[data-path="' + CSS.escape(btn.dataset.remove) + '"]');
            if (cb) cb.checked = false;
            updateSelectionUI();
        }
        const vBtn = e.target.closest('[data-remove-version]');
        if (vBtn) {
            selectedVersions.delete(vBtn.dataset.removeVersion);
            updateSelectionUI();
        }
    });

    // Tree loading
    function loadTreeNode(parentEl, path) {
        const archiveId = archiveSelect.value;
        if (!archiveId) return;

        const spinner = document.createElement('div');
        spinner.className = 'ps-4 py-1 text-muted small';
        spinner.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div> Loading...';
        parentEl.appendChild(spinner);

        fetch('/clients/' + agentId + '/catalog/' + archiveId + '/tree?path=' + encodeURIComponent(path), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                spinner.remove();

                if (data.dirs.length === 0 && data.files.length === 0) {
                    if (path === '/') {
                        browsePanel.querySelector('.restore-panel-body').innerHTML =
                            '<div class="p-3 text-muted text-center">No file catalog available for this archive.</div>';
                    }
                    return;
                }

                data.dirs.forEach(d => {
                    const item = document.createElement('div');
                    item.className = 'tree-item tree-dir';
                    const isChecked = isPathSelected(d.path);

                    item.innerHTML =
                        '<span class="tree-toggle"><i class="bi bi-chevron-right"></i></span>' +
                        '<input type="checkbox" class="tree-cb" data-path="' + esc(d.path) + '" data-type="dir"' + (isChecked ? ' checked' : '') + '>' +
                        '<span class="tree-icon"><i class="bi bi-folder-fill text-warning"></i></span>' +
                        '<span class="tree-label">' + esc(d.name) + '</span>' +
                        '<span class="tree-meta"><span class="tree-count">(' + d.file_count.toLocaleString() + ' files, ' + formatSize(d.total_size) + ')</span></span>';

                    const children = document.createElement('div');
                    children.className = 'tree-children';
                    children.style.paddingLeft = '20px';

                    parentEl.appendChild(item);
                    parentEl.appendChild(children);

                    let loaded = false;

                    item.querySelector('.tree-toggle, .tree-label').addEventListener('click', function(e) {
                        e.stopPropagation();
                        const isOpen = children.classList.contains('open');
                        if (isOpen) {
                            children.classList.remove('open');
                            item.querySelector('.tree-toggle i').className = 'bi bi-chevron-right';
                        } else {
                            children.classList.add('open');
                            item.querySelector('.tree-toggle i').className = 'bi bi-chevron-down';
                            if (!loaded) {
                                loaded = true;
                                loadTreeNode(children, d.path);
                            }
                        }
                    });

                    item.querySelector('.tree-cb').addEventListener('change', function(e) {
                        e.stopPropagation();
                        togglePath(d.path, this.checked);
                        if (this.checked) {
                            children.querySelectorAll('.tree-cb').forEach(cb => cb.checked = true);
                        } else {
                            children.querySelectorAll('.tree-cb').forEach(cb => cb.checked = false);
                        }
                    });
                });

                data.files.forEach(f => {
                    const item = document.createElement('div');
                    item.className = 'tree-item tree-file';
                    const isChecked = isPathSelected(f.file_path);

                    item.innerHTML =
                        '<span class="tree-toggle"></span>' +
                        '<input type="checkbox" class="tree-cb" data-path="' + esc(f.file_path) + '" data-type="file"' + (isChecked ? ' checked' : '') + '>' +
                        '<span class="tree-icon"><i class="bi bi-file-earmark"></i></span>' +
                        '<span class="tree-label">' + esc(f.file_name) + '</span>' +
                        '<span class="tree-meta">' +
                            statusBadge(f.status) +
                            '<span class="tree-size">' + formatSize(f.file_size) + '</span>' +
                            (f.mtime ? '<span class="tree-mtime">' + formatDate(f.mtime) + '</span>' : '') +
                        '</span>';

                    parentEl.appendChild(item);

                    item.querySelector('.tree-cb').addEventListener('change', function(e) {
                        e.stopPropagation();
                        togglePath(f.file_path, this.checked);
                    });
                });
            })
            .catch(() => {
                spinner.remove();
                if (path === '/') {
                    browsePanel.querySelector('.restore-panel-body').innerHTML =
                        '<div class="p-3 text-muted text-center">Failed to load file catalog.</div>';
                }
            });
    }

    // Show/hide panels
    function showBrowse() {
        currentMode = 'browse';
        browsePanel.style.display = '';
        searchPanel.style.display = 'none';
        historyPanel.style.display = 'none';
        backBtn.style.display = 'none';
    }

    function showSearchCurrent() {
        currentMode = 'search-current';
        browsePanel.style.display = 'none';
        searchPanel.style.display = '';
        historyPanel.style.display = 'none';
        backBtn.style.display = '';
    }

    function showHistory() {
        currentMode = 'search-all';
        browsePanel.style.display = 'none';
        searchPanel.style.display = 'none';
        historyPanel.style.display = '';
        backBtn.style.display = '';
    }

    // Search mode switching
    searchModeMenu.addEventListener('click', function(e) {
        const item = e.target.closest('[data-mode]');
        if (item) {
            searchMode = item.dataset.mode;
            searchModeBtn.innerHTML = '<i class="bi bi-funnel"></i> ' + (searchMode === 'all' ? 'All Archives' : 'This Archive');
            // Enable search even without archive selected in "all" mode
            if (searchMode === 'all') {
                searchInput.disabled = false;
                searchBtn.disabled = false;
            } else {
                searchInput.disabled = !archiveSelect.value;
                searchBtn.disabled = !archiveSelect.value;
            }
        }
    });

    // Archive selection
    archiveSelect.addEventListener('change', function() {
        selectedPaths.clear();
        selectedVersions.clear();
        updateSelectionUI();

        if (searchMode !== 'all') {
            searchInput.disabled = !this.value;
            searchBtn.disabled = !this.value;
        }

        showBrowse();

        if (this.value) {
            treeRoot.innerHTML = '';
            loadTreeNode(treeRoot, '/');
        } else {
            treeRoot.innerHTML = '<div class="p-3 text-muted text-center">Select an archive to browse files</div>';
        }
    });

    // Search
    function doSearchCurrent(page) {
        const archiveId = archiveSelect.value;
        const q = searchInput.value.trim();
        if (!archiveId || !q) return;

        searchPage = page || 1;
        showSearchCurrent();

        const body = document.getElementById('search-results-body');
        body.innerHTML = '<div class="p-3 text-center"><div class="spinner-border spinner-border-sm"></div></div>';

        fetch('/clients/' + agentId + '/catalog/' + archiveId + '?page=' + searchPage + '&search=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                document.getElementById('search-count').textContent = data.total.toLocaleString();
                document.getElementById('search-page-info').textContent = 'Page ' + data.page + ' / ' + data.pages;
                document.getElementById('search-prev').disabled = data.page <= 1;
                document.getElementById('search-next').disabled = data.page >= data.pages;

                body.innerHTML = '';
                if (data.files.length === 0) {
                    body.innerHTML = '<div class="p-3 text-muted text-center">No files found</div>';
                    return;
                }
                data.files.forEach(f => {
                    const row = document.createElement('div');
                    row.className = 'tree-item tree-file';
                    const isChecked = isPathSelected(f.file_path);
                    row.innerHTML =
                        '<input type="checkbox" class="tree-cb search-cb" data-path="' + esc(f.file_path) + '"' + (isChecked ? ' checked' : '') + '>' +
                        '<span class="tree-icon"><i class="bi bi-file-earmark"></i></span>' +
                        '<span class="small font-monospace">' + esc(f.file_path) + '</span>' +
                        ' ' + statusBadge(f.status) +
                        '<span class="tree-size">' + formatSize(f.file_size) + '</span>';
                    body.appendChild(row);
                });
            });
    }

    function doSearchAll(page) {
        const q = searchInput.value.trim();
        if (!q) return;

        searchPage = page || 1;
        showHistory();

        const body = document.getElementById('history-results-body');
        body.innerHTML = '<div class="p-3 text-center"><div class="spinner-border spinner-border-sm"></div></div>';

        fetch('/clients/' + agentId + '/catalog/search-all?page=' + searchPage + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                document.getElementById('history-count').textContent = data.total.toLocaleString();
                document.getElementById('history-page-info').textContent = 'Page ' + data.page + ' / ' + data.pages;
                document.getElementById('history-prev').disabled = data.page <= 1;
                document.getElementById('history-next').disabled = data.page >= data.pages;

                body.innerHTML = '';
                if (data.files.length === 0) {
                    body.innerHTML = '<div class="p-3 text-muted text-center">No files found across any archive</div>';
                    return;
                }

                data.files.forEach(file => {
                    const group = document.createElement('div');
                    group.className = 'version-group';

                    const header = document.createElement('div');
                    header.className = 'version-group-header font-monospace';
                    header.innerHTML = '<i class="bi bi-file-earmark me-1"></i>' + esc(file.path);
                    group.appendChild(header);

                    file.versions.forEach(v => {
                        const row = document.createElement('div');
                        row.className = 'version-row';
                        const isSelected = selectedVersions.get(file.path) == v.archive_id;
                        row.innerHTML =
                            '<input type="radio" name="ver_' + esc(file.path) + '" class="form-check-input version-radio" ' +
                            'data-path="' + esc(file.path) + '" data-archive="' + v.archive_id + '"' + (isSelected ? ' checked' : '') + '>' +
                            '<span class="text-muted">' + formatDate(v.archive_date) + '</span>' +
                            '<span class="text-muted small">' + esc(v.repo_name) + '</span>' +
                            ' ' + statusBadge(v.status) +
                            '<span class="tree-size">' + formatSize(v.file_size) + '</span>';
                        group.appendChild(row);
                    });

                    body.appendChild(group);
                });
            });
    }

    function performSearch(page) {
        if (searchMode === 'all') {
            doSearchAll(page);
        } else {
            doSearchCurrent(page);
        }
    }

    searchBtn.addEventListener('click', () => performSearch(1));
    searchInput.addEventListener('keypress', e => { if (e.key === 'Enter') { e.preventDefault(); performSearch(1); } });
    backBtn.addEventListener('click', showBrowse);

    // Delegate: search result checkboxes
    document.getElementById('search-results-body').addEventListener('change', function(e) {
        if (e.target.classList.contains('search-cb')) {
            togglePath(e.target.dataset.path, e.target.checked);
        }
    });

    // Delegate: search pagination
    document.getElementById('search-prev').addEventListener('click', () => doSearchCurrent(searchPage - 1));
    document.getElementById('search-next').addEventListener('click', () => doSearchCurrent(searchPage + 1));

    // Delegate: history result radios
    document.getElementById('history-results-body').addEventListener('change', function(e) {
        if (e.target.classList.contains('version-radio')) {
            toggleVersion(e.target.dataset.path, parseInt(e.target.dataset.archive), e.target.checked);
        }
    });

    // Delegate: history pagination
    document.getElementById('history-prev').addEventListener('click', () => doSearchAll(searchPage - 1));
    document.getElementById('history-next').addEventListener('click', () => doSearchAll(searchPage + 1));

    // Form submission helpers
    function fillFormAndSubmit(formId, archiveFieldId, filesContainerId, destFieldId) {
        const container = document.getElementById(filesContainerId);
        container.innerHTML = '';

        if (currentMode === 'search-all' && selectedVersions.size > 0) {
            // Cross-archive: group by archive_id and submit multiple forms
            const byArchive = new Map();
            selectedVersions.forEach((aid, path) => {
                if (!byArchive.has(aid)) byArchive.set(aid, []);
                byArchive.get(aid).push(path);
            });

            let first = true;
            byArchive.forEach((paths, aid) => {
                if (first) {
                    document.getElementById(archiveFieldId).value = aid;
                    if (destFieldId) {
                        document.getElementById(destFieldId).value = document.getElementById('restore-destination').value;
                    }
                    paths.forEach(p => {
                        const input = document.createElement('input');
                        input.type = 'hidden'; input.name = 'files[]'; input.value = p;
                        container.appendChild(input);
                    });
                    document.getElementById(formId).submit();
                    first = false;
                } else {
                    // Additional archives: create and submit temp forms
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = document.getElementById(formId).action;
                    form.style.display = 'none';
                    form.innerHTML = '<input type="hidden" name="csrf_token" value="' + document.querySelector('[name=csrf_token]').value + '">' +
                        '<input type="hidden" name="archive_id" value="' + aid + '">';
                    if (destFieldId) {
                        form.innerHTML += '<input type="hidden" name="destination" value="' + esc(document.getElementById('restore-destination').value) + '">';
                    }
                    paths.forEach(p => {
                        form.innerHTML += '<input type="hidden" name="files[]" value="' + esc(p) + '">';
                    });
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        } else {
            // Single archive mode
            document.getElementById(archiveFieldId).value = archiveSelect.value;
            if (destFieldId) {
                document.getElementById(destFieldId).value = document.getElementById('restore-destination').value;
            }
            selectedPaths.forEach(path => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = 'files[]'; input.value = path;
                container.appendChild(input);
            });
            document.getElementById(formId).submit();
        }
    }

    restoreBtn.addEventListener('click', function() {
        const count = (currentMode === 'search-all' && selectedVersions.size > 0) ? selectedVersions.size : selectedPaths.size;
        if (count === 0) return;
        if (!confirm('Restore ' + count + ' path(s) to the client? This may overwrite existing files.')) return;
        fillFormAndSubmit('restore-form', 'restore-archive-id', 'restore-files-container', 'restore-dest-field');
    });

    downloadBtn.addEventListener('click', function() {
        const count = (currentMode === 'search-all' && selectedVersions.size > 0) ? selectedVersions.size : selectedPaths.size;
        if (count === 0) return;
        if (!confirm('Download ' + count + ' path(s) as a .tar.gz archive?')) return;
        fillFormAndSubmit('download-form', 'download-archive-id', 'download-files-container', null);
    });

    // Initialize
    treeRoot.innerHTML = '<div class="p-3 text-muted text-center">Select an archive to browse files</div>';
})();
