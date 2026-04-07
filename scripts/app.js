import { requestApi } from '../shared/scripts/http.js';

const SIDEBAR_REFRESH_MS = 120000;

const state = {
    gems: [],
    gemLookup: new Map(),
    suggestions: [],
    selectedSuggestionIndex: -1,
    activeLeague: 'Loading...',
    sidebarOpen: false,
    sidebarDealsRefreshing: false,
    sidebarTimer: null,
};

const elements = {
    activeLeague: document.getElementById('active-league'),
    clearButton: document.getElementById('clear-search'),
    error: document.getElementById('global-error'),
    gcpPrice: document.getElementById('gcp-price'),
    input: document.getElementById('gem-search'),
    resultsBody: document.getElementById('results-body'),
    resultsMeta: document.getElementById('results-meta'),
    resultsTitle: document.getElementById('results-title'),
    searchButton: document.getElementById('search-button'),
    sidebarClose: document.getElementById('sidebar-close'),
    sidebarCount: document.getElementById('sidebar-count'),
    sidebarDeals: document.getElementById('sidebar-deals'),
    sidebarDrawer: document.getElementById('market-drawer'),
    sidebarOverlay: document.getElementById('sidebar-overlay'),
    sidebarStatus: document.getElementById('sidebar-status'),
    sidebarToggle: document.getElementById('sidebar-toggle'),
    suggestions: document.getElementById('gem-suggestions'),
};

function setError(message) {
    if (!message) {
        elements.error.textContent = '';
        elements.error.hidden = true;
        return;
    }

    elements.error.textContent = message;
    elements.error.hidden = false;
}

function normalizeGemName(value) {
    return state.gemLookup.get(String(value || '').trim().toLowerCase()) || null;
}

function trimNumber(value) {
    if (!Number.isFinite(value)) {
        return 'n/a';
    }

    const rounded = Math.round(value * 100) / 100;
    if (Number.isInteger(rounded)) {
        return String(rounded);
    }

    if (Number.isInteger(rounded * 10)) {
        return rounded.toFixed(1);
    }

    return rounded.toFixed(2);
}

function formatTimestamp(iso) {
    if (!iso) {
        return 'Unknown';
    }

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function getSuggestions(query) {
    const trimmed = query.trim().toLowerCase();
    if (!trimmed) {
        return state.gems.slice(0, 12);
    }

    const prefixMatches = [];
    const containsMatches = [];

    for (const gemName of state.gems) {
        const lowered = gemName.toLowerCase();
        if (lowered.startsWith(trimmed)) {
            prefixMatches.push(gemName);
            continue;
        }

        if (lowered.includes(trimmed)) {
            containsMatches.push(gemName);
        }
    }

    return [...prefixMatches, ...containsMatches].slice(0, 12);
}

function closeSuggestions() {
    state.suggestions = [];
    state.selectedSuggestionIndex = -1;
    elements.input.setAttribute('aria-expanded', 'false');
    elements.suggestions.hidden = true;
    elements.suggestions.replaceChildren();
}

function selectSuggestion(gemName) {
    elements.input.value = gemName;
    closeSuggestions();
    elements.input.focus();
}

function renderSuggestions() {
    elements.suggestions.replaceChildren();

    if (state.suggestions.length === 0) {
        elements.input.setAttribute('aria-expanded', 'false');
        elements.suggestions.hidden = true;
        return;
    }

    const fragment = document.createDocumentFragment();

    state.suggestions.forEach((gemName, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'suggestion-item';
        button.dataset.gemName = gemName;
        button.id = `gem-suggestion-${index}`;
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', index === state.selectedSuggestionIndex ? 'true' : 'false');
        button.textContent = gemName;

        if (index === state.selectedSuggestionIndex) {
            button.classList.add('is-active');
            elements.input.setAttribute('aria-activedescendant', button.id);
        }

        fragment.appendChild(button);
    });

    elements.suggestions.appendChild(fragment);
    elements.suggestions.hidden = false;
    elements.input.setAttribute('aria-expanded', 'true');
}

function syncSuggestions() {
    state.suggestions = getSuggestions(elements.input.value);
    state.selectedSuggestionIndex = -1;
    elements.input.removeAttribute('aria-activedescendant');
    renderSuggestions();
}

function createCell(content, className = '') {
    const cell = document.createElement('td');
    if (className) {
        cell.className = className;
    }

    cell.textContent = content;
    return cell;
}

function renderSidebarDeals(items, emptyMessage) {
    elements.sidebarDeals.replaceChildren();

    if (!Array.isArray(items) || items.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'deal-empty';
        empty.textContent = emptyMessage;
        elements.sidebarDeals.appendChild(empty);
        return;
    }

    const fragment = document.createDocumentFragment();

    items.forEach((item) => {
        const wrapper = document.createElement('article');
        wrapper.className = 'deal-item';

        const topRow = document.createElement('div');
        topRow.className = 'deal-row';

        const titleGroup = document.createElement('div');
        const title = document.createElement('h3');
        title.className = 'deal-name';
        title.textContent = item.gemName || 'Unknown gem';
        titleGroup.appendChild(title);

        if (item.baseType && item.baseType !== item.gemName) {
            const base = document.createElement('p');
            base.className = 'deal-base';
            base.textContent = `Base: ${item.baseType}`;
            titleGroup.appendChild(base);
        }

        const price = document.createElement('strong');
        price.className = 'deal-price';
        price.textContent = item.priceDisplay || 'n/a';

        topRow.append(titleGroup, price);

        const details = document.createElement('div');
        details.className = 'deal-details';

        const label = document.createElement('span');
        label.className = 'deal-label';
        label.textContent = `Level ${item.level ?? '?'} | Quality ${item.quality ?? '?'}`;

        const timestamp = document.createElement('span');
        timestamp.className = 'deal-timestamp';
        timestamp.textContent = `Seen ${formatTimestamp(item.indexedAt)}`;

        details.append(label, timestamp);
        wrapper.append(topRow, details);
        fragment.appendChild(wrapper);
    });

    elements.sidebarDeals.appendChild(fragment);
}

function setSidebarOpen(isOpen) {
    state.sidebarOpen = Boolean(isOpen);
    document.body.classList.toggle('drawer-open', state.sidebarOpen);
    elements.sidebarDrawer.setAttribute('aria-hidden', state.sidebarOpen ? 'false' : 'true');
    elements.sidebarOverlay.setAttribute('aria-hidden', state.sidebarOpen ? 'false' : 'true');
    elements.sidebarToggle.setAttribute('aria-expanded', state.sidebarOpen ? 'true' : 'false');
    elements.sidebarDrawer.inert = !state.sidebarOpen;
}

function renderRows(rows) {
    elements.resultsBody.replaceChildren();

    if (!Array.isArray(rows) || rows.length === 0) {
        const tr = document.createElement('tr');
        tr.className = 'empty-row';

        const td = document.createElement('td');
        td.colSpan = 6;
        td.textContent = 'No matching results were returned.';
        tr.appendChild(td);
        elements.resultsBody.appendChild(tr);
        return;
    }

    const fragment = document.createDocumentFragment();

    rows.forEach((row) => {
        const tr = document.createElement('tr');

        tr.appendChild(createCell(row.gemName || 'n/a'));
        tr.appendChild(createCell(String(row.level ?? 'n/a')));
        tr.appendChild(createCell(String(row.quality ?? 'n/a')));
        tr.appendChild(createCell(row.priceDisplay || 'n/a', 'value-cell'));

        let costClassName = 'value-cell';
        if (typeof row.cost === 'number') {
            costClassName += row.cost > 0 ? ' value-cost' : ' value-neutral';
        }

        tr.appendChild(createCell(row.costDisplay || 'n/a', costClassName));

        let profitClassName = 'value-cell';
        if (typeof row.profit === 'number') {
            if (row.profit < 0) {
                profitClassName += ' value-negative';
            } else if (row.profit > 0) {
                profitClassName += ' value-positive';
            } else {
                profitClassName += ' value-neutral';
            }
        }

        tr.appendChild(createCell(row.profitDisplay || 'n/a', profitClassName));
        fragment.appendChild(tr);
    });

    elements.resultsBody.appendChild(fragment);
}

function setLoading(isLoading) {
    elements.searchButton.disabled = isLoading;
    elements.input.disabled = isLoading;
    elements.clearButton.disabled = isLoading;
    elements.searchButton.textContent = isLoading ? 'Evaluating...' : 'Evaluate Gem';
}

function renderEvaluation(payload) {
    state.activeLeague = payload.league || state.activeLeague;
    elements.activeLeague.textContent = state.activeLeague;
    elements.resultsTitle.textContent = payload.rows?.[0]?.gemName || 'No gem selected';
    elements.gcpPrice.textContent = payload.gcpBundle?.display || 'Unavailable';
    elements.resultsMeta.textContent =
        `League: ${payload.league || 'Unknown'} | Scope: ${payload.searchStatus || 'unknown'} | Updated: ${formatTimestamp(payload.generatedAt)}`;

    renderRows(payload.rows || []);
}

function renderSidebar(payload) {
    state.activeLeague = payload.league || state.activeLeague;
    elements.activeLeague.textContent = state.activeLeague;

    const items = Array.isArray(payload.items) ? payload.items : [];
    const maxPrice = trimNumber(payload.filters?.maxPriceChaos ?? 3);
    const itemLabel = items.length === 1 ? 'match' : 'matches';

    elements.sidebarCount.textContent = `${items.length} ${itemLabel}`;
    elements.sidebarStatus.textContent =
        `Updated ${formatTimestamp(payload.generatedAt)} | Scope: ${payload.searchStatus || 'unknown'}`;

    renderSidebarDeals(
        items,
        `No instant-buyout gems currently match the ${maxPrice} chaos limit.`
    );
}

async function loadSidebarDeals({ showLoading = false } = {}) {
    if (state.sidebarDealsRefreshing) {
        return;
    }

    state.sidebarDealsRefreshing = true;

    if (showLoading) {
        elements.sidebarStatus.textContent = 'Loading sidebar...';
        elements.sidebarCount.textContent = '...';
        renderSidebarDeals([], 'Loading the current market watch...');
    } else {
        elements.sidebarStatus.textContent = 'Refreshing market watch...';
    }

    try {
        const payload = await requestApi('api/sidebar-gems', {}, {
            timeoutMs: 90000,
            retries: 0,
        });

        renderSidebar(payload);
    } catch (error) {
        elements.sidebarStatus.textContent = error.message || 'Sidebar unavailable.';
        if (!elements.sidebarDeals.querySelector('.deal-item')) {
            renderSidebarDeals([], 'Unable to load the current market watch.');
        }
    } finally {
        state.sidebarDealsRefreshing = false;
    }
}

function startSidebarPolling() {
    if (state.sidebarTimer) {
        window.clearInterval(state.sidebarTimer);
    }

    state.sidebarTimer = window.setInterval(() => {
        void loadSidebarDeals();
    }, SIDEBAR_REFRESH_MS);
}

async function loadGemCatalog() {
    try {
        const payload = await requestApi('api/gems');
        state.gems = Array.isArray(payload.gems) ? payload.gems : [];
        state.gemLookup = new Map(state.gems.map((gemName) => [gemName.toLowerCase(), gemName]));
        state.activeLeague = payload.league || 'Unknown';
        elements.activeLeague.textContent = state.activeLeague;
        elements.resultsMeta.textContent =
            `Catalog loaded: ${trimNumber(payload.count || state.gems.length)} gems | Scope: ${payload.searchStatus || 'unknown'}`;
    } catch (error) {
        elements.activeLeague.textContent = 'Unavailable';
        setError(error.message || 'Unable to load the gem catalog.');
    }
}

async function evaluateGem() {
    const canonicalGemName = normalizeGemName(elements.input.value);
    if (!canonicalGemName) {
        setError('Select a valid gem from the official autocomplete suggestions.');
        return;
    }

    setError('');
    closeSuggestions();
    setLoading(true);

    try {
        const payload = await requestApi('api/evaluations', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ gemName: canonicalGemName }),
        }, {
            timeoutMs: 90000,
            retries: 0,
        });

        renderEvaluation(payload);
        elements.input.value = canonicalGemName;
    } catch (error) {
        setError(error.message || 'Unable to evaluate this gem right now.');
    } finally {
        setLoading(false);
    }
}

function moveSuggestion(delta) {
    if (state.suggestions.length === 0) {
        return;
    }

    const nextIndex = state.selectedSuggestionIndex + delta;
    if (nextIndex < 0) {
        state.selectedSuggestionIndex = state.suggestions.length - 1;
    } else if (nextIndex >= state.suggestions.length) {
        state.selectedSuggestionIndex = 0;
    } else {
        state.selectedSuggestionIndex = nextIndex;
    }

    renderSuggestions();
}

function bindEvents() {
    setSidebarOpen(false);

    elements.input.addEventListener('input', () => {
        setError('');
        syncSuggestions();
    });

    elements.input.addEventListener('focus', () => {
        syncSuggestions();
    });

    elements.input.addEventListener('keydown', async (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveSuggestion(1);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveSuggestion(-1);
            return;
        }

        if (event.key === 'Escape') {
            closeSuggestions();
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();

            if (state.selectedSuggestionIndex >= 0 && state.suggestions[state.selectedSuggestionIndex]) {
                selectSuggestion(state.suggestions[state.selectedSuggestionIndex]);
                return;
            }

            await evaluateGem();
        }
    });

    elements.suggestions.addEventListener('click', (event) => {
        const button = event.target.closest('.suggestion-item');
        if (!(button instanceof HTMLElement)) {
            return;
        }

        selectSuggestion(button.dataset.gemName || '');
    });

    elements.searchButton.addEventListener('click', async () => {
        await evaluateGem();
    });

    elements.clearButton.addEventListener('click', () => {
        elements.input.value = '';
        closeSuggestions();
        setError('');
        elements.input.focus();
    });

    elements.sidebarToggle.addEventListener('click', () => {
        setSidebarOpen(!state.sidebarOpen);
    });

    elements.sidebarClose.addEventListener('click', () => {
        setSidebarOpen(false);
    });

    elements.sidebarOverlay.addEventListener('click', () => {
        setSidebarOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state.sidebarOpen) {
            setSidebarOpen(false);
        }
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Node)) {
            return;
        }

        if (!elements.suggestions.contains(target) && target !== elements.input) {
            closeSuggestions();
        }
    });
}

async function bootstrap() {
    setError('');
    void loadSidebarDeals({ showLoading: true });
    startSidebarPolling();
    await loadGemCatalog();
}

bindEvents();
bootstrap();
