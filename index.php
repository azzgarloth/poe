<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PoE Gem Evaluation</title>
    <link rel="stylesheet" href="styles/styles.css">
</head>
<body>
    <button
        class="btn btn-secondary drawer-toggle"
        id="sidebar-toggle"
        type="button"
        aria-controls="market-drawer"
        aria-expanded="false"
    >
        Market Watch
    </button>

    <div class="drawer-overlay" id="sidebar-overlay" aria-hidden="true"></div>

    <aside class="card market-drawer" id="market-drawer" aria-hidden="true">
        <div class="section-heading sidebar-heading drawer-heading">
            <div>
                <p class="section-kicker">Market Watch</p>
                <h2>Cheap 20/20+ Skill Gems</h2>
            </div>
            <div class="drawer-actions">
                <div class="sidebar-badge">
                    <span>Auto Refresh</span>
                    <strong>2 min</strong>
                </div>
                <button class="btn btn-secondary drawer-close" id="sidebar-close" type="button">Close</button>
            </div>
        </div>

        <p class="helper-text sidebar-helper">
            Skill gems only, level 20+, quality 20+, uncorrupted, instant buyout, and max 3 chaos after normalization.
        </p>

        <div class="sidebar-meta-row">
            <span class="sidebar-status" id="sidebar-status">Loading sidebar...</span>
            <strong class="sidebar-count" id="sidebar-count">0 matches</strong>
        </div>

        <div class="deal-list" id="sidebar-deals">
            <div class="deal-empty">Loading the current market watch...</div>
        </div>
    </aside>

    <div class="app-shell">
        <header class="app-header card">
            <div class="hero-copy">
                <p class="eyebrow">Path of Exile 1</p>
                <h1>Gem Evaluation</h1>
                <p class="hero-text">
                    Compare uncorrupted gem listings against the live cost of 20x Gemcutter's Prism
                    using the official Path of Exile trade API.
                </p>
            </div>
            <div class="hero-meta">
                <div class="meta-pill">
                    <span class="meta-label">Current League</span>
                    <strong id="active-league">Loading...</strong>
                </div>
                <div class="meta-pill">
                    <span class="meta-label">Search Scope</span>
                    <strong>Instant buyouts only</strong>
                </div>
            </div>
        </header>

        <div class="alert alert-error" id="global-error" hidden></div>

        <main class="main-grid">
            <section class="card search-card">
                <div class="section-heading">
                    <p class="section-kicker">Autocomplete</p>
                    <h2>Find a gem</h2>
                </div>

                <label class="field-label" for="gem-search">Gem name</label>
                <div class="search-controls">
                    <div class="combobox">
                        <div class="combobox-row">
                            <input
                                id="gem-search"
                                type="text"
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="Start typing a gem name..."
                                aria-autocomplete="list"
                                aria-controls="gem-suggestions"
                                aria-expanded="false"
                            >
                            <button class="btn btn-secondary ghost-btn" id="clear-search" type="button">Clear</button>
                        </div>
                        <div class="suggestions" id="gem-suggestions" role="listbox" hidden></div>
                    </div>

                    <button class="btn btn-primary" id="search-button" type="button">Evaluate Gem</button>
                </div>

                <p class="helper-text">
                    Suggestions include base gems and transfigured versions. The app evaluates four uncorrupted variants:
                    1/0, 1/20, 20/0, and 20/20, and normalizes gem prices to chaos when needed.
                </p>
            </section>

            <section class="card results-card">
                <div class="results-toolbar">
                    <div>
                        <p class="section-kicker">Results</p>
                        <h2 id="results-title">Choose a gem to begin</h2>
                    </div>
                    <div class="summary-chip">
                        <span>20x Gemcutter's Prism</span>
                        <strong id="gcp-price">Waiting for search</strong>
                    </div>
                </div>

                <p class="results-meta" id="results-meta">
                    Official trade API data will appear here after the first search.
                </p>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Gem Name</th>
                                <th scope="col">Level</th>
                                <th scope="col">Quality</th>
                                <th scope="col">Price</th>
                                <th scope="col">Cost</th>
                                <th scope="col">Profit</th>
                            </tr>
                        </thead>
                        <tbody id="results-body">
                            <tr class="empty-row">
                                <td colspan="6">Select a gem and run the evaluation.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script type="module" src="scripts/app.js"></script>
</body>
</html>
